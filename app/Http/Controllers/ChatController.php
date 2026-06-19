<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AiOracle;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
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
        $token = $request->session()->get('chat_token');
        if (!$token) {
            $token = (string) Str::uuid();
            $request->session()->put('chat_token', $token);
        }

        $conversation = ChatConversation::firstOrCreate(
            ['session_token' => $token, 'user_id' => $user?->id],
            ['title' => 'สนทนากับแม่หมอ']
        );

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

        return view('pages.chat.index', [
            'conversation' => $conversation->load('messages'),
            'gate'         => $gate,
            'channel'      => $user?->chatLinkChannel(),
            'cost'         => Pricing::for('chat_message'),
            'balance'      => $user ? $this->wallet->balance($user) : null,
            'readonly'     => false, // live chat room — input is active
        ]);
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

        $token = $request->session()->get('chat_token');
        abort_unless($token, 403);

        // Idempotency — block a double-submit of the same message.
        if ($this->guardCharge($request, 'chat') === false) {
            return $request->wantsJson()
                ? response()->json(['error' => 'ข้อความก่อนหน้ากำลังส่งอยู่ กรุณารอสักครู่', 'reason_code' => 'in_flight'], 409)
                : redirect()->route('chat.index')->with('status', 'ข้อความก่อนหน้ากำลังส่งอยู่ กรุณารอสักครู่');
        }

        $cost = Pricing::for('chat_message');
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

        $conversation = ChatConversation::where('session_token', $token)->firstOrFail();

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
            return response()->json([
                'reply'   => $reply,
                'balance' => $this->wallet->balance($user),
                'cost'    => $cost,
            ]);
        }
        return redirect()->route('chat.index')->with('status', 'แม่หมอตอบกลับแล้ว');
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
            'cost'         => Pricing::for('chat_message'),
            'balance'      => $user ? $this->wallet->balance($user) : null,
            // Viewing past history: the send form posts to the LIVE session
            // conversation, so disable input here to avoid appending replies to
            // the wrong thread. The user goes to /chat to continue chatting.
            'readonly'     => true,
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
            $start = $this->bot->start($user);
            $sessionId = $start['session_id'] ?? null;
            if ($sessionId) {
                $request->session()->put('thaiprompt_chat_session', $sessionId);
            }
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
            $start = $this->bot->start($user);
            $newSession = $start['session_id'] ?? null;
            if ($newSession) {
                $request->session()->put('thaiprompt_chat_session', $newSession);
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
     */
    private function gate($user): array
    {
        if (!$user) {
            return ['allowed' => false, 'code' => 'guest',
                    'reason' => 'กรุณาเข้าสู่ระบบด้วย Thaiprompt (Facebook หรือ LINE) เพื่อคุยกับแม่หมอ'];
        }

        if (!$user->isLinkedViaFbOrLine()) {
            return ['allowed' => false, 'code' => 'no_link',
                    'reason' => 'บัญชีของคุณยังไม่ได้เชื่อมกับ Facebook หรือ LINE — เข้าสู่ระบบใหม่ผ่าน Thaiprompt เพื่อยืนยันตัวตน'];
        }

        if (empty($user->thaiprompt_token)) {
            return ['allowed' => false, 'code' => 'no_token',
                    'reason' => 'session หมดอายุ — กรุณาเข้าสู่ระบบใหม่'];
        }

        return ['allowed' => true, 'code' => null, 'reason' => null];
    }
}
