<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AiOracle;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Wallet\WalletService;
use App\Support\ChatPolicy;
use App\Support\ChatSuggestions;
use App\Support\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mobile Mae Mor chat — list / start / send for the juntra Flutter app.
 *
 * Functionally mirrors the web ChatController but:
 *   - Returns JSON only (never redirects).
 *   - Skips the FB/LINE link gate (mobile users typically authenticate
 *     by email/password). Upstream Thaiprompt routing requires a
 *     `thaiprompt_token`; without one we transparently fall back to
 *     the local AiOracle so the chat never returns a blank reply.
 *   - Stores the upstream session id in cache keyed by conversation id
 *     instead of the web session, so reconnects from the mobile app
 *     pick up the same upstream context.
 *   - Debits AFTER a non-empty reply (same fairness policy as web).
 */
class ChatController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(
        private FortuneBotClient $bot,
        private AiOracle $oracle,
        private WalletService $wallet,
    ) {}

    public function conversations(Request $request): JsonResponse
    {
        $rows = ChatConversation::where('user_id', $request->user()->id)
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ChatConversation $c) => [
                'id'            => $c->id,
                'title'         => $c->title,
                'message_count' => (int) $c->messages_count,
                'updated_at'    => optional($c->updated_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * หมวดคำถามแบบเต็ม — ชุดเดียวกับแผงที่เว็บกางออกในแชท
     * แยก endpoint เพราะเป็นข้อมูลคงที่ แอพดึงครั้งเดียวแล้ว cache เองได้
     * ไม่ต้องแบกมากับทุก response ของการส่งข้อความ
     */
    public function topics(): JsonResponse
    {
        return response()->json(['data' => ChatSuggestions::topics()]);
    }

    public function startConversation(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = (string) Str::uuid();
        $convo = ChatConversation::create([
            'user_id'       => $user->id,
            'session_token' => $token,
            'title'         => 'สนทนากับแม่หมอ',
        ]);

        // Greet via upstream when possible so mobile + web feel identical.
        $greeting = 'สวัสดีค่ะลูก แม่หมอจันทราอยู่ตรงนี้แล้ว · อยากปรึกษาเรื่องอะไรเป็นพิเศษวันนี้คะ?';
        if ($this->bot->isAvailable($user)) {
            $start = $this->bot->start($user);
            if (!empty($start['greeting'])) {
                $greeting = $start['greeting'];
            }
            if (!empty($start['session_id'])) {
                $this->cacheUpstreamSession($convo->id, $start['session_id']);
            }
        }

        ChatMessage::create([
            'chat_conversation_id' => $convo->id,
            'role'    => 'assistant',
            'content' => $greeting,
        ]);

        return response()->json([
            'data' => [
                'conversation' => $this->convoPayload($convo->fresh('messages')),
                'balance'      => (float) $this->wallet->balance($user),
                // 🎁 (2026-07-28) ราคาที่คนนี้ต้องจ่ายข้อความถัดไป (0 = ยังอยู่ในโควตาฟรี)
                'cost'         => ChatPolicy::costFor($user),
                'daily_limit'  => ChatPolicy::dailyLimit(),
                'daily_left'   => ChatPolicy::dailyLeft($user),
                'blocked'      => ChatPolicy::exhausted($user),
                // ปุ่มคำถามลัดชุดเริ่มต้น — ชุดเดียวกับเว็บ (ChatSuggestions)
                'suggestions'  => ChatSuggestions::starter(),
            ],
        ], 201);
    }

    /**
     * เปิดห้องเดิมต่อ — ต้องส่งสถานะครบเท่าตอนเปิดห้องใหม่
     *
     * 🔴 เดิมคืนแค่ `convoPayload()` (id/title/messages) ไม่มี cost / daily_*
     * / blocked / suggestions เลย และแอพเข้าทาง "เปิดห้องล่าสุดต่อ" เป็นค่า
     * เริ่มต้น = ผู้ใช้เดิมแทบทุกคน ผลคือเปิดแชทแล้วไม่มีปุ่มคำถามลัด
     * ไม่มีทางเข้าหมวดคำถาม และ **ไม่เห็นว่าข้อความถัดไปคิดเงินเท่าไร**
     * ต่างจากเว็บที่ขึ้นป้ายราคาให้ก่อนเสมอ
     */
    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);
        $conversation->load('messages');
        $user = $request->user();

        // ห้องที่ผู้ใช้ยังไม่เคยพิมพ์ = ชุดเริ่มต้น · เคยพิมพ์แล้ว = ชุดถามต่อ
        // (ตรรกะเดียวกับเว็บ ChatController)
        $hasUserMessage = $conversation->messages
            ->contains(fn ($m) => $m->role === 'user');

        $lastAssistant = $conversation->messages
            ->last(fn ($m) => $m->role === 'assistant');
        $awaiting = $lastAssistant
            ? ChatSuggestions::isAwaitingAnswer((string) $lastAssistant->content)
            : false;

        return response()->json([
            'data' => array_merge($this->convoPayload($conversation), [
                'balance'     => (float) $this->wallet->balance($user),
                'cost'        => ChatPolicy::costFor($user),
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => ChatPolicy::dailyLeft($user),
                'blocked'     => ChatPolicy::exhausted($user),
                'awaiting'    => $awaiting,
                'suggestions' => $awaiting
                    ? []
                    : ($hasUserMessage ? ChatSuggestions::followUp() : ChatSuggestions::starter()),
            ]),
        ]);
    }

    public function send(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);
        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $user = $request->user();
        // 🎁 (2026-07-28) ราคาต่อคน — 0 ระหว่างที่ยังอยู่ในโควตาฟรีของวันนี้
        //    ต้องอ่านก่อนบันทึกข้อความ ไม่งั้น dailyUsed() นับข้อความนี้รวมด้วย
        $cost = ChatPolicy::costFor($user);

        // 🆓 เพดานคุยฟรีรายวัน — เดิมมีแต่ฝั่งเว็บ ผู้ใช้แอพจึงยิง AI ฟรีได้
        //    ไม่จำกัด (ต้นทุนบานปลาย) กติกาอยู่ใน ChatPolicy ที่เดียวแล้ว
        //    นับรวมทุกช่องทางเพราะเป็นคนเดียวกันและต้นทุนก้อนเดียวกัน
        //
        //    เช็คก่อนจับ idempotency lock — ถ้าเช็คทีหลัง lock (TTL 90 วิ) จะถูก
        //    จับไว้ทั้งที่คำขอถูกปฏิเสธ ผู้ใช้ที่ลองส่งซ้ำด้วยคีย์เดิมภายใน 90 วิ
        //    จะได้ 409 แทนที่จะได้เหตุผลจริงว่าโควตาหมด
        if (ChatPolicy::exhausted($user)) {
            return response()->json([
                'message'     => ChatPolicy::limitMessage(),
                'reason_code' => 'daily_limit',
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => 0,
            ], 429);
        }

        // Idempotency — block a double-send of the same message (Idempotency-Key header).
        // ล็อกถูกปล่อยอัตโนมัติเมื่อจบ request (ดู guardChargeAuto) — ของเดิม
        // ปล่อยให้หมดอายุเอง 90 วิ ผู้ใช้ที่เจอ error แล้วกดลองใหม่จึงโดน 409
        // ค้างทั้งที่ไม่มีอะไรกำลังประมวลผลจริง และเงินถูกคืนไปแล้ว
        if (!$this->guardChargeAuto($request, 'chat')) {
            return response()->json(['message' => 'ข้อความก่อนหน้ากำลังส่งอยู่ กรุณารอสักครู่', 'reason_code' => 'in_flight'], 409);
        }

        $balance = $this->wallet->balance($user);

        if ($cost > 0 && bccomp(number_format($balance, 2, '.', ''), number_format($cost, 2, '.', ''), 2) < 0) {
            return response()->json([
                'message'     => sprintf(
                    'เครดิตไม่พอสนทนา (ต้องการ %s ต่อข้อความ คงเหลือ %s) — กรุณาเติมเงินเข้าวอลเลต',
                    Pricing::format($cost),
                    Pricing::format($balance),
                ),
                'reason_code' => 'insufficient_funds',
                'balance'     => (float) $balance,
                'cost'        => $cost,
            ], 402);
        }

        $userMessage = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role'    => 'user',
            'content' => $data['message'],
        ]);

        $dispatch = $this->dispatchToUpstream($user, $conversation, $data['message']);
        $reply    = $dispatch['reply'];
        $degraded = $dispatch['degraded'] ?? false;

        // Debit only AFTER a successful reply — fairer when upstream blips. And
        // NEVER for a degraded placeholder (no AI key + upstream unreachable):
        // the user gets the "not ready" note for free (parity with web).
        $debitTx = null;
        if ($cost > 0 && !$degraded) {
            try {
                $debitTx = $this->wallet->debit($user, $cost, 'AI chat message', [
                    'reference_type' => 'chat_message',
                    'reference_id'   => $userMessage->id,
                ]);
            } catch (InsufficientFundsException) {
                // Race lost between balance check and debit. Append a one-line
                // notice to the assistant message so the user knows they need
                // to top up before the next message goes through.
                $reply .= "\n\n— *แม่หมอบอก: เครดิตในวอลเลตหมดพอดีตอนตอบครั้งนี้ ครั้งหน้ากรุณาเติมเงินก่อนนะคะ*";
            }
        }

        // Refund if the reply can't be persisted after we've already debited.
        try {
            $assistant = ChatMessage::create([
                'chat_conversation_id' => $conversation->id,
                'role'    => 'assistant',
                'content' => $reply,
            ]);
        } catch (\Throwable $e) {
            if ($debitTx) {
                try {
                    $this->wallet->refund($debitTx, 'บันทึกข้อความตอบกลับไม่สำเร็จ');
                } catch (\Throwable $refundErr) {
                    Log::critical('Chat refund FAILED after persist failure — manual intervention needed', [
                        'tx_id' => $debitTx->id, 'err' => $refundErr->getMessage(),
                    ]);
                }
            }
            Log::error('Mobile chat assistant message persist failed after debit', [
                'user_id' => $user->id, 'err' => $e->getMessage(),
            ]);
            return response()->json([
                'message'     => 'ระบบขัดข้องชั่วคราว — ' . ($debitTx ? 'เครดิตถูกคืนแล้ว ' : '') . 'กรุณาลองใหม่อีกครั้ง',
                'reason_code' => 'persist_failed',
            ], 500);
        }
        $conversation->touch();

        // แม่หมอกำลังถามกลับอยู่ไหม — แอพใช้ตัดสินว่าจะยุบแถบปุ่มคำถามลัด
        // เพื่อไม่ให้ผู้ใช้กดปุ่มแล้วบทสนทนาหลุดโฟลว์ที่แม่หมอกำลังเดินอยู่
        $awaiting = ChatSuggestions::isAwaitingAnswer($reply);

        return response()->json([
            'data' => [
                'message'     => $this->msgPayload($assistant),
                'reply'       => $reply,
                'balance'     => (float) $this->wallet->balance($user),
                'cost'        => $cost,
                'degraded'    => $degraded,
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => ChatPolicy::dailyLeft($user),
                // 🎁 (2026-07-28) โควตาหมด ≠ คุยต่อไม่ได้ — แอพต้องไม่ปิดช่องพิมพ์เอง
                'blocked'     => ChatPolicy::exhausted($user),
                'next_cost'   => ChatPolicy::costFor($user),
                'awaiting'    => $awaiting,
                'suggestions' => $awaiting ? [] : ChatSuggestions::followUp(),
            ],
        ]);
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    private function dispatchToUpstream($user, ChatConversation $conversation, string $message): array
    {
        if (!$this->bot->isAvailable($user)) {
            return $this->degradedFallback($message);
        }

        $sessionId = $this->loadUpstreamSession($conversation->id);
        if (!$sessionId) {
            $start = $this->bot->start($user);
            $sessionId = $start['session_id'] ?? null;
            if ($sessionId) {
                $this->cacheUpstreamSession($conversation->id, $sessionId);
            }
        }
        if (!$sessionId) {
            return $this->degradedFallback($message);
        }

        $resp  = $this->bot->send($user, $sessionId, $message);
        $reply = $resp['reply'] ?? null;

        // Stale upstream session (6h TTL) — refresh once then retry.
        if (!$reply || trim($reply) === '') {
            $this->forgetUpstreamSession($conversation->id);
            $start = $this->bot->start($user);
            $newSession = $start['session_id'] ?? null;
            if ($newSession) {
                $this->cacheUpstreamSession($conversation->id, $newSession);
                $resp2 = $this->bot->send($user, $newSession, $message);
                $reply = $resp2['reply'] ?? null;
            }
        }

        if (!$reply || trim($reply) === '') {
            return $this->degradedFallback($message);
        }

        return ['reply' => $reply, 'degraded' => false];
    }

    /**
     * Local AiOracle fallback. Marked "degraded" only when there's no Gemini
     * key either — i.e. the reply is a placeholder, not a real answer — so the
     * caller can skip the charge (parity with the web ChatController).
     */
    private function degradedFallback(string $message): array
    {
        return [
            'reply'    => $this->oracle->chat([(object) ['role' => 'user', 'content' => $message]]),
            'degraded' => !$this->oracle->isConfigured(),
        ];
    }

    private function authorizeOwner(Request $request, ChatConversation $conversation): void
    {
        $user = $request->user();
        $isOwner = $conversation->user_id === $user->id;
        $isAdmin = method_exists($user, 'isAdmin') && $user->isAdmin();
        if (!$isOwner && !$isAdmin) {
            abort(403, 'Not your conversation');
        }
    }

    private function convoPayload(ChatConversation $convo): array
    {
        return [
            'id'         => $convo->id,
            'title'      => $convo->title,
            'created_at' => optional($convo->created_at)->toIso8601String(),
            'updated_at' => optional($convo->updated_at)->toIso8601String(),
            'messages'   => $convo->messages
                ? $convo->messages->map(fn ($m) => $this->msgPayload($m))->values()
                : [],
        ];
    }

    private function msgPayload(ChatMessage $m): array
    {
        return [
            'id'         => $m->id,
            'role'       => $m->role,
            'content'    => $m->content,
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }

    private function cacheKey(int $convoId): string
    {
        return "juntra:chat:upstream_session:$convoId";
    }
    private function loadUpstreamSession(int $convoId): ?string
    {
        $v = Cache::get($this->cacheKey($convoId));
        return is_string($v) && $v !== '' ? $v : null;
    }
    private function cacheUpstreamSession(int $convoId, string $sid): void
    {
        // 6h matches the upstream session TTL — refresh on miss.
        Cache::put($this->cacheKey($convoId), $sid, now()->addHours(6));
    }
    private function forgetUpstreamSession(int $convoId): void
    {
        Cache::forget($this->cacheKey($convoId));
    }
}
