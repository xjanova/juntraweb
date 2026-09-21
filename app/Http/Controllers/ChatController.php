<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Services\AiOracle;
use App\Services\Chat\ChatOffers;
use App\Services\Chat\ChatReadingIntent;
use App\Services\Chat\MaeMorUpstream;
use App\Services\Chat\ReadingChatContext;
use App\Services\Wallet\WalletService;
use App\Support\ChatPolicy;
use App\Support\ChatSuggestions;
use App\Support\Markdown;
use App\Support\Pricing;
use App\Support\TarotSpreads;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "แม่หมอจันทรา" AI chat — proxies to Thaiprompt's Fortune Bot so the
 * conversation behaves like the Facebook Messenger / LINE bot, and uses
 * Thaiprompt's API key pool (juntra holds NO AI keys in prod).
 *
 * Access rule (owner, 2026-09-15 — replaces the 2026-05-08 FB/LINE rule):
 *   - Anyone logged in may chat (phone / email / SSO alike) — see ChatPolicy::gate
 *   - Chatting is free (pricing_chat_message_enabled=0); the credit is spent
 *     when a reading starts. A request to be read ("ดูดวงให้หน่อย") gets the
 *     reading packages as cards (ChatOffers) — never a free reading in chat.
 *
 * Guests get the chat shell with a login CTA. If Thaiprompt is unreachable
 * we fall back to the local AiOracle so the page never crashes.
 */
class ChatController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(
        private AiOracle $oracle,
        private WalletService $wallet,
        private MaeMorUpstream $upstream,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $gate = $this->gate($user);

        // ปุ่ม "เข้าสู่ระบบด้วยเบอร์โทร/อีเมล" ในหน้านี้ — ล็อกอินเสร็จให้กลับมาห้องแชท ไม่ใช่แดชบอร์ด
        // (ไม่ทับปลายทางที่หน้าอื่นตั้งไว้ก่อน)
        if (! $user && ! $request->session()->has('url.intended')) {
            $request->session()->put('url.intended', route('chat.index'));
        }

        // Even when the gate denies access, render the shell — the user
        // sees the bot UI with an inline CTA + greeting placeholder. Less
        // jarring than a dead "please log in" page.
        $conversation = $this->conversationFor($request, $user);

        // First-time greeting via upstream so persona matches FB/LINE bot exactly.
        if ($gate['allowed'] && $conversation->messages()->doesntExist()) {
            $start = $this->upstream->start($user);
            $greeting = $start['greeting'] ?? 'สวัสดีค่ะลูก แม่หมอจันทราอยู่ตรงนี้แล้ว · อยากปรึกษาเรื่องอะไรเป็นพิเศษวันนี้คะ?';
            if (!empty($start['session'])) {
                $request->session()->put('thaiprompt_chat_session', $start['session']);
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
        // 💬 (2026-09-21) อ่านจากห้อง (chat_conversations.reading_id) ไม่ใช่ session เบราว์เซอร์อีกต่อไป
        $reading = ReadingChatContext::readingOf($conversation);

        return view('pages.chat.index', [
            'conversation' => $conversation,
            'gate'         => $gate,
            'channel'      => $user?->chatLinkChannel(),
            'cost'         => $cost,
            'balance'      => $user ? $this->wallet->balance($user) : null,
            'dailyLimit'   => $dailyLimit,
            'dailyLeft'    => $dailyLeft ?? 0,
            'readonly'     => false, // live chat room — input is active
            'suggestions'  => $this->suggestionsFor($conversation, $reading),
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

    /**
     * 💬 (2026-09-21) ห้องของไพ่ชุดนี้ — คืน [ห้อง, เพิ่งผูกกับไพ่ไหม] แล้วตั้งให้เป็นห้องสดของ session นี้
     *
     *   1. เคยคุยเรื่องไพ่ชุดนี้แล้ว (กลับมาถามต่อวันหลัง / กดซ้ำ) → ห้องเดิม
     *   2. ห้องสดยังไม่ได้คุยอะไร (มีแค่คำทักทาย) และยังไม่ผูกไพ่ → ผูกกับไพ่ชุดนี้ ไม่ทิ้งห้องเปล่าไว้ในประวัติ
     *   3. ห้องสดกำลังคุยเรื่องอื่นอยู่ → เปิดห้องใหม่ (ห้องเดิมยังอยู่ในประวัติแชท)
     *
     * ล็อกต่อผู้ใช้กันกดเบิ้ล: สองคำขอพร้อมกันต้องได้ห้องเดียว คำทักทายเดียว
     *
     * @return array{0:ChatConversation,1:bool}
     */
    private function conversationForReading(Request $request, $user, Reading $reading): array
    {
        $bind = function () use ($request, $user, $reading): array {
            $room = ChatConversation::where('user_id', $user->id)
                ->where('reading_id', $reading->id)
                ->latest('id')
                ->first();
            if ($room) {
                return [$room, false];
            }

            $live = $this->conversationFor($request, $user);
            if ($live->reading_id === null && ! $live->messages()->where('role', 'user')->exists()) {
                $live->update(['reading_id' => $reading->id, 'title' => ReadingChatContext::title($reading)]);
                $room = $live;
            } else {
                $room = ChatConversation::create([
                    'user_id'       => $user->id,
                    'session_token' => (string) Str::uuid(),
                    'reading_id'    => $reading->id,
                    'title'         => ReadingChatContext::title($reading),
                ]);
            }

            // คำทักทายประกอบในเครื่อง — ไม่ถาม AI ไม่คิดเงิน (เดิมเสียหนึ่งรอบไปกับการ prime)
            $room->messages()->create([
                'role'    => 'assistant',
                'content' => ReadingChatContext::greeting($reading),
            ]);

            return [$room, true];
        };

        try {
            [$room, $fresh] = Cache::lock("chat:reading-room:{$user->id}", 10)->block(5, $bind);
        } catch (LockTimeoutException) {
            // อีกคำขอถือล็อกนานผิดปกติ — ทำต่อเองดีกว่าให้ลูกค้าเห็นหน้า error (อย่างแย่ได้ห้องซ้ำ ไม่เสียข้อมูล)
            [$room, $fresh] = $bind();
        }

        $request->session()->put('chat_token', $room->session_token);

        return [$room, $fresh];
    }

    /** ชุดปุ่มคำถามลัดที่เหมาะกับสถานะของบทสนทนาตอนนี้ */
    private function suggestionsFor(ChatConversation $conversation, ?Reading $reading): array
    {
        if ($reading !== null) {
            return ChatSuggestions::forReading($reading);
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

        $dispatch = $this->dispatchToUpstream($request, $user, $conversation, $data['message']);
        $reply    = $dispatch['reply'];
        $degraded = $dispatch['degraded'] ?? false;
        $kind     = $dispatch['kind'] ?? 'reply';
        $offers   = $kind === 'offer' ? ChatOffers::for((string) ($dispatch['offer_topic'] ?? 'general'), $user) : [];

        // Debit only AFTER a successful reply — fairer to the user when upstream
        // blips. And NEVER charge for a degraded placeholder (no AI key AND
        // upstream unreachable): the user gets the "not ready" note for free.
        // Nor for an invitation to open cards (kind=offer): that is the shop
        // counter, not an answer — the reading itself is what gets paid for.
        // Race-safe because debit() locks the wallet row.
        $debitTx = null;
        if ($cost > 0 && !$degraded && $kind !== 'offer') {
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
                'role'        => 'assistant',
                'content'     => $reply,
                'offer_topic' => $offers !== [] ? ChatReadingIntent::normalizeTopic($dispatch['offer_topic'] ?? null) : null,
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
                'cost'        => $kind === 'offer' ? 0.0 : $cost,
                'degraded'    => $degraded,
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => ChatPolicy::dailyLeft($user),
                // 🎁 (2026-07-28) "โควตาหมด" ไม่ได้แปลว่าคุยต่อไม่ได้เสมอไป
                //    ฝั่ง client เคยเดาเองจาก daily_left <= 0 แล้วปิดช่องพิมพ์
                //    → พอเปิดโหมดคิดเงิน คนที่พร้อมจ่ายจะโดนปิดปาก ต้องให้เซิร์ฟเวอร์บอก
                'blocked'     => ChatPolicy::exhausted($user),
                'next_cost'   => ChatPolicy::costFor($user),
                'awaiting'    => $awaiting,
                'suggestions' => $awaiting || $offers !== [] ? [] : ChatSuggestions::followUp(),
                // 🌙 (2026-09-15) ลูกค้าขอให้ทำนาย → การ์ดแพ็กเกจเปิดไพ่ (หักเครดิตตอนเปิดไพ่จริง)
                'kind'        => $kind,
                'offers'      => $offers,
                'question'    => $offers !== [] ? mb_substr($data['message'], 0, 300) : null,
            ]);
        }
        return redirect()->route('chat.index')->with('status', 'แม่หมอตอบกลับแล้ว');
    }

    /**
     * Enter the live chat grounded on a finished tarot reading.
     *
     * The user has already paid for + opened this spread; now they can ask
     * แม่หมอ specific follow-ups and she answers from the *exact* cards and
     * the interpretation they received (not a blank-slate chat). Every
     * follow-up question rides the normal /chat/send path, so billing + the
     * gate are exactly the existing ones.
     *
     * 💬 (2026-09-21) เดิม "prime" แม่หมอด้วยข้อความแชทลับ ซึ่งถูกตัดที่ 1,000 ตัว (12 เดือนไปถึงแค่เดือน 1-4
     * ไม่มีเนื้อคำพยากรณ์เลย) กินรอบสนทนาหนึ่งรอบ เลื่อนหลุดประวัติหลัง ~5 คำถาม และลิงก์ห้อง↔ไพ่อยู่ใน
     * session เบราว์เซอร์ (หมดอายุ ~2 ชม.) — ตอนนี้ห้องผูกกับไพ่ในฐานข้อมูล (reading_id) และไพ่ทุกใบ +
     * คำพยากรณ์ฉบับเต็มไปเป็น `context` ใน system message ของแม่หมอ (ReadingChatContext)
     * กลับมากดถามต่อวันหลังก็เข้าห้องเดิม บริบทสร้างใหม่จากฐานข้อมูลเสมอ
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

        // Same eligibility as any chat message (ChatPolicy::gate).
        $gate = $this->gate($user);
        if (! $gate['allowed']) {
            // /chat renders the connect CTA — send them there to link up first.
            return redirect()->route('chat.index')->with('status', $gate['reason']);
        }

        // ครบโควตาวันนี้แล้ว — คำถามแรกที่ส่งอัตโนมัติจะโดนเด้ง 429 ทันทีที่หน้าแชท บอกตรงนี้เลยดีกว่า
        if (ChatPolicy::exhausted($user)) {
            return redirect()->route('chat.index')->with('status', ChatPolicy::limitMessage());
        }

        // 💬 (2026-09-21) แม่หมอยังอ่านไม่เสร็จ / อ่านไม่สำเร็จ (คืนเงินแล้ว) = ยังไม่มีคำพยากรณ์ให้คุยต่อ
        //    (หน้าผลซ่อนปุ่มนี้อยู่แล้ว — กันฟอร์มเก่าที่ค้างอยู่ในแท็บ)
        if (! ReadingChatContext::usable($reading)) {
            return redirect()->route('tarot.show', $reading);
        }

        $question = trim((string) $request->input('question', ''));
        if (mb_strlen($question) > 2000) {
            $question = mb_substr($question, 0, 2000);
        }

        $previousToken = $request->session()->get('chat_token');
        [$conversation, $fresh] = $this->conversationForReading($request, $user, $reading);

        // ห้อง Thaiprompt ของห้องนี้: เพิ่งผูกไพ่ / สลับมาจากห้องอื่น / ยังไม่มี → เปิดใหม่พร้อมบริบทเต็ม
        // กดซ้ำ/รีเฟรชในห้องเดิม → ใช้ห้องเดิมต่อ (ประวัติฝั่งแม่หมอไม่หาย) · เปิดห้องไม่ถาม AI จึงไม่เสียรอบ
        $switched = $previousToken !== $conversation->session_token;
        if ($fresh || $switched || ! $request->session()->has('thaiprompt_chat_session')) {
            // ลืมห้องเก่าก่อนเสมอ — เปิดใหม่ไม่ติด ข้อความถัดไปจะเปิดเองพร้อมบริบท ไม่ไปคุยในห้องของเรื่องอื่น
            $request->session()->forget('thaiprompt_chat_session');
            $this->openUpstreamSession($request, $user, ReadingChatContext::for($reading));
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
    private function dispatchToUpstream(Request $request, $user, ChatConversation $conversation, string $message): array
    {
        // 💬 (2026-09-21) ห้องที่ผูกกับไพ่ที่จ่ายแล้ว — อ่านจากฐานข้อมูลทุกครั้ง (เดิมอ่านจาก session เบราว์เซอร์
        //    และมีอายุ 24 ชม.) · บริบท = ไพ่ทุกใบ + คำพยากรณ์ฉบับเต็ม ส่งไปทุกรอบ ห้องฝั่ง Thaiprompt หมดอายุ
        //    หรือ cache ถูกล้างเมื่อไร แม่หมอก็ยังเห็นคำพยากรณ์ครบ
        $reading  = ReadingChatContext::readingOf($conversation);
        $context  = $reading ? ReadingChatContext::for($reading) : null;
        $grounded = $reading !== null;

        // 🌙 (2026-09-15) "คุยฟรีจนกว่าจะเริ่มการทำนาย" — ขอให้ดูดวงตรง ๆ = ยื่นการ์ดเปิดไพ่เลย
        //    ไม่ส่งไปให้ AI ทำนายฟรี · ยกเว้นห้องที่คุยต่อจากไพ่ที่จ่ายแล้ว (ถามเรื่องไพ่ชุดนั้น = คุยได้)
        if (! $grounded && ($topic = ChatReadingIntent::detect($message)) !== null) {
            return ['reply' => ChatOffers::invite($topic), 'degraded' => false, 'kind' => 'offer', 'offer_topic' => $topic];
        }

        // Re-use the upstream session id across messages for context continuity.
        $sessionId = $request->session()->get('thaiprompt_chat_session');
        if (!$sessionId) {
            $sessionId = $this->openUpstreamSession($request, $user, $context);
        }

        $out = $sessionId ? $this->upstream->send($user, $sessionId, $message, $grounded, $context) : null;

        if ($out === null && $sessionId) {
            // Possible stale upstream session (6h cache TTL on Thaiprompt) —
            // forget our cached id, start fresh, retry once. After that, fall back.
            Log::info('แม่หมอ upstream gave no reply — refreshing session and retrying once', [
                'user_id' => $user->id,
            ]);
            $request->session()->forget('thaiprompt_chat_session');
            $newSession = $this->openUpstreamSession($request, $user, $context);
            $out = $newSession ? $this->upstream->send($user, $newSession, $message, $grounded, $context) : null;
        }

        if ($out === null) {
            Log::warning('แม่หมอ upstream still silent after retry — falling back to AiOracle', [
                'user_id' => $user->id,
            ]);
            return $this->degradedFallback($message, $context);
        }

        return ['reply' => $out['reply'], 'degraded' => false, 'kind' => $out['kind'], 'offer_topic' => $out['offer_topic']];
    }

    /**
     * เปิด upstream session ใหม่ — ห้องที่ผูกกับไพ่ได้บริบทเต็มไปด้วยตั้งแต่เปิด
     *
     * upstream หมุน session เองทุก 6 ชม. (TTL) เดิมเมื่อ session หมุนแล้ว
     * เราเปิดใหม่ "เปล่า ๆ" บริบทไพ่จึงหายเงียบ ๆ — ผู้ใช้ยังเห็นหน้าจอเดิม
     * ที่ชวนถามต่อจากไพ่ แต่แม่หมอตอบเหมือนไม่เคยเห็นไพ่ชุดนั้นเลย
     * 💬 (2026-09-21) บริบทไม่ใช่ข้อความ primer ที่ต้องยิงซ้ำ (และเงียบหายได้ถ้ายิงไม่ผ่าน) อีกต่อไป
     * แต่เป็นฟิลด์ `context` ของการเปิดห้อง ซึ่งผู้เรียกสร้างใหม่จากฐานข้อมูล (reading_id)
     */
    private function openUpstreamSession(Request $request, $user, ?string $context = null): ?string
    {
        $start     = $this->upstream->start($user, $context);
        $sessionId = $start['session'] ?? null;
        if (! $sessionId) {
            return null;
        }

        $request->session()->put('thaiprompt_chat_session', $sessionId);

        return $sessionId;
    }

    /**
     * Local AiOracle fallback. Marked "degraded" only when there's no Gemini
     * key either — i.e. the reply is a placeholder, not a real answer — so the
     * caller can skip the charge. When a key IS set the local model gives a
     * genuine reply and we charge normally.
     */
    private function degradedFallback(string $message, ?string $context = null): array
    {
        return [
            'reply'    => $this->fallback($message, $context),
            'degraded' => !$this->oracle->isConfigured(),
        ];
    }

    /** 💬 (2026-09-21) ห้องที่ผูกกับไพ่ — ทางสำรองก็ตอบจากคำพยากรณ์เดิม (เดิมได้แค่ข้อความของลูกค้าอย่างเดียว) */
    private function fallback(string $message, ?string $context = null): string
    {
        return $this->oracle->chat([
            (object) ['role' => 'user', 'content' => $message],
        ], $context);
    }

    /**
     * Decides whether the current user may chat. Returns:
     *   ['allowed' => bool, 'code' => 'guest'|null, 'reason' => str|null]
     *
     * กติกาจริงอยู่ใน ChatPolicy เพื่อให้เว็บกับ API มือถือใช้ชุดเดียวกัน
     */
    private function gate($user): array
    {
        return ChatPolicy::gate($user);
    }
}
