<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Services\FortuneBot\FortuneAiService;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use App\Support\TarotSpreads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Mobile reading history — tarot / numerology / palmistry / auspicious
 * readings the user has paid for, in reverse-chronological order.
 *
 * Wraps the existing `readings` table that all the web controllers
 * persist into; juntra mobile shows the list under "ดูดวงล่าสุด" on the
 * home screen and the History tab.
 *
 * `store()` mirrors the web {@see \App\Http\Controllers\TarotController}
 * flow (debit → create → AI interpret → refund-on-failure) but accepts
 * JSON input from the Flutter app's shuffle-and-reveal sequence and
 * returns the same JSON shape that `show()` does so the mobile app can
 * route straight to the detail screen on a 201.
 */
class HistoryController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(
        private FortuneAiService $ai,
        private WalletService $wallet,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'limit'  => 'sometimes|integer|min:1|max:100',
            'cursor' => 'sometimes|nullable|string',
            'type'   => ['sometimes', 'string', Rule::in([
                ...array_map(fn ($k) => "tarot_{$k}", TarotSpreads::keys()),
                // 'deep' ตกหล่นมาตั้งแต่เปิดขายดูดวงเชิงลึก 39฿ — แอพกรองหมวดนี้
                // ไม่ได้เลย ส่ง type=deep แล้วโดน 422 ทั้งที่รายการถูกบันทึกไว้ครบ
                'numerology', 'palmistry', 'auspicious', 'deep', 'chat',
            ])],
        ]);
        $limit = (int) $request->input('limit', 20);

        $q = Reading::where('user_id', $request->user()->id)
            ->orderByDesc('created_at');
        if ($type = $request->input('type')) {
            $q->where('type', $type);
        }
        if ($cursor = $request->input('cursor')) {
            $q->where('id', '<', (int) $cursor);
        }
        $rows = $q->limit($limit + 1)->get();
        $nextCursor = null;
        if ($rows->count() > $limit) {
            $nextCursor = (string) $rows->last()->id;
            $rows = $rows->take($limit);
        }

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->readingSummary($r))->values(),
            'meta' => ['next_cursor' => $nextCursor],
        ]);
    }

    public function show(Request $request, Reading $reading): JsonResponse
    {
        $user = $request->user();
        $isOwner = $reading->user_id === $user->id;
        $isAdmin = method_exists($user, 'isAdmin') && $user->isAdmin();
        // Same privacy gate as the web `/tarot/result/{id}` route — owner
        // OR admin OR explicitly public-shared.
        if (!$isOwner && !$isAdmin && !($reading->shared_public ?? false)) {
            abort(403);
        }

        return response()->json(['data' => $this->readingDetail($reading)]);
    }

    /**
     * POST /v1/history/readings — persist a mobile tarot reading.
     *
     * Body shape (JSON):
     *   {
     *     "type":     "tarot_three" | "tarot_celtic",
     *     "question": "...",                            // nullable, max 500
     *     "picks": [
     *       { "slug": "the-fool",       "reversed": false },
     *       { "slug": "ace-of-cups",    "reversed": true  },
     *       ...
     *     ]
     *   }
     *
     * Response: 201 with the same envelope as `show()`. The Flutter
     * shuffle screen routes the user to `/reading?id=<id>` on success.
     *
     * Errors:
     *   - 402 insufficient_funds (no debit, no reading created)
     *   - 422 validation (e.g. picks size doesn't match spread)
     *   - 503 ระบบขัดข้องชั่วคราว — debit refunded automatically
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'           => ['required', Rule::in(array_map(fn ($k) => "tarot_{$k}", TarotSpreads::keys()))],
            'question'       => 'nullable|string|max:500',
            // เส้นทางใหม่: กองที่เซิร์ฟเวอร์สับให้ + ตำแหน่งที่ผู้ใช้แตะ
            'deal_token'     => 'sometimes|string|max:64',
            'slots'          => 'sometimes|array',
            'slots.*'        => 'integer|min:0|max:200',
            // เส้นทางเดิม: แอพส่ง slug + reversed มาเอง (คง compat ให้ APK รุ่นเก่า)
            'picks'          => 'sometimes|array',
            'picks.*.slug'   => 'required_with:picks|string|max:64',
            'picks.*.reversed' => 'sometimes|boolean',
        ]);

        $needed = TarotSpreads::cardCount(TarotSpreads::keyFromType($data['type']));

        // แปลง (deal_token, slots) → picks โดยอ่านจากกองที่เซิร์ฟเวอร์สับไว้เอง
        // ค่า reversed มาจากกองนี้เท่านั้น ไม่รับจากไคลเอนต์
        if (!empty($data['deal_token']) && !empty($data['slots'])) {
            $deal = Cache::get(TarotController::dealCacheKey($request->user()->id, $data['deal_token']));
            if (!is_array($deal)) {
                return response()->json([
                    'message'     => 'กองไพ่หมดอายุแล้ว — กรุณาสับไพ่ใหม่อีกครั้งนะคะ',
                    'reason_code' => 'deal_expired',
                ], 422);
            }

            $picks = [];
            foreach ($data['slots'] as $slot) {
                if (!isset($deal[$slot])) {
                    return response()->json([
                        'message'     => 'ตำแหน่งไพ่ไม่ถูกต้อง — กรุณาสับไพ่ใหม่',
                        'reason_code' => 'invalid_slot',
                    ], 422);
                }
                $picks[] = $deal[$slot];
            }
            $data['picks'] = $picks;
        }

        if (empty($data['picks'])) {
            return response()->json([
                'message'     => 'ยังไม่ได้เลือกไพ่',
                'reason_code' => 'no_picks',
            ], 422);
        }

        if (count($data['picks']) !== $needed) {
            return response()->json([
                'message'     => sprintf('สเปรดนี้ต้องเลือก %d ใบ', $needed),
                'reason_code' => 'invalid_pick_count',
            ], 422);
        }

        $positions = self::positionsFor($data['type']);

        // Resolve picks → TarotCard rows in pick order. Slugs are stable
        // across deploys (see TarotCardSeeder); reject the whole reading
        // if any one fails to resolve so we don't half-charge the user.
        $slugs = collect($data['picks'])->pluck('slug')->all();
        $cards = TarotCard::whereIn('slug', $slugs)->where('active', true)->get()->keyBy('slug');
        $orderedPicks = [];
        foreach ($data['picks'] as $i => $p) {
            $card = $cards->get($p['slug']);
            if (!$card) {
                return response()->json([
                    'message'     => 'ไพ่ที่เลือกมีบางใบไม่ถูกต้อง — กรุณาลองใหม่',
                    'reason_code' => 'unknown_card_slug',
                    'unknown_slug' => $p['slug'],
                ], 422);
            }
            $orderedPicks[] = [
                'card'           => $card,
                'reversed'       => (bool) ($p['reversed'] ?? false),
                'position'       => $i + 1,
                'position_label' => $positions[$i] ?? ('ตำแหน่ง ' . ($i + 1)),
            ];
        }

        $user = $request->user();
        // Idempotency — block a double-submit of this reading (Idempotency-Key header).
        // ล็อกถูกปล่อยอัตโนมัติเมื่อจบ request (ดู guardChargeAuto) — ของเดิม
        // ปล่อยให้หมดอายุเอง 90 วิ ผู้ใช้ที่เจอ error แล้วกดลองใหม่จึงโดน 409
        // ค้างทั้งที่ไม่มีอะไรกำลังประมวลผลจริง และเงินถูกคืนไปแล้ว
        if (!$this->guardChargeAuto($request, 'reading')) {
            return response()->json(['message' => 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่', 'reason_code' => 'in_flight'], 409);
        }
        $cost = Pricing::for($data['type']);
        $balance = $this->wallet->balance($user);

        // Pre-flight: surface 402 cleanly so the mobile app can show the
        // wallet bottom sheet without us having to differentiate "no
        // balance" from "race-lost debit" responses downstream.
        if ($cost > 0 && bccomp(number_format($balance, 2, '.', ''), number_format($cost, 2, '.', ''), 2) < 0) {
            $this->releaseChargeLock();   // ยังไม่ได้ตัดเงิน
            return response()->json([
                'message'     => sprintf(
                    'เครดิตไม่พอเปิดไพ่ (ต้องการ %s คงเหลือ %s) — กรุณาเติมเงินเข้าวอลเลต',
                    Pricing::format($cost),
                    Pricing::format($balance),
                ),
                'reason_code' => 'insufficient_funds',
                'balance'     => (float) $balance,
                'cost'        => $cost,
            ], 402);
        }

        // อัปสตรีมพร้อมไหม — เช็คก่อนหักเงิน
        //
        // ถ้าผู้ใช้ยังไม่ผูก Thaiprompt (สมัครในแอพตรง ๆ) FortuneAiService จะ
        // ตกไปประกอบข้อความจากคอลัมน์ความหมายไพ่แทนคำทำนายจริง การเก็บเงิน
        // เต็มราคาแล้วส่งของแบบนั้นให้ = ขายของไม่ตรงปก จึงต้องหยุดตั้งแต่ยัง
        // ไม่ตัดเงิน แล้วบอกทางแก้ (ผูกบัญชี) ให้แอพเด้ง sheet ได้
        if (!$this->ai->isAvailableFor($user)) {
            $this->releaseChargeLock();   // ยังไม่ได้ตัดเงิน
            return response()->json([
                'message'     => 'ตอนนี้แม่หมอยังเชื่อมต่อระบบทำนายไม่ได้ — กรุณาเชื่อมบัญชี Thaiprompt ในหน้าโปรไฟล์ แล้วลองอีกครั้งนะคะ',
                'reason_code' => 'thaiprompt_not_linked',
            ], 409);
        }

        // Debit BEFORE we touch the AI, so a failed AI call doesn't leak
        // server resources and a successful one always has a paired tx.
        try {
            $tx = $cost > 0
                ? $this->wallet->debit($user, $cost, 'เปิดไพ่: ' . (TarotSpreads::nameForType($data['type']) ?? 'ไพ่ยิปซี'), [
                    'reference_type' => 'reading',
                    'method'         => 'system',
                ])
                : null;
        } catch (InsufficientFundsException $e) {
            $this->releaseChargeLock();   // ยังไม่ได้ตัดเงิน
            return response()->json([
                'message'     => $e->getMessage() . ' — กรุณาเติมเงินเข้าวอลเลต',
                'reason_code' => 'insufficient_funds',
                'balance'     => (float) $e->balance,
                'cost'        => $cost,
            ], 402);
        }

        $reading = null;

        try {
            $reading = Reading::create([
                'user_id'       => $user->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => $data['type'],
                'question'      => $data['question'] ?? null,
                'payload'       => [
                    'positions'    => $positions,
                    'cost'         => $cost,
                    'wallet_tx_id' => $tx?->id,
                    'source'       => 'mobile',
                ],
            ]);

            foreach ($orderedPicks as $pick) {
                $reading->tarotCards()->create([
                    'tarot_card_id'  => $pick['card']->id,
                    'position'       => $pick['position'],
                    'position_label' => $pick['position_label'],
                    'reversed'       => $pick['reversed'],
                ]);
            }

            $reading->load('tarotCards.card');
            $aiResult = $this->ai->interpretTarot($reading, $user);

            // 🔴 `source === 'local'` = อัปสตรีมใช้ไม่ได้ (ผู้ใช้ยังไม่ผูก
            // Thaiprompt หรือพูลล่ม) แล้ว FortuneAiService ตกไปประกอบข้อความ
            // จากคอลัมน์ความหมายไพ่ในดาต้าเบสแทน — มันไม่ใช่คำทำนาย
            // เก็บเงินเต็มราคาสำหรับของแบบนี้ไม่ได้ ต้องคืนแล้วให้ลองใหม่
            // เหมือนที่ FreeTarotController ทำ (ที่นั่นจงใจไม่มี fallback เลย)
            if (($aiResult['source'] ?? null) === 'local') {
                throw new \RuntimeException('upstream_unavailable');
            }

            $reading->result      = $aiResult['text'];
            $reading->ai_provider = $aiResult['provider'];
            $reading->ai_model    = $aiResult['model'];
            $reading->save();

            if ($tx) {
                $tx->update(['reference_id' => $reading->id]);
            }
        } catch (\Throwable $e) {
            // คืนเงินแล้ว = รายการนี้ไม่สำเร็จ ปล่อยล็อกให้ลองใหม่ได้ทันที
            $this->releaseChargeLock();
            Log::error('Mobile tarot reading creation failed after debit — refunding', [
                'user_id' => $user->id,
                'tx_id'   => $tx?->id,
                'type'    => $data['type'],
                'err'     => $e->getMessage(),
            ]);
            if ($tx) {
                try {
                    $this->wallet->refund($tx, 'ระบบขัดข้องระหว่างเปิดไพ่ (mobile)');
                } catch (\Throwable $refundErr) {
                    Log::critical('Refund FAILED after mobile reading failure — manual intervention needed', [
                        'tx_id' => $tx->id,
                        'err'   => $refundErr->getMessage(),
                    ]);
                }
            }

            // แถวที่ยังไม่มีคำทำนายต้องไม่ค้างอยู่ในประวัติ ไม่งั้นผู้ใช้เปิดเจอ
            // รายการเปล่าที่ "จ่ายแล้วไม่ได้อะไร" ทั้งที่เงินถูกคืนไปแล้ว
            if ($reading && blank($reading->result)) {
                try {
                    $reading->delete();
                } catch (\Throwable) {
                    // ปล่อยได้ — เงินคืนแล้วซึ่งเป็นส่วนที่สำคัญกว่า
                }
            }

            return response()->json([
                'message'     => 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนเข้าวอลเลตแล้ว กรุณาลองใหม่อีกครั้ง',
                'reason_code' => 'reading_failed',
            ], 503);
        }

        return response()->json([
            'data'    => $this->readingDetail($reading->fresh(['tarotCards.card'])),
            'balance' => (float) $this->wallet->balance($user),
            'cost'    => $cost,
        ], 201);
    }

    /**
     * Canonical position labels per spread — sourced from the shared
     * config/tarot_spreads.php registry so mobile and web always render
     * identical position names for every spread.
     */
    private static function positionsFor(string $type): array
    {
        $key = TarotSpreads::keyFromType($type);
        return $key ? TarotSpreads::positionLabels($key) : [];
    }

    private function readingDetail(Reading $reading): array
    {
        $cards = [];
        // Only tarot readings have associated tarot_reading_cards rows.
        if (str_starts_with((string) $reading->type, 'tarot_')) {
            $cards = $reading->tarotCards()->with('card')->orderBy('position')->get()->map(function ($c) {
                $card = $c->card;
                return [
                    'position'       => $c->position,
                    'position_label' => $c->position_label,
                    'slug'           => $card?->slug,
                    'name_en'        => $card?->name_en,
                    'name_th'        => $card?->name_th,
                    'reversed'       => (bool) $c->reversed,
                    'meaning'        => $c->reversed
                        ? ($card?->reversed_meaning_th ?? '')
                        : ($card?->upright_meaning_th ?? ''),
                    'image_path'     => $card?->image_path,
                    // Resolved real face-image URL (or null → app draws its own
                    // built-in face). Mirrors GET /v1/tarot/cards so the reading
                    // detail shows the same real art the cinematic showed.
                    'image_url'      => $card?->faceImageUrl(),
                ];
            })->values();
        }

        // รูปฝ่ามือที่ลูกค้าอัปโหลด — เก็บเป็น path สัมพัทธ์ใน payload
        // แอพต่อ URL เองไม่ได้ (ไม่รู้โฮสต์ storage) จึงต้องแปลงให้เป็น
        // absolute เหมือนที่ทำกับหน้าไพ่ ไม่งั้นลูกค้าไม่เห็นรูปที่ตัวเองส่งไป
        $payload = (array) ($reading->payload ?? []);
        $imageUrl = null;
        if (!empty($payload['image_path'])) {
            $imageUrl = asset('storage/' . ltrim((string) $payload['image_path'], '/'));
        }

        return array_merge($this->readingSummary($reading), [
            'question'      => $reading->question,
            'result'        => $reading->result,
            'payload'       => $reading->payload,
            'image_url'     => $imageUrl,
            'ai_provider'   => $reading->ai_provider,
            'ai_model'      => $reading->ai_model,
            'shared_public' => (bool) ($reading->shared_public ?? false),
            'cards'         => $cards,
        ]);
    }

    private function readingSummary(Reading $r): array
    {
        return [
            'id'         => $r->id,
            'type'       => $r->type,
            'preview'    => mb_substr((string) ($r->result ?? ''), 0, 140),
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }
}
