<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Support\ReadingTheater;
use App\Support\VoicePremium;
use App\Services\AiOracle;
use App\Services\Chat\ChatOffers;
use App\Services\Chat\ChatReadingIntent;
use App\Services\Chat\MaeMorUpstream;
use App\Services\Chat\ReadingChatContext;
use App\Services\Wallet\WalletService;
use App\Support\ChatPolicy;
use App\Support\ChatRooms;
use App\Support\ChatSuggestions;
use App\Support\Markdown;
use App\Support\Pricing;
use App\Support\ReadingQaClock;
use App\Support\ReadingRoomLock;
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
 *
 * 🔮 (2026-11-03) ห้องพยากรณ์ 3 ห้อง (ChatRooms): ลูกค้าเลือกคุยกับน้องบุษบา / แม่หมอจันทรา / คุณไสย
 *   ห้องละ 1 แบบทำนาย · ห้องผูกกับ persona ตั้งแต่สร้าง (chat_conversations.persona)
 *   ห้อง Thaiprompt ของแต่ละห้องเก็บต่อห้องใน cache (เดิมอยู่ใน session เบราว์เซอร์ = ได้ห้องเดียวต่อเบราว์เซอร์)
 */
class ChatController extends Controller
{
    use PreventsDuplicateCharges;

    /**
     * งบเวลาทั้งคำขอส่งข้อความ (วินาที) — Apache ตัดที่ ~60 วิ ถ้าเกินนั้นลูกค้าเห็น error
     * ทั้งที่ PHP ยังทำต่อและบันทึกคำตอบที่ลูกค้าไม่เคยเห็น แล้วลูกค้ากดส่งซ้ำ = คำถามซ้ำ
     */
    private const BUDGET_SEC = 52;

    /** ข้อความที่แสดงในห้อง — ห้องยาวมากไม่ต้องลากทั้งก้อนมาทุกครั้งที่เปิดหน้า */
    private const RENDER_LIMIT = 80;

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

        // 🔮 เลือกห้องก่อน: ?room=… = เข้าห้องนั้น · ?pick=1 หรือยังไม่เคยเลือก = หน้าเลือกผู้พยากรณ์
        $room = $this->chosenRoom($request);

        // 🔒 (2026-11-05) เริ่มทำนายแล้วเปลี่ยนห้องไม่ได้จนจบ/หมดเวลา — รวมหน้าเลือกผู้พยากรณ์ (?pick=1)
        //    ห้องที่ล็อกถูกปิดไปแล้วไม่บังคับ (กันวน redirect กับหน้า "ยังไม่เปิด")
        $lock = ReadingRoomLock::active($request);
        if ($lock !== null && $room !== $lock['room'] && ChatRooms::isOpen($lock['room'])) {
            return redirect()->route('chat.index', ['room' => $lock['room']])
                ->with('status', ReadingRoomLock::message($lock));
        }

        if ($room === null) {
            return view('pages.chat.rooms', [
                'rooms'   => ChatRooms::cards(),
                'current' => $request->session()->get('chat_room'),
                'gate'    => $gate,
                // คำว่า "ฟรี" บนหน้าเลือกห้องต้องจริงตามที่แอดมินตั้ง (ราคาต่อข้อความ / โควตาฟรีรายวัน)
                'chatFree'  => ChatPolicy::isFree() || ChatPolicy::dailyLimit() > 0,
                'chatLede'  => match (true) {
                    ChatPolicy::isFree() && ChatPolicy::dailyLimit() <= 0 => 'คุยฟรีทุกวัน',
                    ChatPolicy::dailyLimit() > 0 => 'คุยฟรีวันละ ' . ChatPolicy::dailyLimit() . ' ข้อความ',
                    default => 'คุยข้อความละ ' . Pricing::format(ChatPolicy::cost()),
                },
            ]);
        }
        if (! ChatRooms::isOpen($room)) {
            return redirect()->route('chat.index', ['pick' => 1])
                ->with('status', ChatRooms::name($room) . ' ยังไม่เปิดให้คุยนะคะ เร็ว ๆ นี้ค่ะ');
        }
        $request->session()->put('chat_room', $room);

        // ⏳ "คุยเรื่องอื่นต่อ" หลังหมดเวลาถามต่อของไพ่ — ทิ้งห้องของไพ่ไว้ในประวัติ กลับห้องคุยทั่วไปของผู้พยากรณ์คนนี้
        if ($request->boolean('fresh')) {
            $this->forgetRoomToken($request, $room);
        }

        // Even when the gate denies access, render the shell — the user
        // sees the bot UI with an inline CTA + greeting placeholder. Less
        // jarring than a dead "please log in" page.
        $conversation = $this->conversationFor($request, $user, $room);

        // 🔮 (2026-11-03) คำทักทายของห้องประกอบในเครื่อง — เดิมยิง Thaiprompt ทุกครั้งที่เปิดหน้า
        //    (รอได้ถึง 14 วิ ถือ worker ของเซิร์ฟเวอร์ที่ใช้ร่วมกันหลายเว็บ แม้คนที่ไม่พิมพ์อะไรเลย)
        //    ห้อง Thaiprompt เปิดเองตอนส่งข้อความแรก (dispatchToUpstream)
        if ($gate['allowed'] && $conversation->messages()->doesntExist()) {
            ChatMessage::create([
                'chat_conversation_id' => $conversation->id,
                'role'    => 'assistant',
                'content' => ChatRooms::greeting($conversation->persona),
            ]);
        }

        // 🆓 ข้อมูลเพดานข้อความฟรีต่อวัน — โชว์ยอดคงเหลือในหน้าแชท
        // 🎁 (2026-07-28) ใช้ costFor() = ราคาที่ "คนนี้" ต้องจ่ายข้อความถัดไป
        //    ยังอยู่ในโควตาฟรี → 0 → หน้าแชทโชว์แถบ "คุยฟรีวันนี้ N/10"
        //    ใช้ครบแล้ว → ราคาจริง → สลับเป็นแถบเครดิต "หักครั้งละ ฿X" เอง
        $cost       = $user ? ChatPolicy::costFor($user) : ChatPolicy::cost();
        $dailyLimit = ChatPolicy::dailyLimit();
        $dailyLeft  = $user ? ChatPolicy::dailyLeft($user) : null;

        $this->loadRecentMessages($conversation);

        // 🔒 กำลังทำนาย / บิลค้าง → ซ่อนทุกปุ่มที่พาไปเปิดไพ่ใบใหม่
        $bill       = $user ? ChatTopupController::pendingBill($user) : null;
        $hideOffers = $lock !== null || $bill !== null;

        // เข้าแชทต่อจากการเปิดไพ่หรือเปล่า — ใช้เลือกชุดปุ่มคำถามลัด
        // 💬 (2026-09-21) อ่านจากห้อง (chat_conversations.reading_id) ไม่ใช่ session เบราว์เซอร์อีกต่อไป
        $reading = ReadingChatContext::readingOf($conversation);
        // ⏳ นาฬิกาถามต่อของห้องที่คุยต่อจากไพ่ (null = ห้องคุยทั่วไป)
        $qa = ReadingQaClock::state($conversation, $reading);
        // ระหว่างเวลาถามต่อของไพ่ชุดนี้ = ยังอยู่ในการทำนาย → ไม่ยื่นไพ่ชุดใหม่ (กด "พอแล้ว" ก่อนได้)
        $hideOffers = $hideOffers || ($qa !== null && ! $qa['over']);

        return view('pages.chat.index', [
            'qa'           => $qa,
            'conversation' => $conversation,
            'room'         => $conversation->persona,
            'rooms'        => ChatRooms::cards(),
            'offerCards'   => $hideOffers ? ['id' => null, 'offers' => []] : $this->offerCardsFor($conversation, $user),
            // 🔮 (2026-11-05) แพ็กเกจของห้องวางบนโต๊ะในห้องพยากรณ์ — กดเปิดไพ่ได้ตลอด ไม่ต้องรอแม่หมอยื่นการ์ด
            //    ยกเว้นตอนกำลังทำนายอยู่ หรือมีบิลเติมเงินค้าง (โชว์บิล/การทำนายที่ค้างแทน)
            'tableOffers'  => $gate['allowed'] && ! $hideOffers ? array_slice(ChatOffers::forRoom((string) $conversation->persona, 'general', $user), 0, 3) : [],
            'roomLock'     => $lock !== null ? ReadingRoomLock::view($lock) : null,
            'pendingBill'  => $bill !== null ? ChatTopupController::billView($bill) : null,
            'aiSuggestions' => $hideOffers ? ChatSuggestions::withoutReadingStarters((array) Cache::get(self::askKey($conversation->id), []))
                                           : (array) Cache::get(self::askKey($conversation->id), []),
            'gate'         => $gate,
            'channel'      => $user?->chatLinkChannel(),
            'cost'         => $cost,
            'balance'      => $user ? $this->wallet->balance($user) : null,
            'dailyLimit'   => $dailyLimit,
            'dailyLeft'    => $dailyLeft ?? 0,
            'readonly'     => false, // live chat room — input is active
            'suggestions'  => $this->staticChips($request, $this->suggestionsFor($conversation, $reading)),
            // คุยต่อจากผลทำนาย → เปิดเสียงแล้วแม่หมอชวนถามต่อ แทนคำทักทายทั่วไป
            'fromReading'  => $reading !== null,
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
    private function conversationFor(Request $request, $user, string $room = ChatRooms::DEFAULT): ChatConversation
    {
        $room  = ChatRooms::normalize($room);
        $token = $this->roomToken($request, $room);

        if (! $token && $user) {
            // 🔮 ลูกค้าที่ล็อกอินกลับมาจากเบราว์เซอร์/เครื่องใหม่ — คุยต่อในห้องเดิมของผู้พยากรณ์คนนี้
            //    (เดิมได้ห้องใหม่เปล่า ๆ ทุก session ประวัติที่เพิ่งคุยหายไปจากหน้าจอ)
            $token = ChatConversation::where('user_id', $user->id)
                ->where('persona', $room)
                ->whereNull('reading_id')
                ->latest('updated_at')
                ->value('session_token');
        }
        if (! $token) {
            $token = (string) Str::uuid();
        }
        $this->rememberRoomToken($request, $room, $token);

        if ($user) {
            ChatConversation::where('session_token', $token)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id]);
        }

        return ChatConversation::firstOrCreate(
            ['session_token' => $token, 'user_id' => $user?->id],
            ['title' => 'สนทนากับ' . ChatRooms::name($room), 'persona' => $room]
        );
    }

    /**
     * ห้องที่ลูกค้าเลือก — null = ให้แสดงหน้าเลือกผู้พยากรณ์
     *
     * ลิงก์เก่า /chat (ปุ่มทั่วเว็บ, ลิงก์จากบอท) ของคนที่เคยคุยอยู่แล้ว → ห้องเดิม ไม่ต้องเลือกใหม่ทุกครั้ง
     */
    private function chosenRoom(Request $request): ?string
    {
        if ($request->boolean('pick')) {
            return null;
        }
        $asked = $request->query('room');
        if (is_string($asked) && ChatRooms::get($asked) !== null) {
            return $asked;
        }
        // ห้องล่าสุดที่ปิดไปแล้ว (เจ้าของปิดแพ็กเกจ/ปิด flag) ไม่พาไปหน้า "เร็ว ๆ นี้" — ให้เลือกใหม่/เข้าห้องที่เปิดอยู่แทน
        $last = $request->session()->get('chat_room');
        if (is_string($last) && ChatRooms::isOpen($last)) {
            return $last;
        }

        // เคยคุยห้องแม่หมอไว้แล้ว (ก่อนมีห้อง) หรือมีคำถามที่ติดมาจากหน้าผลไพ่ — พากลับห้องเดิม
        if ($request->session()->has('chat_token') || $request->session()->has('chat_autosend')) {
            return ChatRooms::DEFAULT;
        }

        // เปิดอยู่ห้องเดียว (ยังไม่เปิด CHAT_PERSONA_UPSTREAM) — ไม่มีอะไรให้เลือก เข้าห้องแม่หมอเลยเหมือนเดิม
        $open = array_values(array_filter(ChatRooms::keys(), [ChatRooms::class, 'isOpen']));

        return count($open) > 1 ? null : ($open[0] ?? ChatRooms::DEFAULT);
    }

    /** token ห้องสดของผู้พยากรณ์คนนี้ใน session — ห้องแม่หมออ่านคีย์เดิม (chat_token) ด้วย */
    private function roomToken(Request $request, string $room): ?string
    {
        $tokens = (array) $request->session()->get('chat_tokens', []);
        $token  = $tokens[$room] ?? ($room === ChatRooms::DEFAULT ? $request->session()->get('chat_token') : null);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * ✨ (2026-11-05) พรีเมียมเสียง: คำตอบในห้องถามต่อ → ไฟล์เสียงของห้อง (null = ตอบเป็นตัวอักษรอย่างเดียว)
     *
     * ตัดอีโมจิ/มาร์กดาวน์ก่อนพูด · ยาวเกินตัดที่ช่องว่างสุดท้าย · เกินเพดานต่อคำทำนาย = ไม่พูด
     * รอเสียงไม่เกิน ~12 วิ — ช้ากว่านั้นลูกค้าได้ข้อความไปก่อน (ไม่ค้างหน้า)
     */
    private function speakReply(Reading $reading, ChatConversation $conversation, string $reply): ?string
    {
        $qa = ReadingQaClock::state($conversation, $reading);
        if ($qa === null || $qa['over'] || ! VoicePremium::active($reading)) {
            return null;
        }
        $text = ReadingTheater::plain($reply);
        $text = (string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text);
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));
        $max = (int) config('reading_voice.reply_max_chars', 600);
        if (mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max);
            $space = max((int) mb_strrpos($cut, ' '), (int) mb_strrpos($cut, "\n"));
            $text = trim($space > $max / 2 ? mb_substr($cut, 0, $space) : $cut);
        }
        if ($text === '' || ! VoicePremium::canSpeak($reading, mb_strlen($text))) {
            return null;
        }

        $r = app(JuntraServerClient::class)->voiceSpeak((string) $conversation->persona, $text, 'chat', (string) $reading->user_id, 12);
        if ($r['status'] !== 'completed') {
            return null;
        }
        VoicePremium::addChatChars($reading, mb_strlen($text));

        return $r['url'];
    }

    private function forgetRoomToken(Request $request, string $room): void
    {
        $tokens = (array) $request->session()->get('chat_tokens', []);
        unset($tokens[$room]);
        $request->session()->put('chat_tokens', $tokens);
        if ($room === ChatRooms::DEFAULT) {
            $request->session()->forget('chat_token');
        }
    }

    private function rememberRoomToken(Request $request, string $room, string $token): void
    {
        $tokens        = (array) $request->session()->get('chat_tokens', []);
        $tokens[$room] = $token;
        $request->session()->put('chat_tokens', $tokens);
        if ($room === ChatRooms::DEFAULT) {
            // คีย์เดิม — session ที่ค้างอยู่ก่อนมีห้องยังหาห้องแม่หมอเจอ
            $request->session()->put('chat_token', $token);
        }
    }

    /** ข้อความล่าสุดของห้อง (เรียงเก่า → ใหม่) ไม่เกิน RENDER_LIMIT */
    private function loadRecentMessages(ChatConversation $conversation): void
    {
        $conversation->setRelation('messages', $conversation->messages()
            ->reorder()
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RENDER_LIMIT)
            ->get()
            ->reverse()
            ->values());
    }

    /**
     * การ์ดแพ็กเกจของข้อความชวนเปิดไพ่ — คำนวณเฉพาะข้อความชวนล่าสุด
     * (เดิมคำนวณทุกข้อความที่เคยชวน: ราคา + ข้อห้ามเปิดซ้ำ ต่อการ์ด ต่อข้อความ = query บานตามความยาวห้อง)
     * การ์ดเก่ากว่านั้นไม่แสดงแล้ว — ลูกค้าเห็นของล่าสุดที่กดได้จริงอยู่ชุดเดียวพอ
     *
     * @return array{id:?int,offers:array}
     */
    private function offerCardsFor(ChatConversation $conversation, $user): array
    {
        $last = $conversation->messages->last(fn ($m) => $m->role === 'assistant' && $m->offer_topic);
        if ($last === null) {
            return ['id' => null, 'offers' => []];
        }

        return ['id' => $last->id, 'offers' => ChatOffers::forRoom($conversation->persona, $last->offer_topic, $user)];
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
        // 🔮 ห้องคุยต่อจากไพ่เป็นของผู้พยากรณ์ที่อ่านไพ่ชุดนั้น (quick → บุษบา · kunsai → คุณไสย · อื่น ๆ → แม่หมอ)
        //    ห้องนั้นยังปิดอยู่ → ห้องแม่หมอ (ลูกค้าจ่ายไพ่ไปแล้ว ต้องได้คุยต่อเสมอ)
        $persona = ChatRooms::roomForSpread(TarotSpreads::keyFromType($reading->type));

        $bind = function () use ($request, $user, $reading, $persona): array {
            $room = ChatConversation::where('user_id', $user->id)
                ->where('reading_id', $reading->id)
                ->latest('id')
                ->first();
            if ($room) {
                return [$room, false];
            }

            $live = $this->conversationFor($request, $user, $persona);
            if ($live->reading_id === null && ! $live->messages()->where('role', 'user')->exists()) {
                $live->update(['reading_id' => $reading->id, 'title' => ReadingChatContext::title($reading)]);
                $room = $live;
            } else {
                $room = ChatConversation::create([
                    'user_id'       => $user->id,
                    'session_token' => (string) Str::uuid(),
                    'reading_id'    => $reading->id,
                    'title'         => ReadingChatContext::title($reading),
                    'persona'       => $persona,
                ]);
            }

            // คำทักทายประกอบในเครื่อง — ไม่ถาม AI ไม่คิดเงิน (เดิมเสียหนึ่งรอบไปกับการ prime)
            $room->messages()->create([
                'role'    => 'assistant',
                'content' => ReadingChatContext::greeting($reading, $persona),
            ]);

            return [$room, true];
        };

        try {
            [$room, $fresh] = Cache::lock("chat:reading-room:{$user->id}", 10)->block(5, $bind);
        } catch (LockTimeoutException) {
            // อีกคำขอถือล็อกนานผิดปกติ — ทำต่อเองดีกว่าให้ลูกค้าเห็นหน้า error (อย่างแย่ได้ห้องซ้ำ ไม่เสียข้อมูล)
            [$room, $fresh] = $bind();
        }

        $this->rememberRoomToken($request, $room->persona, $room->session_token);
        $request->session()->put('chat_room', $room->persona);

        return [$room, $fresh];
    }

    /** คีย์ cache ปุ่มจาก AI ชุดล่าสุดของห้อง */
    private static function askKey(int $conversationId): string
    {
        return "chat:ask:{$conversationId}";
    }

    /** ปุ่มลัดตายตัว — ระหว่างทำนายอยู่ตัดปุ่มที่พาไปเริ่มดูดวงใบใหม่ออก */
    private function staticChips(Request $request, array $chips): array
    {
        return ReadingRoomLock::active($request) !== null ? ChatSuggestions::withoutReadingStarters($chips) : $chips;
    }

    /**
     * ลูกค้าขอดูดวงระหว่างที่ยังเปิดการ์ดใหม่ไม่ได้ — คืนคำตอบนุ่ม ๆ แทนการ์ด หรือ null ถ้าเปิดได้ตามปกติ
     */
    private function offerHold(Request $request, $user, ?string $persona, ?array $qa = null): ?string
    {
        $self = ChatRooms::selfName($persona);
        if ($qa !== null && ! $qa['over']) {
            $mins = max(1, (int) ceil($qa['left'] / 60));

            return "ตอนนี้เรากำลังคุยต่อจากไพ่ชุดที่เปิดไว้นะคะ ยังถามต่อได้อีกราว {$mins} นาที "
                . "ถ้าอยากเปิดไพ่ชุดใหม่ กดปุ่ม \"พอแล้ว\" ด้านบนก่อน แล้ว{$self}เปิดให้ได้เลยค่ะ 🌙";
        }
        if (($lock = ReadingRoomLock::active($request)) !== null) {
            return $lock['reason'] === 'reading'
                ? "{$self}กำลังอ่านไพ่ชุดที่เปิดไว้ให้อยู่นะคะ อีกสักครู่คำทำนายจะเสร็จ แล้วถามต่อจากไพ่ชุดนั้นได้เลยค่ะ 🌙"
                : "ตอนนี้เรากำลังอยู่ในพิธีเปิดไพ่กันอยู่นะคะ กลับไปเลือกไพ่ให้ครบก่อน แล้วค่อยถามต่อจากไพ่ได้เลยค่ะ 🌙";
        }
        if ($user && ($bill = ChatTopupController::pendingBill($user)) !== null) {
            return "ยังมีบิลเติมเงินเลขที่ {$bill->reference_code} รอชำระอยู่นะคะ จ่ายบิลนี้ให้เรียบร้อยก่อน แล้ว{$self}เปิดไพ่ให้ต่อได้เลยค่ะ";
        }

        return null;
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
        $t0 = microtime(true);
        $data = $request->validate([
            'message' => 'required|string|max:2000',
            // 🔮 ห้องที่หน้าจอเปิดอยู่ — ไม่ส่ง = หน้าเก่าก่อนมีห้องที่ค้างในแท็บ ซึ่งเป็นห้องแม่หมอเสมอ
            //    (ห้ามเดาจาก session: แท็บเก่าห้องแม่หมอส่งแล้วข้อความไปโผล่ห้องบุษบาที่เพิ่งเปิดอีกแท็บ)
            'room'    => 'nullable|string|max:16',
        ]);

        $user = $request->user();
        $gate = $this->gate($user);

        if (!$gate['allowed']) {
            return $request->wantsJson()
                ? response()->json(['error' => $gate['reason'], 'reason_code' => $gate['code']], 403)
                : redirect()->route('chat.index')->with('status', $gate['reason']);
        }

        $room = ChatRooms::normalize($data['room'] ?? null);
        if (! ChatRooms::isOpen($room)) {
            $msg = ChatRooms::name($room) . ' ยังไม่เปิดให้คุยนะคะ เร็ว ๆ นี้ค่ะ';

            return $request->wantsJson()
                ? response()->json(['error' => $msg, 'reason_code' => 'room_closed'], 403)
                : redirect()->route('chat.index', ['pick' => 1])->with('status', $msg);
        }

        // Idempotency — block a double-submit of the same message.
        // guardChargeAuto: ล้ม/คืนเงิน → ปล่อยล็อก ลูกค้ากด "ลองส่งใหม่" ได้ทันที (เดิมค้าง 409 ไป 90 วิ)
        if (! $this->guardChargeAuto($request, 'chat')) {
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
            $this->releaseChargeLock();
            $limitMsg = ChatPolicy::limitMessage($room);

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
            $this->releaseChargeLock();

            return $request->wantsJson()
                ? response()->json(['error' => $msg, 'reason_code' => 'insufficient_funds'], 402)
                : redirect()->route('wallet.index')->with('status', $msg);
        }

        // 🔒 ทีละข้อความต่อคน — สองแท็บ (หรือเว็บ + แอพ) ยิงพร้อมกันได้ เดิมแต่ละข้อความถือ worker
        //    ของเซิร์ฟเวอร์ (ที่ใช้ร่วมกันหลายเว็บ) ค้างไว้ทั้งคู่ระหว่างรอ AI และประวัติฝั่ง Thaiprompt
        //    เขียนทับกันจนข้อความหาย · reason_code แยกจาก in_flight (ข้อความเดิมส่งซ้ำ) เพราะข้อความนี้
        //    ยังไม่ถูกส่งเลย หน้าแชทต้องโชว์ปุ่ม "ลองส่งใหม่" ไม่ใช่บอกให้รอเฉย ๆ
        $inflight = Cache::lock("chat:inflight:u{$user->id}", self::BUDGET_SEC + 15);
        if (! $inflight->get()) {
            $this->releaseChargeLock();
            $msg = ChatRooms::selfName($room) . 'กำลังตอบข้อความก่อนหน้าอยู่ค่ะ รอให้ตอบเสร็จแล้วกด "ลองส่งใหม่" ได้เลย (ยังไม่คิดเครดิต)';

            return $request->wantsJson()
                ? response()->json(['error' => $msg, 'reason_code' => 'busy_other'], 409)
                : redirect()->route('chat.index')->with('status', $msg);
        }

        try {
            return $this->deliver($request, $user, $room, $data['message'], $cost, $t0);
        } finally {
            $inflight->release();
        }
    }

    /** ส่งข้อความที่ผ่านทุกด่านแล้ว — บันทึก → ถามผู้พยากรณ์ → หักเงิน → ตอบกลับ */
    private function deliver(Request $request, $user, string $room, string $message, float $cost, float $t0)
    {
        $conversation = $this->conversationFor($request, $user, $room);

        // ⏳ ห้องที่คุยต่อจากไพ่: หมดเวลาถามต่อ/กด "พอแล้ว" แล้ว = ไม่ส่ง ไม่ถาม AI ไม่คิดเงิน
        $reading = ReadingChatContext::readingOf($conversation);
        $qa = ReadingQaClock::state($conversation, $reading);
        if ($qa !== null && $qa['over']) {
            $this->releaseChargeLock();
            $msg = ReadingQaClock::overMessage($conversation->persona);

            return $request->wantsJson()
                ? response()->json(['error' => $msg, 'reason_code' => 'qa_over', 'qa' => $qa], 403)
                : redirect()->route('chat.index', ['room' => $room])->with('status', $msg);
        }
        // คำถามแรก = เริ่มนับ (นับจากตอนลูกค้าถาม ไม่ใช่ตอนเปิดห้อง)
        $qaJustStarted = $qa !== null && ! $qa['started'];
        if ($qa !== null) {
            ReadingQaClock::startIfNeeded($conversation);
        }

        $userMessage = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role'    => 'user',
            'content' => $message,
        ]);

        $dispatch = $this->dispatchToUpstream($user, $conversation, $message, $t0);
        $reply    = $dispatch['reply'];
        $degraded = $dispatch['degraded'] ?? false;
        $kind     = $dispatch['kind'] ?? 'reply';
        $offers   = $kind === 'offer' ? ChatOffers::forRoom($conversation->persona, (string) ($dispatch['offer_topic'] ?? 'general'), $user) : [];
        // 🔒 (2026-11-05) กำลังทำนายอยู่ (สับไพ่/อ่านไพ่) หรือมีบิลเติมเงินค้าง — ไม่ยื่นการ์ดเปิดไพ่ใบใหม่ซ้อน
        //    เจ้าของสั่ง "ถ้าทำนายอยู่ปุ่มดูดวงไม่ควรโผล่มา" + "ป้ายเปิดไพ่ต้องไม่ขึ้นถ้ายังค้างบิลอยู่"
        if ($offers !== [] && ($hold = $this->offerHold($request, $user, $conversation->persona, $qa)) !== null) {
            $offers = [];
            $reply  = $hold;
        }
        // ✨ ปุ่มที่ผู้พยากรณ์คิดมาให้จากบทสนทนา — การ์ดเปิดไพ่ขึ้นอยู่แล้วไม่ต้องมีปุ่มแย่งความสนใจ
        $aiSuggestions = ! $degraded && $offers === [] ? (array) ($dispatch['suggestions'] ?? []) : [];
        if ($aiSuggestions !== [] && (ReadingRoomLock::active($request) !== null || $qa !== null)) {
            $aiSuggestions = ChatSuggestions::withoutReadingStarters($aiSuggestions);
        }
        if ($degraded) {
            // ไม่ได้คำตอบจริง ไม่คิดเงิน — ลูกค้าส่งใหม่ด้วยคีย์เดิมได้ทันที
            $this->releaseChargeLock();
            // ⏳ คำถามแรกไม่ได้คำตอบ = ยังไม่เริ่มนับ (ลูกค้าไม่เสียเวลาถามต่อไปกับตอนที่ระบบล่ม)
            if ($qaJustStarted) {
                ChatConversation::whereKey($conversation->id)->update(['qa_started_at' => null]);
                $conversation->refresh();
            }
            // 🔮 (2026-11-03) และไม่กินโควตาคุยฟรี: dailyUsed() นับข้อความของลูกค้า ถ้าเก็บไว้ ส่งใหม่หนึ่งครั้ง
            //    = เสียโควตาสองข้อความแลกคำตอบเดียว · คำขอโทษก็ไม่เก็บ (ลูกค้าเห็นบนจอแล้ว ส่งใหม่ได้คำตอบจริงแทน)
            $userMessage->delete();
        }

        // Debit only AFTER a successful reply — fairer to the user when upstream
        // blips. And NEVER charge for a degraded placeholder (no AI key AND
        // upstream unreachable): the user gets the "not ready" note for free.
        // Nor for an invitation to open cards (kind=offer): that is the shop
        // counter, not an answer — the reading itself is what gets paid for.
        // Race-safe because debit() locks the wallet row.
        $debitTx = null;
        if ($cost > 0 && !$degraded && $kind !== 'offer') {
            try {
                $debitTx = $this->wallet->debit($user, $cost, 'สนทนากับ' . ChatRooms::name($room), [
                    'reference_type' => 'chat_message',
                    'reference_id'   => $userMessage->id,
                ]);
            } catch (InsufficientFundsException $e) {
                // Race lost (parallel debit between balance check and now).
                // We've already produced a reply — surface a one-liner in the
                // assistant message so the user understands why no further
                // messages will go through.
                $reply .= "\n\n— *" . ChatRooms::selfName($conversation->persona) . 'บอก: เครดิตในวอลเลตหมดพอดีตอนตอบครั้งนี้ ครั้งหน้ากรุณาเติมเงินก่อนนะคะ*';
            }
        }

        // We've already debited — if persisting the reply now fails, the user
        // would be charged for a message they never see. Refund on failure.
        try {
            if (! $degraded) {
                // จำปุ่มชุดล่าสุดไว้ให้หน้าที่รีเฟรช (ชุดว่าง = ลบของเก่า ไม่ให้ปุ่มของคำตอบก่อนค้างอยู่)
                $aiSuggestions !== []
                    ? Cache::put(self::askKey($conversation->id), $aiSuggestions, now()->addHours(6))
                    : Cache::forget(self::askKey($conversation->id));
                ChatMessage::create([
                    'chat_conversation_id' => $conversation->id,
                    'role'        => 'assistant',
                    'content'     => $reply,
                    'offer_topic' => $offers !== [] ? ChatReadingIntent::normalizeTopic($dispatch['offer_topic'] ?? null) : null,
                ]);
            }
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
            $this->releaseChargeLock();
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
            // ✨ พรีเมียมเสียง — ระหว่างเวลาถามต่อ ทุกคำตอบพูดออกเสียงของห้อง
            $voiceUrl = ! $degraded && $kind !== 'offer' && $reading !== null
                ? $this->speakReply($reading, $conversation, (string) $reply) : null;

            return response()->json([
                'reply'       => $reply,
                'voice_url'   => $voiceUrl,
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
                // ⏳ นาฬิกาถามต่อ (null = ห้องคุยทั่วไป) — หน้าเว็บนับถอยหลังตามเวลาของเซิร์ฟเวอร์
                'qa'          => ReadingQaClock::state($conversation, $reading),
                'server_now'  => now()->getTimestamp(),
                'suggestions' => $awaiting || $offers !== [] ? [] : $this->staticChips($request, ChatSuggestions::followUp()),
                // ✨ (2026-11-05) ปุ่มจาก AI — แม่หมอถามกลับอยู่ = ตัวเลือกคำตอบ · ไม่ได้ถาม = คำถามที่น่าถามต่อ
                'ai_suggestions' => $aiSuggestions,
                // 🌙 (2026-09-15) ลูกค้าขอให้ทำนาย → การ์ดแพ็กเกจเปิดไพ่ (หักเครดิตตอนเปิดไพ่จริง)
                'kind'        => $kind,
                'offers'      => $offers,
                'question'    => $offers !== [] ? mb_substr($message, 0, 300) : null,
            ]);
        }

        return redirect()->route('chat.index')->with('status', ChatRooms::short($conversation->persona) . 'ตอบกลับแล้ว');
    }

    /**
     * ⏳ "พอแล้ว" — จบเวลาถามต่อของไพ่ชุดนี้ก่อนกำหนด (เจ้าของสั่ง "ปุ่ม พอแล้ว ด้วย หมายถึงเลิกก่อนเวลา")
     *
     * คำลาประกอบในเครื่อง ไม่ถาม AI ไม่คิดเงิน · กดซ้ำ = ได้สถานะเดิม ไม่มีคำลาซ้ำ
     */
    public function endQa(Request $request)
    {
        $data = $request->validate(['room' => 'nullable|string|max:16']);
        $user = $request->user();
        $room = ChatRooms::normalize($data['room'] ?? null);
        $conversation = $this->conversationFor($request, $user, $room);
        $reading = ReadingChatContext::readingOf($conversation);
        if ($reading === null || $conversation->user_id !== $user->id) {
            return response()->json(['error' => 'ห้องนี้ไม่ได้จับเวลาถามต่อค่ะ', 'reason_code' => 'no_clock'], 422);
        }

        $reply = null;
        if (ReadingQaClock::end($conversation)) {
            $reply = ReadingQaClock::farewell($conversation->persona);
            ChatMessage::create(['chat_conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $reply]);
        }

        return response()->json([
            'reply'      => $reply,
            'reply_html' => $reply !== null ? Markdown::safe($reply) : null,
            'qa'         => ReadingQaClock::state($conversation, $reading),
            'server_now' => now()->getTimestamp(),
        ]);
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
            return redirect()->route('chat.index')->with('status', ChatPolicy::limitMessage(
                ChatRooms::roomForSpread(TarotSpreads::keyFromType($reading->type))
            ));
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

        [$conversation, $fresh] = $this->conversationForReading($request, $user, $reading);

        // ห้อง Thaiprompt ของห้องนี้: เพิ่งผูกไพ่ / ยังไม่มี → เปิดใหม่พร้อมบริบทเต็ม
        // กดซ้ำ/รีเฟรชในห้องเดิม → ใช้ห้องเดิมต่อ (ประวัติฝั่งผู้พยากรณ์ไม่หาย) · เปิดห้องไม่ถาม AI จึงไม่เสียรอบ
        // 🔮 (2026-11-03) ห้อง Thaiprompt เก็บต่อห้องแชท (cache) ไม่ใช่ต่อเบราว์เซอร์แล้ว จึงไม่มีเคส "สลับห้อง"
        if ($fresh || $this->upstream->sessionFor($conversation->id) === null) {
            // ลืมห้องเก่าก่อนเสมอ — เปิดใหม่ไม่ติด ข้อความถัดไปจะเปิดเองพร้อมบริบท ไม่ไปคุยในห้องของเรื่องอื่น
            $this->upstream->forgetSession($conversation->id);
            $this->openUpstreamSession($user, $conversation, ReadingChatContext::for($reading));
        }

        // Carry the first question into /chat so it auto-sends there (charged
        // by the normal send path). One-shot via session — see index()'s pull().
        if ($question !== '') {
            $request->session()->put('chat_autosend', $question);
        }

        return redirect()->route('chat.index');
    }

    /**
     * 🌙 (2026-11-03 FB web-only) ลูกค้าที่ย้ายมาจากแชทเพจ FB — /chat/continue/{handoff}
     *
     * ลิงก์ในเพจพาลูกค้าผ่าน SSO มาลงที่นี่ (ThaipromptController::isHandoffPath — สลับบัญชีเป็นเจ้าของลิงก์)
     *
     * 🔮 (2026-11-04 เจ้าของสั่ง) การ์ด "เข้าสู่ระบบดูดวง" ในเพจ → สมัคร/เข้าระบบ แล้ว **ลงหน้าเลือกห้องดูดวง**
     *    "ส่งมาหน้าห้องดูดวงให้เลือกเลย" · "ไม่ต้องส่งบริบทที่คุยในเฟชบุ๊คมา"
     *    ⇒ ไม่เปิดห้อง "คุยต่อจากเพจ" ไม่ทักทายต่อเรื่องจากเพจ ไม่เรียก Thaiprompt ที่นี่
     *    ลูกค้าเลือกห้องเอง — ห้องไหนก็ได้ Thaiprompt รู้จักลูกค้าจาก thaiprompt_user_id (RPG/อุปนิสัย/ประวัติไพ่)
     */
    public function continue(Request $request, string $handoff)
    {
        return redirect()->route('chat.index', ['pick' => 1]);
    }

    public function show(ChatConversation $conversation)
    {
        $user = auth()->user();
        $isOwner = $user && $conversation->user_id === $user->id;
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        if (!$isOwner && !$isAdmin) {
            abort(403);
        }

        $this->loadRecentMessages($conversation);

        return view('pages.chat.index', [
            'conversation' => $conversation,
            'room'         => ChatRooms::normalize($conversation->persona),
            'rooms'        => ChatRooms::cards(),
            'offerCards'   => ['id' => null, 'offers' => []],
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
    private function dispatchToUpstream($user, ChatConversation $conversation, string $message, float $t0): array
    {
        $room    = ChatRooms::normalize($conversation->persona);
        $persona = ChatRooms::upstreamPersona($room);
        $left    = fn (): int => (int) floor(self::BUDGET_SEC - (microtime(true) - $t0));

        // 💬 (2026-09-21) ห้องที่ผูกกับไพ่ที่จ่ายแล้ว — อ่านจากฐานข้อมูลทุกครั้ง (เดิมอ่านจาก session เบราว์เซอร์
        //    และมีอายุ 24 ชม.) · บริบท = ไพ่ทุกใบ + คำพยากรณ์ฉบับเต็ม ส่งไปทุกรอบ ห้องฝั่ง Thaiprompt หมดอายุ
        //    หรือ cache ถูกล้างเมื่อไร แม่หมอก็ยังเห็นคำพยากรณ์ครบ
        $reading  = ReadingChatContext::readingOf($conversation);
        // ⏳ บรรทัดเวลาที่เหลือขึ้นก่อนบริบทไพ่ — ผู้พยากรณ์รู้ว่าเหลือกี่นาที (เจ้าของถาม "ทำไมบอทไม่รู้ว่าเหลือเวลาเท่าไหร่")
        $timeLine = $reading ? ReadingQaClock::contextLine(ReadingQaClock::state($conversation, $reading)) : null;
        $context  = $reading
            ? trim(($timeLine !== null ? $timeLine . "\n\n" : '') . ReadingChatContext::for($reading, $timeLine !== null ? mb_strlen($timeLine) + 2 : 0))
            : null;
        $grounded = $reading !== null;

        // 🌙 (2026-09-15) "คุยฟรีจนกว่าจะเริ่มการทำนาย" — ขอให้ดูดวงตรง ๆ = ยื่นการ์ดเปิดไพ่เลย
        //    ไม่ส่งไปให้ AI ทำนายฟรี · ยกเว้นห้องที่คุยต่อจากไพ่ที่จ่ายแล้ว (ถามเรื่องไพ่ชุดนั้น = คุยได้)
        if (! $grounded && ($topic = ChatReadingIntent::detect($message)) !== null) {
            return ['reply' => ChatOffers::invite($topic, $room, $user), 'degraded' => false, 'kind' => 'offer', 'offer_topic' => $topic];
        }

        // Re-use the upstream session id across messages for context continuity.
        $sessionId = $this->upstream->sessionFor($conversation->id)
            ?? $this->openUpstreamSession($user, $conversation, $context);

        $out = $sessionId && $left() >= 8
            ? $this->upstream->send($user, $sessionId, $message, $grounded, $context, $persona, $left() - 3)
            : null;

        // ลองห้องใหม่อีกรอบเฉพาะตอน "ได้คำตอบว่าง" (ห้องค้าง) และยังมีเวลาพอ — ต่อไม่ติด/หมดเวลา/เซิร์ฟเวอร์แน่น
        // ยิงซ้ำทันทีมีแต่ซ้ำเติมฝั่งที่กำลังล้ม และกินเวลาจนเกินเพดาน 60 วิ (เดิมรวมได้ ~148 วิ)
        if ($out === null && $sessionId && $this->upstream->worthRetry() && $left() >= 25) {
            Log::info('ผู้พยากรณ์ตอบว่าง — เปิดห้องใหม่แล้วลองอีกครั้ง', ['user_id' => $user->id, 'room' => $room]);
            $this->upstream->forgetSession($conversation->id);
            $newSession = $this->openUpstreamSession($user, $conversation, $context);
            $out = $newSession ? $this->upstream->send($user, $newSession, $message, $grounded, $context, $persona, $left() - 3) : null;
        }

        if ($out === null) {
            Log::warning('ผู้พยากรณ์ไม่ตอบ — ใช้ทางสำรอง', ['user_id' => $user->id, 'room' => $room, 'left' => $left()]);

            // ทางสำรอง (AiOracle) รอได้ถึง 30 วิ — เวลาไม่พอแล้วตอบขอโทษ (ไม่คิดเงิน) ดีกว่าให้ลูกค้าเห็นหน้า error
            // ห้องที่ไม่ใช่แม่หมอก็ไม่ใช้ทางสำรอง: ทางสำรองพูดเป็นแม่หมอ บุคลิกจะไม่ตรงห้อง
            if ($room !== ChatRooms::DEFAULT || $left() < 32) {
                return ['reply' => ChatRooms::busyReply($room), 'degraded' => true];
            }

            return $this->degradedFallback($message, $context);
        }

        return [
            'reply'       => $out['reply'],
            'degraded'    => false,
            'kind'        => $out['kind'],
            'offer_topic' => $out['offer_topic'],
            'suggestions' => $out['suggestions'] ?? [],
        ];
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
    private function openUpstreamSession($user, ChatConversation $conversation, ?string $context = null): ?string
    {
        $start     = $this->upstream->start($user, $context, persona: ChatRooms::upstreamPersona($conversation->persona));
        $sessionId = $start['session'] ?? null;
        if (! $sessionId) {
            return null;
        }

        $this->upstream->rememberSession($conversation->id, $sessionId);

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
