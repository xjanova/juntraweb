<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AiOracle;
use App\Services\FortuneBot\FortuneBotClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "แม่หมอจันทรา" AI chat — proxies to Thaiprompt's Fortune Bot API so the
 * conversation behaves identically to the Facebook Messenger / LINE bot.
 *
 * Access rule (per operator request 2026-05-08):
 *   - Must be logged in via Thaiprompt SSO
 *   - Membership must originate from Facebook or LINE (signup_via)
 *
 * If those aren't met we render the chat shell with a CTA pointing the
 * user at the SSO redirect. If Thaiprompt is unreachable we fall back to
 * the local AiOracle so the page never crashes.
 */
class ChatController extends Controller
{
    public function __construct(private FortuneBotClient $bot, private AiOracle $oracle) {}

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
                'role' => 'assistant',
                'content' => $greeting,
            ]);
        }

        return view('pages.chat.index', [
            'conversation' => $conversation->load('messages'),
            'gate'         => $gate,
            'channel'      => $user?->chatLinkChannel(),
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

        $conversation = ChatConversation::where('session_token', $token)->firstOrFail();

        ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role'    => 'user',
            'content' => $data['message'],
        ]);

        $reply = $this->dispatchToUpstream($request, $user, $data['message']);

        ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role'    => 'assistant',
            'content' => $reply,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['reply' => $reply]);
        }
        return redirect()->route('chat.index')->with('status', 'แม่หมอตอบกลับแล้ว');
    }

    public function show(ChatConversation $conversation)
    {
        return view('pages.chat.index', [
            'conversation' => $conversation->load('messages'),
            'gate'         => ['allowed' => true, 'reason' => null, 'code' => null],
            'channel'      => auth()->user()?->chatLinkChannel(),
        ]);
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    /**
     * Try Thaiprompt first (the canonical FB/LINE bot pattern). If it's
     * unreachable or returns nothing we fall back to the local AiOracle —
     * juntra's chat NEVER breaks, even when upstream is down.
     */
    private function dispatchToUpstream(Request $request, $user, string $message): string
    {
        if (!$this->bot->isAvailable($user)) {
            return $this->fallback($message);
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
            return $this->fallback($message);
        }

        $resp = $this->bot->send($user, $sessionId, $message);
        $reply = $resp['reply'] ?? null;

        if (!$reply || trim($reply) === '') {
            Log::warning('Thaiprompt bot returned empty reply — falling back to AiOracle', [
                'user_id' => $user->id,
                'session' => $sessionId,
            ]);
            return $this->fallback($message);
        }

        return $reply;
    }

    private function fallback(string $message): string
    {
        // Local heuristic via the existing AiOracle wrapper. This is the
        // exact behaviour juntra had before this change — preserved as a
        // safety net.
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
