<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ChatTopupController;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\WalletTransaction;
use App\Services\Chat\ChatOffers;
use App\Services\Chat\ChatTurn;
use App\Services\Chat\LiveRoomStats;
use App\Services\Chat\MaeMorUpstream;
use App\Services\Chat\MicTranscriber;
use App\Services\Chat\ReadingChatContext;
use App\Services\Wallet\WalletService;
use App\Support\AppChannel;
use App\Support\CardTheater;
use App\Support\ChatPolicy;
use App\Support\ChatRooms;
use App\Support\ChatSuggestions;
use App\Support\ChatTakeover;
use App\Support\FreeAskClock;
use App\Support\OracleMedia;
use App\Support\Pricing;
use App\Support\PromptPayQr;
use App\Support\ReadingQaClock;
use App\Support\ReadingRoomLock;
use App\Support\TarotSpreads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Mobile oracle chat — rooms / list / start / send / follow-up clock / staff feed / mic for the juntra Flutter app.
 *
 * 💬 (2026-11-12) web parity: one turn of the conversation is App\Services\Chat\ChatTurn — the SAME code the
 * web /chat/send runs (follow-up clock, free-ask clock, help turns free, offer hold, takeover, debit-after-reply).
 * This controller only runs the gates in the web's order and shapes the JSON (`{data: …}`, never redirects).
 * Keys that app 0.6.0 reads keep their meaning; everything new is added next to them.
 *
 * Play policy: a request with `X-App-Channel: play` never receives PromptPay / QR / bank data (`pending_bill`).
 */
class ChatController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(
        private MaeMorUpstream $upstream,
        private WalletService $wallet,
        private ChatTurn $turn,
    ) {}

    /**
     * 🔮 A1 GET /v1/chat/rooms — ห้องผู้พยากรณ์ทั้งหมด (หน้าเลือกห้องของเว็บ) · ไม่ต้องล็อกอิน
     * ส่ง bearer มา = ได้ `meta.lock` (กำลังทำนายอยู่ห้องไหน — เปลี่ยนห้องไม่ได้จนจบ)
     */
    public function rooms(Request $request): JsonResponse
    {
        $user = $this->optionalUser($request);

        $rooms = array_map(function (array $card) use ($user) {
            $key = $card['key'];
            $theater = CardTheater::room($key);

            return [
                'key'       => $key,
                'name'      => $card['name'],
                'short'     => $card['short'],
                'eyebrow'   => $card['eyebrow'],
                'title'     => $card['title'],
                'quote'     => $card['quote'],
                'tags'      => array_values((array) $card['tags']),
                // สีประจำห้องแบบ hex (ชุดเดียวกับฉากโต๊ะไพ่ของเว็บ) — config ของห้องเก็บเป็นชื่อธีม
                'accent'    => $theater['accent'],
                'open'      => $card['open'],
                'image_url' => $card['image'],
                'product'   => $card['product'],
                // แบบไพ่ที่ห้องนี้ขายตอนนี้ (แบบประจำห้องขึ้นก่อน) — รายการเดียวกับเมนูไพ่บนโต๊ะของเว็บ
                'spreads'   => array_values(array_filter(array_map(
                    fn (array $o) => $o['kind'] === 'tarot' ? $o['spread'] : null,
                    ChatOffers::roomMenu($key, $user),
                ))),
                'media'     => [
                    'still_url'       => $card['image'],
                    // แอพวาดวิดีโอพื้นใส (VP9 alpha) ไม่ได้ — ส่งเฉพาะ mp4 (H.264 ทึบ)
                    'loops'           => array_values(array_column(OracleMedia::loops($key), 'mp4')),
                    'music'           => OracleMedia::music($key),
                    'voice'           => (object) OracleMedia::voice($key),
                    'voice_by_spread' => (object) OracleMedia::voiceBySpread($key),
                    'theater'         => [
                        'plate_url'  => $theater['plate']['src'],
                        'sprite_url' => $theater['sprite'],
                        // ฉากหลังเต็มจอยังไม่มีในเว็บ (โต๊ะ + ผู้พยากรณ์อยู่ใน plate/sprite แล้ว)
                        'bg_url'     => null,
                    ],
                ],
            ];
        }, ChatRooms::cards());

        $lock = $user ? ReadingRoomLock::active($request) : null;

        return response()->json([
            'data' => $rooms,
            'meta' => [
                'chat_cost'     => ChatPolicy::cost(),
                'daily_limit'   => ChatPolicy::dailyLimit() > 0 ? ChatPolicy::dailyLimit() : null,
                'free_daily'    => ChatPolicy::advertisedFree(),
                'lede'          => ChatPolicy::lede() . ' · ' . ChatRooms::PICKER_TAIL,
                'ai_disclosure' => ChatRooms::AI_DISCLOSURE,
                'lock'          => $lock !== null ? $this->lockPayload($lock) : null,
            ],
        ]);
    }

    /** 🔴 A2 GET /v1/chat/live — ตัวเลขสดหน้าห้อง (ชุดเดียวกับเว็บ /chat/live) · ไม่ต้องล็อกอิน */
    public function live(LiveRoomStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->get()])
            ->header('Cache-Control', 'public, max-age=' . LiveRoomStats::REFRESH_SECONDS);
    }

    public function conversations(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 🔮 ห้องของผู้พยากรณ์คนไหน (ไม่ส่ง = ทุกห้อง เหมือนเดิม)
            'room' => ['sometimes', 'nullable', 'string', Rule::in(ChatRooms::keys())],
        ]);

        $rows = ChatConversation::where('user_id', $request->user()->id)
            ->when(! empty($data['room']), fn ($q) => $q->where('persona', $data['room']))
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ChatConversation $c) => [
                'id'            => $c->id,
                'title'         => $c->title,
                // 💬 (2026-09-21) ห้องที่คุยต่อจากคำพยากรณ์ไหน (null = ห้องคุยทั่วไป) — แอพรุ่นเก่าเมินฟิลด์นี้
                'reading_id'    => $c->reading_id,
                'message_count' => (int) $c->messages_count,
                'updated_at'    => optional($c->updated_at)->toIso8601String(),
                'persona'       => ChatRooms::normalize($c->persona),
                'room'          => $this->roomBrief($c->persona),
                'free_reading_id' => $c->free_reading_id,
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

    /**
     * เปิดห้อง — 💬 (2026-09-21) ส่ง `reading_id` มาด้วย = ห้องที่คุยต่อจากคำพยากรณ์ที่จ่ายแล้ว
     *
     * แม่หมอได้ไพ่ทุกใบ + คำพยากรณ์ฉบับเต็มเป็นบริบท (ReadingChatContext) และคำถามเรื่องไพ่ชุดนั้นไม่โดน
     * เปลี่ยนเป็นใบเสนอแพ็กเกจ — เหมือนปุ่ม "ถามแม่หมอ" ของเว็บ · มีห้องของไพ่ชุดนี้อยู่แล้ว = เปิดห้องเดิมต่อ (200)
     * 🔮 (2026-11-12) ส่ง `room` มา = ห้องคุยทั่วไปของผู้พยากรณ์คนนั้น (เว็บ /chat?room=) — คุยต่อห้องล่าสุด (200)
     *    เว้นแต่ส่ง `new: true` · ห้องปิด 422 room_closed · กำลังทำนายอยู่อีกห้อง 423 room_locked
     * แอพรุ่นเก่าไม่ส่งทั้งสองอย่าง → ห้องแม่หมอใหม่แบบเดิมทุกอย่าง (201)
     */
    public function startConversation(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'reading_id' => 'sometimes|nullable|integer|min:1',
            'room'       => ['sometimes', 'nullable', 'string', Rule::in(ChatRooms::keys())],
            'new'        => 'sometimes|boolean',
        ], [
            'room.in' => 'ไม่พบห้องผู้พยากรณ์นี้ค่ะ',
        ]);

        $reading = null;
        if (! empty($data['reading_id'])) {
            // ไพ่ของคนอื่น = 404 เหมือนไม่มีอยู่ (ไม่บอกว่ามีคำพยากรณ์เลขนี้ของใครอยู่)
            $reading = Reading::where('id', $data['reading_id'])->where('user_id', $user->id)->first();
            if (! $reading) {
                return response()->json(['message' => 'ไม่พบคำพยากรณ์นี้', 'reason_code' => 'reading_not_found'], 404);
            }
            if (! TarotSpreads::isTarotType((string) $reading->type)) {
                return response()->json([
                    'message'     => 'คุยต่อกับแม่หมอได้เฉพาะคำพยากรณ์ไพ่ยิปซีค่ะ',
                    'reason_code' => 'reading_not_tarot',
                ], 422);
            }
            if (! ReadingChatContext::usable($reading)) {
                return response()->json([
                    'message'     => 'คำพยากรณ์ชุดนี้ยังไม่พร้อมให้คุยต่อค่ะ (แม่หมอยังอ่านไม่เสร็จ หรืออ่านไม่สำเร็จ)',
                    'reason_code' => 'reading_not_ready',
                ], 422);
            }

            $existing = ChatConversation::where('user_id', $user->id)
                ->where('reading_id', $reading->id)
                ->latest('id')
                ->first();
            if ($existing) {
                return response()->json(['data' => $this->startPayload($request, $existing->load('messages'), $user, $reading)], 200);
            }
        } elseif (! empty($data['room'])) {
            return $this->openRoom($request, $user, $data['room'], (bool) ($data['new'] ?? false));
        }

        // 🔮 (2026-11-03) คุยต่อจากไพ่ = ห้องของผู้พยากรณ์ที่อ่านไพ่ชุดนั้น (ห้องปิดอยู่ → แม่หมอ)
        $persona = $reading ? ChatRooms::roomForSpread(TarotSpreads::keyFromType((string) $reading->type)) : ChatRooms::DEFAULT;

        $token = (string) Str::uuid();
        $convo = ChatConversation::create([
            'user_id'       => $user->id,
            'session_token' => $token,
            'title'         => $reading ? ReadingChatContext::title($reading) : 'สนทนากับแม่หมอ',
            'reading_id'    => $reading?->id,
            'persona'       => $persona,
        ]);

        // Greet via upstream when possible so mobile + web feel identical.
        $greeting = 'สวัสดีค่ะ แม่หมอจันทราอยู่ตรงนี้แล้ว · อยากปรึกษาเรื่องอะไรเป็นพิเศษวันนี้คะ?';
        $start = $this->upstream->start($user, $reading ? ReadingChatContext::for($reading) : null, persona: ChatRooms::upstreamPersona($persona));
        if (!empty($start['greeting'])) {
            $greeting = $start['greeting'];
        }
        if ($reading) {
            // คำทักทายเดียวกับเว็บ (บอกจำนวนใบ + ชื่อแพ็กเกจ) — ไม่ขึ้นกับว่า Thaiprompt รุ่นไหน
            $greeting = ReadingChatContext::greeting($reading, $persona);
        }
        if (!empty($start['session'])) {
            $this->upstream->rememberSession($convo->id, $start['session']);
        }

        ChatMessage::create([
            'chat_conversation_id' => $convo->id,
            'role'    => 'assistant',
            'content' => $greeting,
        ]);

        return response()->json(['data' => $this->startPayload($request, $convo->fresh('messages'), $user, $reading)], 201);
    }

    /**
     * 🔮 ห้องคุยทั่วไปของผู้พยากรณ์คนหนึ่ง — กติกาเดียวกับเว็บ /chat?room= (ChatController::index + conversationFor)
     *
     * ห้องเดิมล่าสุดของผู้พยากรณ์คนนี้ที่ไม่ผูกไพ่ = คุยต่อ (200) · ไม่มี / ส่ง new = ห้องใหม่พร้อมคำทักทายในเครื่อง (201)
     * ห้อง Thaiprompt เปิดเองตอนส่งข้อความแรก (ไม่ถาม AI ตอนเปิดห้อง — เหมือนเว็บ)
     */
    private function openRoom(Request $request, $user, string $room, bool $new): JsonResponse
    {
        if (! ChatRooms::isOpen($room)) {
            return response()->json([
                'message'     => ChatRooms::name($room) . ' ยังไม่เปิดให้คุยนะคะ เร็ว ๆ นี้ค่ะ',
                'reason_code' => 'room_closed',
            ], 422);
        }
        // 🔒 เริ่มทำนายแล้วเปลี่ยนห้องไม่ได้จนจบ/หมดเวลา (ห้องที่ล็อกถูกปิดไปแล้วไม่บังคับ — เหมือนเว็บ)
        $lock = ReadingRoomLock::active($request);
        if ($lock !== null && $lock['room'] !== $room && ChatRooms::isOpen($lock['room'])) {
            return response()->json([
                'message'     => ReadingRoomLock::message($lock),
                'reason_code' => 'room_locked',
                'lock'        => $this->lockPayload($lock),
            ], 423);
        }

        $convo = $new ? null : ChatConversation::where('user_id', $user->id)
            ->where('persona', $room)
            ->whereNull('reading_id')
            ->latest('updated_at')
            ->latest('id')
            ->first();
        $fresh = $convo === null;
        $convo ??= ChatConversation::create([
            'user_id'       => $user->id,
            'session_token' => (string) Str::uuid(),
            'title'         => 'สนทนากับ' . ChatRooms::name($room),
            'persona'       => $room,
        ]);
        // 🔮 (2026-11-03) คำทักทายของห้องประกอบในเครื่อง — ไม่ยิง Thaiprompt ตอนเปิดห้อง
        if ($convo->messages()->doesntExist()) {
            ChatMessage::create([
                'chat_conversation_id' => $convo->id,
                'role'    => 'assistant',
                'content' => ChatRooms::greeting($room),
            ]);
        }

        return response()->json(
            ['data' => $this->startPayload($request, $convo->fresh('messages'), $user, ReadingChatContext::readingOf($convo))],
            $fresh ? 201 : 200,
        );
    }

    /** สถานะครบชุดของการเปิดห้อง (ห้องใหม่ 201 / ห้องเดิม 200) — รูปเดียวกันทุกทาง */
    private function startPayload(Request $request, ChatConversation $convo, $user, ?Reading $reading): array
    {
        return [
            'conversation' => $this->convoPayload($request, $convo, $user),
            'balance'      => (float) $this->wallet->balance($user),
            // 🎁 (2026-07-28) ราคาที่คนนี้ต้องจ่ายข้อความถัดไป (0 = ยังอยู่ในโควตาฟรี)
            'cost'         => ChatPolicy::costFor($user),
            'daily_limit'  => ChatPolicy::dailyLimit(),
            'daily_left'   => ChatPolicy::dailyLeft($user),
            'blocked'      => ChatPolicy::exhausted($user),
            // ปุ่มคำถามลัดชุดเริ่มต้น — ชุดเดียวกับเว็บ (ChatSuggestions) · ห้องของไพ่ = ชุดถามต่อจากไพ่
            'suggestions'  => $reading ? ChatSuggestions::forReading($reading) : ChatSuggestions::starter(),
        ];
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
        $free = FreeAskClock::state($conversation);
        // 🎁 หมดเวลาถามฟรีแล้ว คำชวนเปิดไพ่ไม่ใช่คำถามที่รอคำตอบ (เหมือนหน้าเว็บ)
        $awaiting = ! ($free['over'] ?? false) && $lastAssistant
            ? ChatSuggestions::isAwaitingAnswer((string) $lastAssistant->content)
            : false;

        // 💬 (2026-09-21) ห้องที่คุยต่อจากไพ่ = ชุดถามต่อจากไพ่ (ตรรกะเดียวกับหน้าแชทของเว็บ)
        $reading = ReadingChatContext::readingOf($conversation);

        return response()->json([
            'data' => array_merge($this->convoPayload($request, $conversation, $user), [
                'balance'     => (float) $this->wallet->balance($user),
                'cost'        => ChatPolicy::costFor($user),
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => ChatPolicy::dailyLeft($user),
                'blocked'     => ChatPolicy::exhausted($user),
                'awaiting'    => $awaiting,
                'suggestions' => $awaiting
                    ? []
                    : ($reading
                        ? ChatSuggestions::forReading($reading)
                        : ($hasUserMessage ? ChatSuggestions::followUp() : ChatSuggestions::starter())),
                // ✨ ปุ่มที่ผู้พยากรณ์คิดมาให้ชุดล่าสุด (ห้องของไพ่ฟรีไม่มี — ป้ายไพ่หมวดอื่นแทน) — เหมือนหน้าเว็บ
                'ai_suggestions' => $free !== null ? [] : $this->aiSuggestionsFor($request, $conversation),
            ]),
        ]);
    }

    public function send(Request $request, ChatConversation $conversation): JsonResponse
    {
        $t0 = microtime(true);
        $this->authorizeOwner($request, $conversation);
        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $user = $request->user();
        $room = ChatRooms::normalize($conversation->persona);

        // 🔮 ห้องถูกปิดไปหลังจากเปิดห้องนี้ (เจ้าของปิดแพ็กเกจ/ปิดห้อง) — กติกาเดียวกับเว็บ
        if (! ChatRooms::isOpen($room)) {
            return response()->json([
                'message'     => ChatRooms::name($room) . ' ยังไม่เปิดให้คุยนะคะ เร็ว ๆ นี้ค่ะ',
                'reason_code' => 'room_closed',
            ], 422);
        }

        // 🧑‍💼 (2026-11-09) แอดมินคุมห้องอยู่ — เก็บข้อความให้แอดมิน ไม่ถาม AI ไม่คิดเงิน (กติกาเดียวกับเว็บ)
        //    เช็คก่อนด่านโควตา/เครดิต: ลูกค้าที่เครดิตหมดต้องยังตอบแอดมินได้
        if (ChatTakeover::isPaused($conversation)) {
            ChatTakeover::hold($conversation, $data['message']);

            return $this->heldResponse($request, $user, $conversation);
        }

        // 🎁 ราคาของข้อความนี้ (โควตาฟรี · ถามวิธีใช้ = ฟรี · ห้องของไพ่ฟรี = ฟรี) — อ่านก่อนบันทึกข้อความ
        ['cost' => $cost, 'help' => $helpTurn] = ChatTurn::costFor($user, $conversation, $data['message']);

        // 🆓 เพดานคุยฟรีรายวัน (ChatPolicy ที่เดียวทั้งเว็บและแอพ) — ถามวิธีใช้/แจ้งปัญหาไม่ติดเพดาน
        //    เช็คก่อนจับ idempotency lock — ถ้าเช็คทีหลัง ผู้ใช้ที่ลองส่งซ้ำด้วยคีย์เดิมภายใน 90 วิ
        //    จะได้ 409 แทนที่จะได้เหตุผลจริงว่าโควตาหมด
        if (! $helpTurn && ChatPolicy::exhausted($user)) {
            return response()->json([
                'message'     => ChatPolicy::limitMessage($conversation->persona),
                'reason_code' => 'daily_limit',
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => 0,
            ], 429);
        }

        // Idempotency — block a double-send of the same message (Idempotency-Key header).
        // ล็อกถูกปล่อยเมื่อรอบนี้ไม่สำเร็จ/ไม่คิดเงิน (ดู guardChargeAuto) — ลองใหม่ด้วยคีย์เดิมได้ทันที
        if (!$this->guardChargeAuto($request, 'chat')) {
            return response()->json(['message' => 'ข้อความก่อนหน้ากำลังส่งอยู่ กรุณารอสักครู่', 'reason_code' => 'in_flight'], 409);
        }

        $balance = $this->wallet->balance($user);
        if ($cost > 0 && bccomp(number_format($balance, 2, '.', ''), number_format($cost, 2, '.', ''), 2) < 0) {
            // ยังไม่ตัดเงิน — ปล่อยล็อก ไม่งั้นเติมเงินแล้วกด "ลองส่งใหม่" (คีย์เดิม) ภายใน 90 วิ ได้ 409 แทน
            $this->releaseChargeLock();

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

        // 🔒 ทีละข้อความต่อคน (เว็บ + แอพรวมกัน) — ล็อกเดียวกับเว็บ · ข้อความนี้ยังไม่ถูกส่ง ไม่คิดเงิน
        $inflight = Cache::lock("chat:inflight:u{$user->id}", ChatTurn::BUDGET_SEC + 15);
        if (! $inflight->get()) {
            $this->releaseChargeLock();

            return response()->json([
                'message'     => ChatRooms::selfName($room) . 'กำลังตอบข้อความก่อนหน้าอยู่ค่ะ รอให้ตอบเสร็จแล้วกด "ลองส่งใหม่" ได้เลย (ยังไม่คิดเครดิต)',
                'reason_code' => 'busy_other',
            ], 409);
        }

        try {
            $turn = $this->turn->deliver($request, $user, $conversation, $data['message'], $cost, $t0,
                fn () => $this->releaseChargeLock());
        } finally {
            $inflight->release();
        }

        switch ($turn['status']) {
            case 'qa_over':
                // ⏳ หมดเวลาถามต่อ/กด "พอแล้ว" แล้ว — ไม่ถาม AI ไม่คิดเงิน (เว็บตอบ 403 · API ตอบ 409)
                return response()->json(['message' => $turn['message'], 'reason_code' => 'qa_over', 'qa' => $turn['qa']], 409);
            case 'held':
                return $this->heldResponse($request, $user, $conversation);
            case 'persist_failed':
                return response()->json(['message' => $turn['message'], 'reason_code' => 'persist_failed'], 500);
        }

        $freeOver = $turn['status'] === 'free_over';
        $kind     = $freeOver ? 'offer' : $turn['kind'];
        $offers   = $turn['offers'];
        $awaiting = $freeOver ? false : $turn['awaiting'];

        return response()->json([
            'data' => array_merge([
                // null = ไม่ได้คำตอบจริง (ระบบแน่น) — คำขอโทษไม่ถูกเก็บในห้อง แอพแสดง reply แล้วให้ส่งใหม่
                'message'     => $turn['assistant'] ? $this->msgPayload($turn['assistant']) : null,
                // แอพรุ่นที่ออกไปแล้วแสดงแค่ข้อความ — ต่อรายการแพ็กเกจท้ายคำตอบให้เลือกได้จริง
                // (ข้อความที่เก็บในห้องไม่มีรายการนี้ ประวัติแสดงเป็นการ์ดจาก offer_topic แทน)
                'reply'       => $turn['reply'] . ChatOffers::asText($offers),
                'balance'     => (float) $this->wallet->balance($user),
                'cost'        => $freeOver || ChatPolicy::isFreeTurn($kind) ? 0.0 : $cost,
                // (2026-11-09) เลิกตรวจจับเคสวิกฤตแล้ว — คงคีย์ไว้เป็น false ให้แอพรุ่นเก่าที่อ่านค่านี้ไม่พัง
                'crisis'      => false,
                'crisis_room' => false,
                'degraded'    => $freeOver ? false : $turn['degraded'],
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => ChatPolicy::dailyLeft($user),
                // 🎁 (2026-07-28) โควตาหมด ≠ คุยต่อไม่ได้ — แอพต้องไม่ปิดช่องพิมพ์เอง
                'blocked'     => $freeOver ? false : ChatPolicy::exhausted($user),
                'next_cost'   => ChatPolicy::costFor($user),
                'awaiting'    => $awaiting,
                'suggestions' => $freeOver || $awaiting || $offers !== [] ? [] : $this->staticChips($request, ChatSuggestions::followUp()),
                // ✨ ปุ่มจาก AI — ผู้พยากรณ์ถามกลับอยู่ = ตัวเลือกคำตอบ · ไม่ได้ถาม = คำถามที่น่าถามต่อ
                'ai_suggestions' => $freeOver ? [] : $turn['ai_suggestions'],
                // 🌙 (2026-09-15) ลูกค้าขอให้ทำนาย → แพ็กเกจเปิดไพ่ (หักเครดิตตอนเปิดไพ่จริง ไม่ใช่ตอนนี้)
                'kind'        => $kind,
                'offers'      => $offers,
                'question'    => $turn['question'],
                // ✨ พรีเมียมเสียง — คำตอบในห้องถามต่อพูดออกเสียงของห้อง (URL เต็มของไฟล์เสียง)
                'reply_voice' => ! $freeOver && $turn['voice_url'] ? ['url' => $turn['voice_url']] : null,
            ], $this->roomState($request, $user, $conversation)),
        ]);
    }

    /**
     * ⏳ A6 "พอแล้ว" — จบเวลาถามต่อของไพ่ชุดนี้ก่อนกำหนด (กติกาเดียวกับเว็บ /chat/qa/end)
     * กดซ้ำ = `message: null` (ไม่มีคำลาซ้ำ) · `qa` คือสถานะเต็มเสมอ
     */
    public function endQa(Request $request, ChatConversation $conversation): JsonResponse
    {
        $user = $request->user();
        $reading = ReadingChatContext::readingOf($conversation);
        if ($reading === null || $conversation->user_id !== $user->id) {
            return response()->json(['message' => 'ห้องนี้ไม่ได้จับเวลาถามต่อค่ะ', 'reason_code' => 'no_clock'], 422);
        }

        $farewell = ChatTurn::endQa($conversation);

        return response()->json([
            'data' => [
                'qa'          => ReadingQaClock::state($conversation, $reading),
                'message'     => $farewell ? $this->msgPayload($farewell) : null,
                'server_time' => now()->getTimestamp(),
            ],
        ]);
    }

    /**
     * 🧑‍💼 A7 ข้อความใหม่จากทีมงานหลัง id ที่แอพมีแล้ว (แอดมินคุมห้อง/คุยแทนผู้พยากรณ์) — กติกาเดียวกับเว็บ /chat/feed
     * `meta.held` = แอดมินคุมห้องอยู่ (แอพเปิดช่องพิมพ์ให้แม้โควตา/เครดิตหมด และถามถี่ขึ้น)
     */
    public function feed(Request $request, ChatConversation $conversation): JsonResponse
    {
        abort_unless($conversation->user_id !== null && $conversation->user_id === $request->user()->id, 403);
        $request->validate(['after' => 'sometimes|nullable|integer|min:0']);

        return response()->json([
            'data' => ChatTakeover::feed($conversation, (int) $request->query('after', 0))
                ->map(fn (ChatMessage $m) => $this->msgPayload($m))->values(),
            'meta' => ['held' => ChatTakeover::isPaused($conversation)],
        ]);
    }

    /** 🎙️ A8 ปุ่มไมค์ — ถอดเสียงที่ Thaiprompt (ขนาด/ชนิดไฟล์/ข้อความเดียวกับเว็บ /chat/transcribe) */
    public function transcribe(Request $request): JsonResponse
    {
        $request->validate(MicTranscriber::RULES, MicTranscriber::MESSAGES);
        $r = MicTranscriber::run($request->file('audio'), (int) $request->user()->id, (string) $request->input('room', ''));

        return $r['ok']
            ? response()->json(['data' => ['text' => $r['text']]])
            : response()->json(['message' => $r['message'], 'reason_code' => $r['status'] === 429 ? 'mic_daily_limit' : 'transcribe_failed'], $r['status']);
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    /**
     * สถานะห้องที่แอพต้องวาด — นาฬิกาถามต่อ/ถามฟรี · บิลค้าง (ช่องทางตรงเท่านั้น) · เมนูไพ่บนโต๊ะ
     * กติกาเดียวกับหน้าแชทของเว็บ (ChatController::index): กำลังทำนาย/ระหว่างถามต่อ = ไม่มีเมนู ·
     * บิลค้างขวางเมนูเฉพาะเมื่อเงินในวอลเลตยังไม่พอเปิดแบบไหนเลย
     */
    private function roomState(Request $request, $user, ChatConversation $conversation): array
    {
        $reading = ReadingChatContext::readingOf($conversation);
        $qa      = ReadingQaClock::state($conversation, $reading);
        $free    = $reading === null ? FreeAskClock::state($conversation) : null;
        $hide    = ReadingRoomLock::active($request) !== null || ($qa !== null && ! $qa['over']);
        $menu    = $hide ? [] : ChatOffers::roomMenu((string) $conversation->persona, $user);
        // 🛡 Play: ไม่มีบิลพร้อมเพย์ในแอพ (บิลที่ออกจากเว็บไม่ขวางเมนู ไม่ส่ง QR)
        $bill    = AppChannel::isPlay($request) ? null : ChatTopupController::pendingBill($user);
        $blocks  = $bill !== null && ! $this->turn->walletCovers($user, $menu);
        if ($blocks) {
            $menu = [];
        }

        return [
            'qa'           => $qa,
            'free_ask'     => $free,
            'pending_bill' => $bill !== null ? $this->billPayload($bill, $user, $blocks) : null,
            'menu'         => array_map(fn (array $o) => [
                // ดูดวงเชิงลึก (ห้องแม่หมอ) ไม่มี spread — ใช้ key 'deep'
                'spread'       => $o['spread'] ?? $o['key'],
                'key'          => $o['key'],
                'label'        => $o['label'],
                'cards'        => $o['cards'],
                'price'        => (float) $o['price'],
                // วันที่เปิดได้อีก (ข้อความภาษาไทยชุดเดียวกับการ์ดบนเว็บ) · null = เปิดได้เลย
                'locked_until' => $o['locked_until'],
            ], $menu),
            'server_time'  => now()->getTimestamp(),
        ];
    }

    /** บิลเติมเงินค้างของห้อง — บัญชี/QR ของบิลนั้นเอง (ChatTopupController::billAccount) */
    private function billPayload(WalletTransaction $bill, $user, bool $blocks): array
    {
        $payable = (float) $bill->amount;
        $account = ChatTopupController::billAccount($bill, $user);
        $id = (string) ($account['promptpay_id'] ?? '');

        return [
            'id'             => $bill->id,
            'ref'            => $bill->reference_code,
            'amount'         => (float) ((((array) $bill->meta)['base_amount'] ?? null) ?: $payable),
            'payable_amount' => $payable,
            'qr_payload'     => $id !== '' ? PromptPayQr::payload($id, $payable) : null,
            'expires_at'     => $bill->expires_at?->getTimestamp(),
            // แนบสลิปแล้ว = ลูกค้าโอนมาแล้ว รอตรวจ — ห้ามบอกให้โอนอีก
            'has_slip'       => ! empty($bill->slip_path),
            'blocks'         => $blocks,
        ];
    }

    /** @param  array{room: string, until: \Illuminate\Support\Carbon, reason: string, reading_id: int|null}  $lock */
    private function lockPayload(array $lock): array
    {
        return [
            'room'       => $lock['room'],
            'until'      => $lock['until']->getTimestamp(),
            'reason'     => $lock['reason'],
            'reading_id' => $lock['reading_id'],
            'message'    => ReadingRoomLock::message($lock),
        ];
    }

    private function roomBrief(?string $persona): array
    {
        $key = ChatRooms::normalize($persona);
        $card = collect(ChatRooms::cards())->firstWhere('key', $key) ?? [];

        return [
            'key'       => $key,
            'name'      => ChatRooms::name($key),
            'short'     => ChatRooms::short($key),
            'image_url' => $card['image'] ?? null,
            'accent'    => CardTheater::room($key)['accent'],
        ];
    }

    /** ปุ่มลัดตายตัว — ระหว่างทำนายอยู่ตัดปุ่มที่พาไปเริ่มดูดวงใบใหม่ออก (เหมือนเว็บ) */
    private function staticChips(Request $request, array $chips): array
    {
        return ReadingRoomLock::active($request) !== null ? ChatSuggestions::withoutReadingStarters($chips) : $chips;
    }

    /** ปุ่มจาก AI ชุดล่าสุดของห้อง (cache ของ ChatTurn) — กำลังทำนาย/ระหว่างถามต่อ ตัดปุ่มเริ่มดูดวงใหม่ออก */
    private function aiSuggestionsFor(Request $request, ChatConversation $conversation): array
    {
        $chips = (array) Cache::get(ChatTurn::askKey($conversation->id), []);
        $qa = ReadingQaClock::state($conversation, ReadingChatContext::readingOf($conversation));

        return ReadingRoomLock::active($request) !== null || ($qa !== null && ! $qa['over'])
            ? ChatSuggestions::withoutReadingStarters($chips) : $chips;
    }

    /** ผู้ใช้จาก bearer บนเส้นสาธารณะ (ไม่บังคับล็อกอิน) — ตั้งให้ $request->user() ใช้ได้ด้วย */
    private function optionalUser(Request $request)
    {
        $user = auth('sanctum')->user();
        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $user;
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

    private function convoPayload(Request $request, ChatConversation $convo, $user): array
    {
        return array_merge([
            'id'         => $convo->id,
            'title'      => $convo->title,
            // 💬 (2026-09-21) ห้องที่คุยต่อจากคำพยากรณ์ไหน (null = ห้องคุยทั่วไป)
            'reading_id' => $convo->reading_id,
            'created_at' => optional($convo->created_at)->toIso8601String(),
            'updated_at' => optional($convo->updated_at)->toIso8601String(),
            'messages'   => $convo->messages
                ? $convo->messages->map(fn ($m) => $this->msgPayload($m))->values()
                : [],
            'persona'         => ChatRooms::normalize($convo->persona),
            'room'            => $this->roomBrief($convo->persona),
            // 🎁 ห้องที่เริ่มจากไพ่ฟรี (นาฬิกาถามฟรี) — null = ห้องอื่น
            'free_reading_id' => $convo->free_reading_id,
        ], $this->roomState($request, $user, $convo));
    }

    /**
     * 🧑‍💼 (2026-11-09) ห้องที่แอดมินคุมอยู่ — ไม่มีคำตอบจากบอท แอพดึงข้อความของแอดมินจาก …/feed
     *
     * message = null · reply = '' (แอพไม่ต้องวาดฟองคำตอบ) · held = true
     */
    private function heldResponse(Request $request, $user, ChatConversation $conversation): JsonResponse
    {
        return response()->json([
            'data' => array_merge([
                'message'     => null,
                'reply'       => '',
                'held'        => true,
                'balance'     => (float) $this->wallet->balance($user),
                'cost'        => 0.0,
                'crisis'      => false,
                'crisis_room' => false,
                'degraded'    => false,
                'daily_limit' => ChatPolicy::dailyLimit(),
                'daily_left'  => ChatPolicy::dailyLeft($user),
                'blocked'     => false,
                'next_cost'   => ChatPolicy::costFor($user),
                'awaiting'    => false,
                'suggestions' => [],
                'ai_suggestions' => [],
                'kind'        => 'held',
                'offers'      => [],
                'question'    => null,
                'reply_voice' => null,
            ], $this->roomState($request, $user, $conversation)),
        ]);
    }

    private function msgPayload(ChatMessage $m): array
    {
        $staff = $m->admin_as === ChatMessage::AS_ADMIN && $m->role === 'assistant';

        return [
            'id'          => $m->id,
            'role'        => $m->role,
            // 🧑‍💼 ข้อความที่แอดมินพิมพ์ (role ยังเป็น assistant ให้แอพรุ่นเก่าวาดได้) · ป้าย "แอดมิน" เฉพาะที่เลือกพูดเป็นแอดมิน
            'from_admin'  => $staff,
            // "admin" = ป้ายทีมงาน · null = อื่น ๆ — แอดมินที่ "คุยแทนผู้พยากรณ์" ลูกค้าเห็นเป็นผู้พยากรณ์พูด (เหมือนเว็บ)
            'admin_as'    => $staff ? ChatMessage::AS_ADMIN : null,
            'content'     => $m->content,
            'created_at'  => optional($m->created_at)->toIso8601String(),
            // ข้อความที่แม่หมอยื่นแพ็กเกจเปิดไพ่ — ราคาอ่านสด ณ ตอนนี้ ไม่ใช่ราคาตอนที่คุย
            'offer_topic' => $m->offer_topic,
            'offers'      => $m->offer_topic ? ChatOffers::for($m->offer_topic, request()->user()) : [],
        ];
    }
}
