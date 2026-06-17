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
                'cost'         => Pricing::for('chat_message'),
            ],
        ], 201);
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);
        return response()->json([
            'data' => $this->convoPayload($conversation->load('messages')),
        ]);
    }

    public function send(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);
        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        // Idempotency — block a double-send of the same message (Idempotency-Key header).
        if ($this->guardCharge($request, 'chat') === false) {
            return response()->json(['message' => 'ข้อความก่อนหน้ากำลังส่งอยู่ กรุณารอสักครู่', 'reason_code' => 'in_flight'], 409);
        }

        $user = $request->user();
        $cost = Pricing::for('chat_message');
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

        $reply = $this->dispatchToUpstream($user, $conversation, $data['message']);

        // Debit only AFTER a successful reply — fairer when upstream blips.
        $debitTx = null;
        if ($cost > 0) {
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

        return response()->json([
            'data' => [
                'message' => $this->msgPayload($assistant),
                'reply'   => $reply,
                'balance' => (float) $this->wallet->balance($user),
                'cost'    => $cost,
            ],
        ]);
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    private function dispatchToUpstream($user, ChatConversation $conversation, string $message): string
    {
        if (!$this->bot->isAvailable($user)) {
            return $this->oracle->chat([(object) ['role' => 'user', 'content' => $message]]);
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
            return $this->oracle->chat([(object) ['role' => 'user', 'content' => $message]]);
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
            return $this->oracle->chat([(object) ['role' => 'user', 'content' => $message]]);
        }

        return $reply;
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
