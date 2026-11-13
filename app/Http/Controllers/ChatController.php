<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Support\ReadingTheater;
use App\Support\VoicePremium;
use App\Services\Chat\ChatOffers;
use App\Services\Chat\ChatTurn;
use App\Services\Chat\FreeReadingRoom;
use App\Services\Chat\MaeMorUpstream;
use App\Services\Chat\MicTranscriber;
use App\Services\Chat\ReadingChatContext;
use App\Services\Wallet\WalletService;
use App\Support\ChatPolicy;
use App\Support\ChatRooms;
use App\Support\FreeAskClock;
use App\Support\ChatTakeover;
use App\Support\ChatSuggestions;
use App\Support\Markdown;
use App\Support\Pricing;
use App\Support\ReadingQaClock;
use App\Support\ReadingRoomLock;
use App\Support\TarotSpreads;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /** ข้อความที่แสดงในห้อง — ห้องยาวมากไม่ต้องลากทั้งก้อนมาทุกครั้งที่เปิดหน้า */
    private const RENDER_LIMIT = 80;

    public function __construct(
        private WalletService $wallet,
        private MaeMorUpstream $upstream,
        private ChatTurn $turn,
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
                'chatFree'  => ChatPolicy::advertisedFree(),
                'chatLede'  => ChatPolicy::lede(),
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

        // 🔒 กำลังทำนาย → ซ่อนทุกปุ่มที่พาไปเปิดไพ่ใบใหม่ (บิลค้างตัดสินด้านล่าง หลังรู้ว่ามีแบบไพ่อะไรให้เปิด)
        $bill       = $user ? ChatTopupController::pendingBill($user) : null;
        $hideOffers = $lock !== null;

        // เข้าแชทต่อจากการเปิดไพ่หรือเปล่า — ใช้เลือกชุดปุ่มคำถามลัด
        // 💬 (2026-09-21) อ่านจากห้อง (chat_conversations.reading_id) ไม่ใช่ session เบราว์เซอร์อีกต่อไป
        $reading = ReadingChatContext::readingOf($conversation);
        // ⏳ นาฬิกาถามต่อของห้องที่คุยต่อจากไพ่ (null = ห้องคุยทั่วไป)
        $qa = ReadingQaClock::state($conversation, $reading);
        // ระหว่างเวลาถามต่อของไพ่ชุดนี้ = ยังอยู่ในการทำนาย → ไม่ยื่นไพ่ชุดใหม่ (กด "พอแล้ว" ก่อนได้)
        $hideOffers = $hideOffers || ($qa !== null && ! $qa['over']);
        // 🔮 (2026-11-06) ทุกแบบการเปิดไพ่ของห้อง — หน้ารวมแพ็กเกจ /tarot ปิดแล้ว ลูกค้าเลือกบนโต๊ะในห้องแทน
        //    โต๊ะวางไม่เกิน 3 แบบที่เปิดได้ตอนนี้ (ผู้สูงอายุ ตัวเลือกมากไปคือไม่เลือก) ที่เหลืออยู่ใน "ไพ่แบบอื่น"
        $menu  = $gate['allowed'] && ! $hideOffers ? ChatOffers::roomMenu((string) $conversation->persona, $user) : [];
        // 🧾 บิลค้างขวางโต๊ะเฉพาะเมื่อเงินในวอลเลตยังไม่พอเปิดไพ่สักแบบ — พอแล้วเปิดได้เลย ไม่ออกบิลใหม่
        //    (เคสจริง 2026-11-11 ลูกค้า #26: เงินเข้าแล้ว ฿39.92 แต่มีรายการรอค้าง บอทตอบทวงบิล 6 ครั้ง จนลูกค้าโอนซ้ำ)
        //    บิลยังส่งให้หน้าแชท (pendingBill) — กดแบบที่เงินไม่พอ = เปิดบิลเดิม ไม่ซ้อนบิลใหม่
        $billBlocks = $bill !== null && ! $this->turn->walletCovers($user, $menu);
        if ($billBlocks) {
            $hideOffers = true;
            $menu = [];
        }
        $table = array_slice(array_values(array_filter($menu, fn ($o) => $o['locked_until'] === null)), 0, 3);

        // ห้องของไพ่ (มีนาฬิกาถามต่อ/การ์ดปิดท้ายนับถอยหลัง) — ป๊อปอัป "มีอะไรใหม่" ห้ามบัง
        //    เคสจริงบน prod: ป๊อปอัปเด้งทับการ์ดหมดเวลา ระหว่างอ่านป๊อปอัป ห้องนับถอยหลังปิดเอง
        if ($qa !== null) {
            $request->attributes->set('hide_whats_new', true);
        }
        // 🎁 (2026-11-11) ห้องที่เริ่มจากไพ่ฟรี — นาฬิกาถามฟรี 2 นาที + ป้ายไพ่หมวดอื่น (ไม่ซ่อนแพ็กเกจแบบห้องไพ่ที่จ่ายแล้ว)
        $freeAsk = $reading === null ? FreeAskClock::state($conversation) : null;
        $freePicks = $freeAsk !== null && $gate['allowed'] && ! $hideOffers ? FreeAskClock::picks((string) $conversation->persona, $user) : [];
        if ($freeAsk !== null) {
            $request->attributes->set('hide_whats_new', true);
        }

        return view('pages.chat.index', [
            'qa'           => $qa,
            'freeAsk' => $freeAsk,
            'freePicks' => $freePicks,
            'qaWrap'       => $qa !== null && $user ? $this->qaWrap($reading, $user) : null,
            'conversation' => $conversation,
            'room'         => $conversation->persona,
            'rooms'        => ChatRooms::cards(),
            'offerCards'   => $hideOffers ? ['id' => null, 'offers' => []] : $this->offerCardsFor($conversation, $user),
            // 🔮 (2026-11-05) แพ็กเกจของห้องวางบนโต๊ะในห้องพยากรณ์ — กดเปิดไพ่ได้ตลอด ไม่ต้องรอแม่หมอยื่นการ์ด
            //    ยกเว้นตอนกำลังทำนายอยู่ หรือมีบิลเติมเงินค้าง (โชว์บิล/การทำนายที่ค้างแทน)
            'tableOffers'  => $table,
            'tableMenu'    => count($menu) > count($table) ? $menu : [],
            // /tarot (ลิงก์เดิมทั่วเว็บ) พามาที่นี่พร้อม ?menu=1 = เปิดรายการไพ่ทุกแบบให้ทันที
            'menuOpen'     => $request->boolean('menu') && count($menu) > count($table),
            'roomLock'     => $lock !== null ? ReadingRoomLock::view($lock) : null,
            'pendingBill'  => $bill !== null ? ChatTopupController::billView($bill) : null,
            // ป้ายบิลบนโต๊ะ (แทนแพ็กเกจ) เฉพาะเมื่อบิลขวางการเปิดไพ่จริง
            'billBlocks'   => $billBlocks,
            // 🎁 ห้องของไพ่ฟรีไม่มีปุ่ม "ถามต่อจากเรื่องนี้" — ป้ายไพ่หมวดอื่นแทน (เจ้าของสั่ง 2026-11-11)
            'aiSuggestions' => $freeAsk !== null ? []
                : ($hideOffers ? ChatSuggestions::withoutReadingStarters((array) Cache::get(ChatTurn::askKey($conversation->id), []))
                               : (array) Cache::get(ChatTurn::askKey($conversation->id), [])),
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
            // 🎁 หมดเวลาถามฟรีแล้ว คำชวนเปิดไพ่ไม่ใช่คำถามที่รอคำตอบ (ไม่งั้นป้ายไพ่หมวดอื่นถูกยุบเป็น "กำลังรอคำตอบ")
            'awaiting' => ($freeAsk['over'] ?? false) ? false : ChatSuggestions::isAwaitingAnswer(
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
     * 🌙 (2026-11-07) การ์ดปิดท้ายเมื่อหมดเวลาถามต่อ — เจ้าของ: "เมื่อเวลาหมดให้ แนะนำ และให้รีวิว อธิบายว่าทำไงได้บ้าง
     *    ฟังย้อนได้ที่ไหน มีปุ่มให้เลือกหรือจะไปดูไปทำอย่างอื่น ได้ ภายใน 1 นาที ถ้าไม่ทำอะไรก็ปิดห้องและรีเซ็ต"
     *
     * @return array{review_url:?string,result_url:string,history_url:string,listen:bool,reviewed:bool,rating:int,close_seconds:int}
     */
    private function qaWrap(Reading $reading, $user): array
    {
        $own    = $reading->user_id === $user->id;
        $review = $own ? $reading->review : null;

        return [
            // รีวิวได้เฉพาะเจ้าของคำทำนายที่อ่านเสร็จแล้ว (กติกาเดียวกับหน้าผล)
            'review_url'    => $own && TarotSpreads::isTarotType($reading->type) && ! $reading->isInProgress() && ! $reading->isFailed()
                ? route('tarot.review', $reading) : null,
            'result_url'    => route('tarot.show', $reading),
            'history_url'   => route('account.history'),
            // หน้าผลมีโรงละครเล่าซ้ำพร้อมเสียง → ปุ่มเขียนว่า "ฟังย้อน" แทน "อ่านย้อน"
            'listen'        => $own && (ReadingTheater::available($reading) || VoicePremium::active($reading)),
            'reviewed'      => $review !== null,
            'rating'        => (int) ($review->rating ?? 0),
            'close_seconds' => max(15, (int) config('reading_qa.wrap_close_seconds', 60)),
        ];
    }

    /**
     * 🎙️ (2026-11-07) ปุ่มไมค์ในห้องพยากรณ์ — เจ้าของถาม "ปุ่มให้ลูกค้ากดพูดไมค์อยู่ไหน"
     *
     * ทางหลักคือเบราว์เซอร์ถอดเอง (ฟรี ไม่ผ่านที่นี่) · ที่นี่ = ทางสำรองสำหรับเครื่องที่ถอดเองไม่ได้
     * ได้ข้อความกลับไปใส่ช่องพิมพ์ ลูกค้าตรวจ/แก้ก่อนกดส่งเอง (ถอดผิดแล้วส่งเลย = เสียค่าข้อความฟรี ๆ)
     */
    public function transcribe(Request $request)
    {
        $request->validate(MicTranscriber::RULES, MicTranscriber::MESSAGES);
        $r = MicTranscriber::run($request->file('audio'), (int) $request->user()->id, (string) $request->input('room', ''));

        return $r['ok']
            ? response()->json(['text' => $r['text']])
            : response()->json(['message' => $r['message']], $r['status']);
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

        $offers = $conversation->free_reading_id !== null && $conversation->reading_id === null
            ? FreeAskClock::offersFor((string) $conversation->persona, $user, $last->offer_topic)
            : ChatOffers::forRoom($conversation->persona, $last->offer_topic, $user);

        return ['id' => $last->id, 'offers' => $offers];
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

    /** ปุ่มลัดตายตัว — ระหว่างทำนายอยู่ตัดปุ่มที่พาไปเริ่มดูดวงใบใหม่ออก */
    private function staticChips(Request $request, array $chips): array
    {
        return ReadingRoomLock::active($request) !== null ? ChatSuggestions::withoutReadingStarters($chips) : $chips;
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

        // 🧑‍💼 (2026-11-09) แอดมินคุมห้องอยู่ — ข้อความไปหาแอดมิน ไม่ถาม AI ไม่คิดเงิน
        //    เช็คก่อนด่านโควตา/เครดิต: ลูกค้าที่เครดิตหมดต้องยังตอบแอดมินที่กำลังคุยด้วยได้
        $conversation = $this->conversationFor($request, $user, $room);
        if (ChatTakeover::isPaused($conversation)) {
            return $this->deliverToAdmin($request, $user, $conversation, $data['message']);
        }

        // Idempotency — block a double-submit of the same message.
        // guardChargeAuto: ล้ม/คืนเงิน → ปล่อยล็อก ลูกค้ากด "ลองส่งใหม่" ได้ทันที (เดิมค้าง 409 ไป 90 วิ)
        if (! $this->guardChargeAuto($request, 'chat')) {
            return $request->wantsJson()
                ? response()->json(['error' => 'ข้อความก่อนหน้ากำลังส่งอยู่ กรุณารอสักครู่', 'reason_code' => 'in_flight'], 409)
                : redirect()->route('chat.index')->with('status', 'ข้อความก่อนหน้ากำลังส่งอยู่ กรุณารอสักครู่');
        }

        // 🎁 ราคาของข้อความนี้ (โควตาฟรี · ถามวิธีใช้เว็บ · ห้องของไพ่ฟรี) — ต้องอ่าน **ก่อน** บันทึกข้อความของผู้ใช้
        //    ไม่งั้น dailyUsed() จะนับข้อความนี้รวมไปด้วย → ข้อความที่ 10 (ซึ่งควรฟรี) จะโดนคิดเงิน
        ['cost' => $cost, 'help' => $helpTurn] = ChatTurn::costFor($user, $conversation, $data['message']);

        // 🆓 (2026-07-24) โหมดคุยฟรี — เพดานข้อความต่อวัน กันต้นทุน AI บานปลาย
        //   ครบเพดานแล้วแม่หมอชวนไปเปิดไพ่แบบเจาะลึกแทน (เตะเบาๆ ไม่ใช่ error แข็งๆ)
        //   ⚠️ exhausted() บล็อกเฉพาะโหมดฟรีล้วน — โหมดคิดเงินให้ผ่านไปหักเครดิตแทน
        if (! $helpTurn && ChatPolicy::exhausted($user)) {
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
        $inflight = Cache::lock("chat:inflight:u{$user->id}", ChatTurn::BUDGET_SEC + 15);
        if (! $inflight->get()) {
            $this->releaseChargeLock();
            $msg = ChatRooms::selfName($room) . 'กำลังตอบข้อความก่อนหน้าอยู่ค่ะ รอให้ตอบเสร็จแล้วกด "ลองส่งใหม่" ได้เลย (ยังไม่คิดเครดิต)';

            return $request->wantsJson()
                ? response()->json(['error' => $msg, 'reason_code' => 'busy_other'], 409)
                : redirect()->route('chat.index')->with('status', $msg);
        }

        try {
            return $this->deliver($request, $user, $room, $conversation, $data['message'], $cost, $t0);
        } finally {
            $inflight->release();
        }
    }

    /** ส่งข้อความที่ผ่านทุกด่านแล้ว — บันทึก → ถามผู้พยากรณ์ → หักเงิน → ตอบกลับ */
    private function deliver(Request $request, $user, string $room, ChatConversation $conversation, string $message, float $cost, float $t0)
    {
        // 💬 (2026-11-12) ขั้นตอนทั้งหมดอยู่ที่ ChatTurn — แอพ (POST /v1/chat/conversations/{id}/send) เรียกตัวเดียวกัน
        //    ที่นี่จัดหน้าตาคำตอบของหน้าเว็บอย่างเดียว (JSON เดิมทุกคีย์ / redirect ของฟอร์มธรรมดา)
        $turn = $this->turn->deliver($request, $user, $conversation, $message, $cost, $t0,
            fn () => $this->releaseChargeLock(), speak: $request->wantsJson());

        switch ($turn['status']) {
            case 'qa_over':
                return $request->wantsJson()
                    ? response()->json(['error' => $turn['message'], 'reason_code' => 'qa_over', 'qa' => $turn['qa']], 403)
                    : redirect()->route('chat.index', ['room' => $room])->with('status', $turn['message']);
            case 'held':
                return $this->heldResponse($request, $user, $conversation);
            case 'persist_failed':
                return $request->wantsJson()
                    ? response()->json(['error' => $turn['message'], 'reason_code' => 'persist_failed'], 500)
                    : redirect()->route('chat.index')->with('status', $turn['message']);
            case 'free_over':
                if (! $request->wantsJson()) {
                    return redirect()->route('chat.index', ['room' => (string) $conversation->persona]);
                }

                return response()->json([
                    'reply' => $turn['reply'],
                    'voice_url' => null,
                    'reply_html' => Markdown::safe($turn['reply']),
                    'balance' => $this->wallet->balance($user),
                    'cost' => 0.0,
                    'degraded' => false,
                    'daily_limit' => ChatPolicy::dailyLimit(),
                    'daily_left' => ChatPolicy::dailyLeft($user),
                    'blocked' => false,
                    'next_cost' => ChatPolicy::costFor($user),
                    'awaiting' => false,
                    'qa' => null,
                    'free_ask' => $turn['free_ask'],
                    'server_now' => now()->getTimestamp(),
                    'suggestions' => [],
                    'ai_suggestions' => [],
                    'kind' => 'offer',
                    'offers' => $turn['offers'],
                    'question' => $turn['question'],
                ]);
        }

        if ($request->wantsJson()) {
            // คืน "สถานะ" กลับไปด้วย ไม่ใช่แค่ข้อความ — หน้าแชทต้องใช้ตัดสินว่า
            // จะโชว์ปุ่มคำถามลัดชุดไหน และต้องยุบแถบปุ่มไหมเมื่อแม่หมอถามกลับ
            // (ก่อนหน้านี้ฝั่ง client เดาเอง ตัวเลขโควตาจึงเคลื่อนจากของจริง)
            return response()->json([
                'reply'       => $turn['reply'],
                'voice_url'   => $turn['voice_url'],
                'reply_html'  => Markdown::safe($turn['reply']),
                'balance'     => $this->wallet->balance($user),
                'cost'        => ChatPolicy::isFreeTurn($turn['kind']) ? 0.0 : $cost,
                'degraded'    => $turn['degraded'],
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => ChatPolicy::dailyLeft($user),
                // 🎁 (2026-07-28) "โควตาหมด" ไม่ได้แปลว่าคุยต่อไม่ได้เสมอไป
                //    ฝั่ง client เคยเดาเองจาก daily_left <= 0 แล้วปิดช่องพิมพ์
                //    → พอเปิดโหมดคิดเงิน คนที่พร้อมจ่ายจะโดนปิดปาก ต้องให้เซิร์ฟเวอร์บอก
                'blocked'     => ChatPolicy::exhausted($user),
                'next_cost'   => ChatPolicy::costFor($user),
                'awaiting'    => $turn['awaiting'],
                // ⏳ นาฬิกาถามต่อ (null = ห้องคุยทั่วไป) — หน้าเว็บนับถอยหลังตามเวลาของเซิร์ฟเวอร์
                'qa'          => $turn['qa'],
                // 🎁 นาฬิกาถามฟรีของห้องที่เริ่มจากไพ่ฟรี (null = ห้องอื่น)
                'free_ask' => $turn['free_ask'],
                'server_now'  => now()->getTimestamp(),
                'suggestions' => $turn['awaiting'] || $turn['offers'] !== [] ? [] : $this->staticChips($request, ChatSuggestions::followUp()),
                // ✨ (2026-11-05) ปุ่มจาก AI — แม่หมอถามกลับอยู่ = ตัวเลือกคำตอบ · ไม่ได้ถาม = คำถามที่น่าถามต่อ
                'ai_suggestions' => $turn['ai_suggestions'],
                // 🌙 (2026-09-15) ลูกค้าขอให้ทำนาย → การ์ดแพ็กเกจเปิดไพ่ (หักเครดิตตอนเปิดไพ่จริง)
                'kind'        => $turn['kind'],
                'offers'      => $turn['offers'],
                'question'    => $turn['question'],
            ]);
        }

        return redirect()->route('chat.index')->with('status', ChatRooms::short($conversation->persona) . 'ตอบกลับแล้ว');
    }

    /**
     * 🧑‍💼 (2026-11-09) ห้องที่แอดมินคุมอยู่ — เก็บข้อความของลูกค้าให้แอดมินอ่าน แล้วจบแค่นั้น
     *
     * ไม่ถาม AI · ไม่คิดเงิน · กดส่งซ้ำ (ข้อความเดิมภายใน 10 วิ) = ไม่เก็บซ้ำ
     */
    private function deliverToAdmin(Request $request, $user, ChatConversation $conversation, string $message)
    {
        ChatTakeover::hold($conversation, $message);

        return $this->heldResponse($request, $user, $conversation);
    }

    /** คำตอบของ /chat/send เมื่อแอดมินคุมห้อง — หน้าห้องไม่โชว์ฟองคำตอบ รอข้อความแอดมินจาก feed */
    private function heldResponse(Request $request, $user, ChatConversation $conversation)
    {
        if (! $request->wantsJson()) {
            return redirect()->route('chat.index', ['room' => $conversation->persona]);
        }

        return response()->json([
            'held'           => true,
            'reply'          => null,
            'reply_html'     => null,
            'kind'           => 'held',
            'cost'           => 0.0,
            'degraded'       => false,
            'balance'        => $this->wallet->balance($user),
            'daily_limit'    => ChatPolicy::dailyLimit(),
            'daily_left'     => ChatPolicy::dailyLeft($user),
            'blocked'        => false,
            'next_cost'      => ChatPolicy::costFor($user),
            'awaiting'       => false,
            'suggestions'    => [],
            'ai_suggestions' => [],
            'offers'         => [],
        ]);
    }

    /**
     * 🧑‍💼 (2026-11-09) หน้าห้องของลูกค้าถามหาข้อความใหม่จากแอดมิน (poll)
     *
     * คืนเฉพาะข้อความที่แอดมินพิมพ์หลัง id ที่หน้าจอมีแล้ว — ข้อความบอท/ลูกค้ามาทาง /chat/send อยู่แล้ว
     * held = แอดมินคุมห้องอยู่ (หน้าห้องเปิดช่องพิมพ์ให้แม้โควตา/เครดิตหมด และถามถี่ขึ้น)
     */
    public function feed(Request $request, ChatConversation $conversation)
    {
        abort_unless($conversation->user_id !== null && $conversation->user_id === $request->user()->id, 403);
        $messages = ChatTakeover::feed($conversation, (int) $request->query('after', 0))
            ->map(fn (ChatMessage $m) => ChatTakeover::payload($m))
            ->values();

        return response()->json([
            'messages' => $messages,
            'held'     => ChatTakeover::isPaused($conversation),
        ]);
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

        $reply = ChatTurn::endQa($conversation)?->content;

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
            $this->turn->openUpstreamSession($user, $conversation, ReadingChatContext::for($reading));
        }

        // Carry the first question into /chat so it auto-sends there (charged
        // by the normal send path). One-shot via session — see index()'s pull().
        if ($question !== '') {
            $request->session()->put('chat_autosend', $question);
        }

        return redirect()->route('chat.index');
    }

    /**
     * 🎁 (2026-11-09) ดูฟรีจบ → เข้าห้องแม่หมอทันที — GET /chat/from-free/{reading}
     *
     * เจ้าของสั่ง: "หลังจากดูฟรี ควรรีบพาไปปิดการขายเพิ่ม อย่าไปหน้าอื่นอีกมันงง ให้ไปหน้าแชทเพื่อเริ่มปิดการขาย ชวนคุยต่อ ทันที"
     *   เดิมดูฟรีเสร็จไปหน้าผลไพ่ที่มีปุ่มพาไปหลายหน้า (เปิดไพ่ชุดเต็ม / เติมเครดิต / คุยกับแม่หมอ) ลูกค้างงแล้วหลุด
     *
     * ห้องที่ได้ = ห้องคุยทั่วไปของแม่หมอ (ไม่ผูก reading_id) — ห้องที่ผูกไพ่มีนาฬิกาถามต่อและซ่อนแพ็กเกจ
     * ซึ่งเป็นของคนที่จ่ายแล้ว ห้องนี้ต้องขายต่อได้ทันที:
     *   1. คำทำนายฟรีเป็นข้อความแรกของห้อง (ลูกค้าอ่านผลในห้องเดียวกับที่คุยต่อ)
     *   2. แม่หมอชวนคุยต่อ + การ์ดแพ็กเกจเปิดไพ่ (offer_topic) — บิลออกเมื่อลูกค้ากดสแกนจ่ายเองเท่านั้น
     *   3. คำถามต่อจากไพ่ใบนี้เป็นปุ่มลัด · ห้อง Thaiprompt ที่เปิดตอนข้อความแรกได้บริบทไพ่ใบนี้ไปด้วย
     * เปิดซ้ำ (รีเฟรช / กดลิงก์เดิม) = ห้องเดิม ไม่วางข้อความซ้ำ
     */
    public function fromFreeReading(Request $request, Reading $reading)
    {
        $user = $request->user();
        if (! $user || $reading->user_id !== $user->id) {
            abort(403);
        }
        // ไม่ใช่ไพ่ฟรี / ยังไม่มีคำทำนาย = ไม่มีอะไรให้วางในห้อง → หน้าผลไพ่ตามเดิม
        if (! FreeReadingRoom::usable($reading)) {
            return redirect()->route('tarot.show', $reading);
        }

        $room = ChatRooms::roomForSpread(TarotSpreads::keyFromType((string) $reading->type));
        $request->session()->put('chat_room', $room);

        try {
            // 💬 (2026-11-12) ห้องเดียวกับที่แอพได้จาก POST /v1/tarot/free
            $conversation = FreeReadingRoom::open($user, $reading);
        } catch (LockTimeoutException) {
            return redirect()->route('tarot.show', $reading);
        }

        $this->rememberRoomToken($request, $room, $conversation->session_token);

        return redirect()->route('chat.index', ['room' => $room]);
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
