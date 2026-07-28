<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Services\AiOracle;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Wallet\WalletService;
use App\Support\ChatPolicy;
use App\Support\ChatSuggestions;
use App\Support\Markdown;
use App\Support\Pricing;
use App\Support\TarotSpreads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "แม่หมอจันทรา" AI chat — proxies to Thaiprompt's Fortune Bot API so the
 * conversation behaves identically to the Facebook Messenger / LINE bot,
 * and uses Thaiprompt's API key pool (juntra holds NO AI keys in prod).
 *
 * Access rule (per operator request 2026-05-08):
 *   - Must be logged in via Thaiprompt SSO
 *   - Membership must originate from Facebook or LINE (signup_via)
 *   - Must have enough wallet credit for the per-message price
 *
 * If those aren't met we render the chat shell with a CTA pointing the
 * user at the SSO redirect / wallet top-up. If Thaiprompt is unreachable
 * we fall back to the local AiOracle so the page never crashes.
 */
class ChatController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(
        private FortuneBotClient $bot,
        private AiOracle $oracle,
        private WalletService $wallet,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $gate = $this->gate($user);

        // Even when the gate denies access, render the shell — the user
        // sees the bot UI with an inline CTA + greeting placeholder. Less
        // jarring than a dead "please log in" page.
        $conversation = $this->conversationFor($request, $user);

        // First-time greeting via upstream so persona matches FB/LINE bot exactly.
        if ($gate['allowed'] && $conversation->messages()->doesntExist()) {
            $start = $this->bot->start($user);
            $greeting = $start['greeting'] ?? 'สวัสดีค่ะลูก แม่หมอจันทราอยู่ตรงนี้แล้ว · อยากปรึกษาเรื่องอะไรเป็นพิเศษวันนี้คะ?';
            if (!empty($start['session_id'])) {
                $request->session()->put('thaiprompt_chat_session', $start['session_id']);
            }
            ChatMessage::create([
                'chat_conversation_id' => $conversation->id,
                'role'    => 'assistant',
                'content' => $greeting,
            ]);
        }

        // 🆓 ข้อมูลเพดานข้อความฟรีต่อวัน — โชว์ยอดคงเหลือในหน้าแชท
        // 🎁 (2026-07-28) ใช้ costFor() = ราคาที่ "คนนี้" ต้องจ่ายข้อความถัดไป
        //    ยังอยู่ในโควตาฟรี → 0 → หน้าแชทโชว์แถบ "คุยฟรีวันนี้ N/10"
        //    ใช้ครบแล้ว → ราคาจริง → สลับเป็นแถบเครดิต "หักครั้งละ ฿X" เอง
        $cost       = $user ? ChatPolicy::costFor($user) : ChatPolicy::cost();
        $dailyLimit = ChatPolicy::dailyLimit();
        $dailyLeft  = $user ? ChatPolicy::dailyLeft($user) : null;

        $conversation->load('messages');

        // เข้าแชทต่อจากการเปิดไพ่หรือเปล่า — ใช้เลือกชุดปุ่มคำถามลัด
        $grounded = $request->session()->get('chat_primed_reading') !== null;

        return view('pages.chat.index', [
            'conversation' => $conversation,
            'gate'         => $gate,
            'channel'      => $user?->chatLinkChannel(),
            'cost'         => $cost,
            'balance'      => $user ? $this->wallet->balance($user) : null,
            'dailyLimit'   => $dailyLimit,
            'dailyLeft'    => $dailyLeft ?? 0,
            'readonly'     => false, // live chat room — input is active
            'suggestions'  => $this->suggestionsFor($conversation, $grounded),
            'topics'       => ChatSuggestions::topics(),
            // แม่หมอกำลังถามกลับอยู่ไหม — ถ้าใช่ UI จะยุบแถบปุ่มคำถามลัด
            // เพื่อไม่ให้ผู้ใช้กดแล้วบทสนทนาหลุดโฟลว์ที่แม่หมอกำลังเดินอยู่
            'awaiting'     => ChatSuggestions::isAwaitingAnswer(
                optional($conversation->messages->last(fn ($m) => $m->role === 'assistant'))->content
            ),
            // A question carried in from a tarot result page — the view
            // auto-sends it once so the grounded answer appears immediately.
            // pull() = one-shot: a refresh won't re-fire (and re-charge) it.
            'autosend'     => $gate['allowed'] ? $request->session()->pull('chat_autosend') : null,
        ]);
    }

    /**
     * ห้องสนทนาสดของ session นี้
     *
     * เดิม index() หาห้องด้วยคู่ (session_token, user_id) แต่ send() หาด้วย
     * session_token อย่างเดียว → คนที่เปิด /chat ตอนยังไม่ล็อกอินแล้วล็อกอิน
     * กลับมา (เส้นทางปกติของหน้านี้ เพราะปุ่ม CTA อยู่ในหน้านี้เอง) จะมีห้อง
     * สองแถว: แถว guest (user_id NULL) กับแถวของตัวเอง ข้อความถูกเขียนลงแถว
     * guest แต่หน้าจอ render อีกแถว → รีเฟรชแล้วแชทหายเกลี้ยง ประวัติไม่ขึ้น
     * ใน /account/chats และตัวนับโควตารายวันนับไม่เจอ (ยิง AI ฟรีไม่จำกัด)
     *
     * แก้ที่ต้นทาง: ทุกเส้นทางเรียกเมธอดนี้เมธอดเดียว และตอนล็อกอินสำเร็จ
     * ห้อง guest ที่ถือ token เดียวกันจะถูก "ยึด" มาเป็นของเจ้าของทันที
     * ผู้ใช้จึงคุยต่อจากที่ค้างไว้ได้โดยไม่เสียบทสนทนาก่อนล็อกอิน
     */
    private function conversationFor(Request $request, $user): ChatConversation
    {
        $token = $request->session()->get('chat_token');
        if (! $token) {
            $token = (string) Str::uuid();
            $request->session()->put('chat_token', $token);
        }

        if ($user) {
            ChatConversation::where('session_token', $token)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id]);
        }

        return ChatConversation::firstOrCreate(
            ['session_token' => $token, 'user_id' => $user?->id],
            ['title' => 'สนทนากับแม่หมอ']
        );
    }

    /** ชุดปุ่มคำถามลัดที่เหมาะกับสถานะของบทสนทนาตอนนี้ */
    private function suggestionsFor(ChatConversation $conversation, bool $grounded): array
    {
        if ($grounded) {
            return ChatSuggestions::forReading();
        }

        $hasUserMessage = $conversation->relationLoaded('messages')
            ? $conversation->messages->contains(fn ($m) => $m->role === 'user')
            : $conversation->messages()->where('role', 'user')->exists();

        return ChatSuggestions::forState($hasUserMessage ? 'flowing' : 'fresh');
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $user = $request->user();
        $gate = $this->gate($user);

        if (!$gate['allowed']) {
            return $request->wantsJson()
                ? response()->json(['error' => $gate['reason'], 'reason_code' => $gate['code']], 403)
                : redirect()->route('chat.index')->with('status', $gate['reason']);
        }

        // Idempotency — block a double-submit of the same message.
        if ($this->guardCharge($request, 'chat') === false) {
            return $request->wantsJson()
                ? response()->json(['error' => 'ข้อความก่อนหน้ากำลังส่งอยู่ กรุณารอสักครู่', 'reason_code' => 'in_flight'], 409)
                : redirect()->route('chat.index')->with('status', 'ข้อความก่อนหน้ากำลังส่งอยู่ กรุณารอสักครู่');
        }

        // 🎁 (2026-07-28) ราคาต่อคน — 0 ระหว่างที่ยังอยู่ในโควตาฟรีของวันนี้
        //    ต้องอ่าน **ก่อน** บันทึกข้อความของผู้ใช้ ไม่งั้น dailyUsed() จะนับข้อความนี้
        //    รวมไปด้วย → ข้อความที่ 10 (ซึ่งควรฟรี) จะโดนคิดเงิน
        $cost = ChatPolicy::costFor($user);

        // 🆓 (2026-07-24) โหมดคุยฟรี — เพดานข้อความต่อวัน กันต้นทุน AI บานปลาย
        //   ครบเพดานแล้วแม่หมอชวนไปเปิดไพ่แบบเจาะลึกแทน (เตะเบาๆ ไม่ใช่ error แข็งๆ)
        //   ⚠️ exhausted() บล็อกเฉพาะโหมดฟรีล้วน — โหมดคิดเงินให้ผ่านไปหักเครดิตแทน
        if (ChatPolicy::exhausted($user)) {
            $limitMsg = ChatPolicy::limitMessage();

            return $request->wantsJson()
                ? response()->json([
                    'error'       => $limitMsg,
                    'reason_code' => 'daily_limit',
                    'daily_limit' => ChatPolicy::dailyLimit(),
                    'daily_left'  => 0,
                ], 429)
                : redirect()->route('chat.index')->with('status', $limitMsg);
        }

        $balance = $this->wallet->balance($user);
        if ($cost > 0 && bccomp(number_format($balance, 2, '.', ''), number_format($cost, 2, '.', ''), 2) < 0) {
            $msg = sprintf(
                'เครดิตไม่พอสนทนา (ต้องการ %s ต่อข้อความ คงเหลือ %s) — กรุณาเติมเงินเข้าวอลเลต',
                Pricing::format($cost),
                Pricing::format($balance),
            );
            return $request->wantsJson()
                ? response()->json(['error' => $msg, 'reason_code' => 'insufficient_funds'], 402)
                : redirect()->route('wallet.index')->with('status', $msg);
        }

        $conversation = $this->conversationFor($request, $user);

        $userMessage = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role'    => 'user',
            'content' => $data['message'],
        ]);

        $dispatch = $this->dispatchToUpstream($request, $user, $data['message']);
        $reply    = $dispatch['reply'];
        $degraded = $dispatch['degraded'] ?? false;

        // Debit only AFTER a successful reply — fairer to the user when upstream
        // blips. And NEVER charge for a degraded placeholder (no AI key AND
        // upstream unreachable): the user gets the "not ready" note for free.
        // Race-safe because debit() locks the wallet row.
        $debitTx = null;
        if ($cost > 0 && !$degraded) {
            try {
                $debitTx = $this->wallet->debit($user, $cost, 'AI chat message', [
                    'reference_type' => 'chat_message',
                    'reference_id'   => $userMessage->id,
                ]);
            } catch (InsufficientFundsException $e) {
                // Race lost (parallel debit between balance check and now).
                // We've already produced a reply — surface a one-liner in the
                // assistant message so the user understands why no further
                // messages will go through.
                $reply .= "\n\n— *แม่หมอบอก: เครดิตในวอลเลตหมดพอดีตอนตอบครั้งนี้ ครั้งหน้ากรุณาเติมเงินก่อนนะคะ*";
            }
        }

        // We've already debited — if persisting the reply now fails, the user
        // would be charged for a message they never see. Refund on failure.
        try {
            ChatMessage::create([
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
            Log::error('Chat assistant message persist failed after debit', [
                'user_id' => $user->id, 'err' => $e->getMessage(),
            ]);
            $errMsg = 'ระบบขัดข้องชั่วคราว — ' . ($debitTx ? 'เครดิตถูกคืนแล้ว ' : '') . 'กรุณาลองใหม่อีกครั้ง';
            return $request->wantsJson()
                ? response()->json(['error' => $errMsg, 'reason_code' => 'persist_failed'], 500)
                : redirect()->route('chat.index')->with('status', $errMsg);
        }

        if ($request->wantsJson()) {
            // คืน "สถานะ" กลับไปด้วย ไม่ใช่แค่ข้อความ — หน้าแชทต้องใช้ตัดสินว่า
            // จะโชว์ปุ่มคำถามลัดชุดไหน และต้องยุบแถบปุ่มไหมเมื่อแม่หมอถามกลับ
            // (ก่อนหน้านี้ฝั่ง client เดาเอง ตัวเลขโควตาจึงเคลื่อนจากของจริง)
            $awaiting = ChatSuggestions::isAwaitingAnswer($reply);

            return response()->json([
                'reply'       => $reply,
                'reply_html'  => Markdown::safe($reply),
                'balance'     => $this->wallet->balance($user),
                'cost'        => $cost,
                'degraded'    => $degraded,
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => ChatPolicy::dailyLeft($user),
                // 🎁 (2026-07-28) "โควตาหมด" ไม่ได้แปลว่าคุยต่อไม่ได้เสมอไป
                //    ฝั่ง client เคยเดาเองจาก daily_left <= 0 แล้วปิดช่องพิมพ์
                //    → พอเปิดโหมดคิดเงิน คนที่พร้อมจ่ายจะโดนปิดปาก ต้องให้เซิร์ฟเวอร์บอก
                'blocked'     => ChatPolicy::exhausted($user),
                'next_cost'   => ChatPolicy::costFor($user),
                'awaiting'    => $awaiting,
                'suggestions' => $awaiting ? [] : ChatSuggestions::followUp(),
            ]);
        }
        return redirect()->route('chat.index')->with('status', 'แม่หมอตอบกลับแล้ว');
    }

    /**
     * Enter the live chat pre-grounded on a finished tarot reading.
     *
     * The user has already paid for + opened this spread; now they can ask
     * แม่หมอ specific follow-ups and she answers reading the *exact* cards
     * they drew (not a blank-slate chat). We do this by priming a fresh
     * upstream session with a hidden context primer (card × position ×
     * meaning + the interpretation) — that priming reply becomes a grounded
     * greeting. Every follow-up question then rides the normal /chat/send
     * path, so billing + the FB/LINE gate are exactly the existing ones.
     */
    public function fromReading(Request $request, Reading $reading)
    {
        $user = $request->user();

        // Only real tarot readings, and only their owner, can be consulted.
        if (! TarotSpreads::isTarotType($reading->type)) {
            abort(404);
        }
        if (! $user || $reading->user_id !== $user->id) {
            abort(403);
        }

        // Same eligibility as any chat message (login + FB/LINE link + token).
        $gate = $this->gate($user);
        if (! $gate['allowed']) {
            // /chat renders the connect CTA — send them there to link up first.
            return redirect()->route('chat.index')->with('status', $gate['reason']);
        }

        // ครบโควตาวันนี้แล้วอย่าเพิ่ง prime — การ prime ยิง upstream หนึ่งครั้งเต็ม ๆ
        // ถ้าปล่อยผ่านผู้ใช้จะเสียคำถามแรกฟรี ๆ แล้วโดนเด้ง 429 ทันทีที่หน้าแชท
        if (ChatPolicy::exhausted($user)) {
            return redirect()->route('chat.index')->with('status', ChatPolicy::limitMessage());
        }

        $question = trim((string) $request->input('question', ''));
        if (mb_strlen($question) > 2000) {
            $question = mb_substr($question, 0, 2000);
        }

        // Live chat conversation — same session-token model as index().
        $conversation = $this->conversationFor($request, $user);

        // Prime แม่หมอ with this reading's cards — but only ONCE per reading, so
        // a refresh / double-tap can't re-prime (a wasted upstream call) or
        // stack duplicate greetings in the thread.
        if ($request->session()->get('chat_primed_reading') !== $reading->id) {
            $reading->load('tarotCards.card');
            $greeting = $this->primeReadingContext($request, $user, $reading);
            $conversation->messages()->create([
                'role'    => 'assistant',
                'content' => $greeting,
            ]);
            $request->session()->put('chat_primed_reading', $reading->id);
        }

        // Carry the first question into /chat so it auto-sends there (charged
        // by the normal send path). One-shot via session — see index()'s pull().
        if ($question !== '') {
            $request->session()->put('chat_autosend', $question);
        }

        return redirect()->route('chat.index');
    }

    public function show(ChatConversation $conversation)
    {
        $user = auth()->user();
        $isOwner = $user && $conversation->user_id === $user->id;
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        if (!$isOwner && !$isAdmin) {
            abort(403);
        }

        return view('pages.chat.index', [
            'conversation' => $conversation->load('messages'),
            'gate'         => ['allowed' => true, 'reason' => null, 'code' => null],
            'channel'      => $user?->chatLinkChannel(),
            'cost'         => ChatPolicy::cost(),
            'balance'      => $user ? $this->wallet->balance($user) : null,
            // Viewing past history: the send form posts to the LIVE session
            // conversation, so disable input here to avoid appending replies to
            // the wrong thread. The user goes to /chat to continue chatting.
            'readonly'     => true,
            // อ่านย้อนอย่างเดียว — ไม่มีปุ่มคำถามลัดเพราะกดไปก็ส่งไม่ได้
            // (ปุ่มที่กดแล้วไม่เกิดอะไรคือสิ่งที่ต้องเลี่ยงที่สุดในหน้านี้)
            'suggestions'  => [],
            'topics'       => [],
            'awaiting'     => false,
        ]);
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    /**
     * Try Thaiprompt first (the canonical FB/LINE bot pattern, using the
     * upstream API-key pool). If it's unreachable or returns nothing we
     * fall back to the local AiOracle — juntra's chat NEVER breaks, even
     * when upstream is down.
     */
    private function dispatchToUpstream(Request $request, $user, string $message): array
    {
        if (!$this->bot->isAvailable($user)) {
            return $this->degradedFallback($message);
        }

        // Re-use the upstream session id across messages for context continuity.
        $sessionId = $request->session()->get('thaiprompt_chat_session');
        if (!$sessionId) {
            $sessionId = $this->openUpstreamSession($request, $user);
        }

        if (!$sessionId) {
            return $this->degradedFallback($message);
        }

        $resp  = $this->bot->send($user, $sessionId, $message);
        $reply = $resp['reply'] ?? null;

        if (!$reply || trim($reply) === '') {
            // Possible stale upstream session (6h cache TTL on Thaiprompt) —
            // forget our cached id, start fresh, retry once. After that, fall back.
            Log::info('Thaiprompt bot returned empty reply — refreshing session and retrying once', [
                'user_id' => $user->id,
                'session' => $sessionId,
            ]);
            $request->session()->forget('thaiprompt_chat_session');
            $newSession = $this->openUpstreamSession($request, $user);
            if ($newSession) {
                $resp2 = $this->bot->send($user, $newSession, $message);
                $reply = $resp2['reply'] ?? null;
            }
        }

        if (!$reply || trim($reply) === '') {
            Log::warning('Thaiprompt bot still empty after retry — falling back to AiOracle', [
                'user_id' => $user->id,
            ]);
            return $this->degradedFallback($message);
        }

        return ['reply' => $reply, 'degraded' => false];
    }

    /**
     * เปิด upstream session ใหม่ — และถ้าบทสนทนานี้ถูก prime ด้วยไพ่ไว้
     * ให้ป้อนบริบทไพ่ซ้ำก่อนเสมอ
     *
     * upstream หมุน session เองทุก 6 ชม. (TTL) เดิมเมื่อ session หมุนแล้ว
     * เราเปิดใหม่ "เปล่า ๆ" บริบทไพ่จึงหายเงียบ ๆ — ผู้ใช้ยังเห็นหน้าจอเดิม
     * ที่ชวนถามต่อจากไพ่ แต่แม่หมอตอบเหมือนไม่เคยเห็นไพ่ชุดนั้นเลย
     * (อาการนี้ยิ่งเด่นเมื่อผู้ใช้กดปุ่มคำถามลัดที่อ้างอิงไพ่โดยตรง)
     */
    private function openUpstreamSession(Request $request, $user): ?string
    {
        $start     = $this->bot->start($user);
        $sessionId = $start['session_id'] ?? null;
        if (! $sessionId) {
            return null;
        }

        $request->session()->put('thaiprompt_chat_session', $sessionId);

        $primer = $request->session()->get('chat_reading_primer');
        $primedAt = (int) $request->session()->get('chat_reading_primer_at', 0);

        // จำกัดอายุไว้ 24 ชม. — ไม่งั้นบริบทไพ่จะติดอยู่ใน session ตลอดไป
        // แล้วผู้ใช้ที่กลับมาคุยเรื่องอื่นอีกหลายวันจะเจอแม่หมออ้างถึงไพ่ชุดเก่า
        // ที่เขาไม่ได้เปิดวันนี้ (บริบทที่ควรช่วย กลายเป็นบริบทที่หลอน)
        if (is_string($primer) && $primer !== '' && $primedAt > 0 && (time() - $primedAt) < 86400) {
            // ป้อนบริบทไพ่เงียบ ๆ — คำตอบของ primer ไม่ถูกแสดงและไม่คิดเงิน
            $this->bot->send($user, $sessionId, $primer);
        } elseif ($primer !== null) {
            $request->session()->forget(['chat_reading_primer', 'chat_reading_primer_at', 'chat_primed_reading']);
        }

        return $sessionId;
    }

    /**
     * Open a fresh upstream session and prime it with the drawn cards so every
     * follow-up question in this session is read against them. Returns the
     * greeting to show (the AI's priming reply, or a local one if upstream is
     * unreachable). The priming exchange is NOT charged and NOT shown as a user
     * bubble — only the greeting surfaces.
     */
    private function primeReadingContext(Request $request, $user, Reading $reading): string
    {
        $primer = $this->buildReadingPrimer($reading);
        // เก็บไว้ให้ openUpstreamSession ป้อนซ้ำเมื่อ session หมุน (มีอายุ 24 ชม.)
        $request->session()->put('chat_reading_primer', $primer);
        $request->session()->put('chat_reading_primer_at', time());

        if ($this->bot->isAvailable($user)) {
            $start     = $this->bot->start($user);
            $sessionId = $start['session_id'] ?? null;
            if ($sessionId) {
                $request->session()->put('thaiprompt_chat_session', $sessionId);
                $resp  = $this->bot->send($user, $sessionId, $primer);
                $reply = $resp['reply'] ?? null;
                if ($reply && trim($reply) !== '') {
                    return $reply;
                }
            }
        }

        return $this->localReadingGreeting($reading);
    }

    /** Hidden context message that teaches แม่หมอ the exact cards drawn. */
    private function buildReadingPrimer(Reading $reading): string
    {
        $spreadName = TarotSpreads::nameForType($reading->type) ?? 'ไพ่ยิปซี';

        $lines   = [];
        $lines[] = '[บริบทสำหรับแม่หมอ — ลูกคนนี้เพิ่งเปิดไพ่ยิปซีเสร็จ ขอให้จำไพ่ชุดนี้ไว้ตอบคำถามต่อ ๆ ไป]';
        $lines[] = 'รูปแบบการวางไพ่: ' . $spreadName;
        if (! empty($reading->question)) {
            $lines[] = 'คำถามตั้งต้น: ' . $reading->question;
        }
        $lines[] = 'ไพ่ที่เปิดได้ตามตำแหน่ง:';
        foreach ($reading->tarotCards as $rc) {
            $dir     = $rc->reversed ? 'กลับหัว' : 'ตั้งตรง';
            $meaning = $rc->reversed
                ? ($rc->card->reversed_meaning_th ?? '')
                : ($rc->card->upright_meaning_th ?? '');
            $lines[] = sprintf(
                ' %d. %s: %s (%s)%s',
                $rc->position,
                $rc->position_label,
                $rc->card->name_th ?? '',
                $dir,
                $meaning !== '' ? ' — ' . $meaning : '',
            );
        }
        if (! empty($reading->result)) {
            $lines[] = '';
            $lines[] = 'คำพยากรณ์ที่แม่หมอให้ไปแล้ว (ย่อ): ' . mb_substr(strip_tags($reading->result), 0, 700);
        }
        $lines[] = '';
        $lines[] = 'โปรดทักทายลูกสั้น ๆ อย่างอบอุ่น บอกว่าแม่หมอเห็นไพ่ชุดนี้แล้ว และชวนให้ลูกถามเจาะจงว่าอยากรู้เรื่องใดเพิ่มเติมจากไพ่ชุดนี้ — ตอบเป็นภาษาไทยล้วน ไม่ต้องอ่านไพ่ซ้ำทั้งหมด';

        return implode("\n", $lines);
    }

    /** Grounded greeting used when upstream can't be primed (no AI, no charge). */
    private function localReadingGreeting(Reading $reading): string
    {
        $n          = $reading->tarotCards->count();
        $spreadName = TarotSpreads::nameForType($reading->type) ?? 'ไพ่ยิปซี';

        return "แม่หมอเห็นไพ่ทั้ง {$n} ใบจากการเปิด \"{$spreadName}\" ของลูกแล้วนะคะ ✨ "
            . 'อยากให้แม่หมอเจาะลึกเรื่องไหนเป็นพิเศษจากไพ่ชุดนี้คะ? พิมพ์คำถามด้านล่างได้เลยค่ะ';
    }

    /**
     * Local AiOracle fallback. Marked "degraded" only when there's no Gemini
     * key either — i.e. the reply is a placeholder, not a real answer — so the
     * caller can skip the charge. When a key IS set the local model gives a
     * genuine reply and we charge normally.
     */
    private function degradedFallback(string $message): array
    {
        return [
            'reply'    => $this->fallback($message),
            'degraded' => !$this->oracle->isConfigured(),
        ];
    }

    private function fallback(string $message): string
    {
        return $this->oracle->chat([
            (object) ['role' => 'user', 'content' => $message],
        ]);
    }

    /**
     * Decides whether the current user may chat. Returns:
     *   ['allowed' => bool, 'code' => 'guest'|'no_link'|'no_token'|null, 'reason' => str|null]
     *
     * กติกาจริงอยู่ใน ChatPolicy เพื่อให้เว็บกับ API มือถือใช้ชุดเดียวกัน
     */
    private function gate($user): array
    {
        return ChatPolicy::gate($user);
    }
}
