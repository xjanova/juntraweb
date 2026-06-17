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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            'type'   => 'sometimes|string|in:tarot_three,tarot_celtic,numerology,palmistry,auspicious',
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
            'type'           => 'required|in:tarot_three,tarot_celtic',
            'question'       => 'nullable|string|max:500',
            'picks'          => 'required|array',
            'picks.*.slug'   => 'required|string|max:64',
            'picks.*.reversed' => 'sometimes|boolean',
        ]);

        $needed = $data['type'] === 'tarot_celtic' ? 10 : 3;
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
        if ($this->guardCharge($request, 'reading') === false) {
            return response()->json(['message' => 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่', 'reason_code' => 'in_flight'], 409);
        }
        $cost = Pricing::for($data['type']);
        $balance = $this->wallet->balance($user);

        // Pre-flight: surface 402 cleanly so the mobile app can show the
        // wallet bottom sheet without us having to differentiate "no
        // balance" from "race-lost debit" responses downstream.
        if ($cost > 0 && bccomp(number_format($balance, 2, '.', ''), number_format($cost, 2, '.', ''), 2) < 0) {
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

        // Debit BEFORE we touch the AI, so a failed AI call doesn't leak
        // server resources and a successful one always has a paired tx.
        try {
            $tx = $cost > 0
                ? $this->wallet->debit($user, $cost, 'เปิดไพ่: ' . ($positions[0] ?? '') . ' (' . $data['type'] . ')', [
                    'reference_type' => 'reading',
                    'method'         => 'system',
                ])
                : null;
        } catch (InsufficientFundsException $e) {
            return response()->json([
                'message'     => $e->getMessage() . ' — กรุณาเติมเงินเข้าวอลเลต',
                'reason_code' => 'insufficient_funds',
                'balance'     => (float) $e->balance,
                'cost'        => $cost,
            ], 402);
        }

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
            $reading->result      = $aiResult['text'];
            $reading->ai_provider = $aiResult['provider'];
            $reading->ai_model    = $aiResult['model'];
            $reading->save();

            if ($tx) {
                $tx->update(['reference_id' => $reading->id]);
            }
        } catch (\Throwable $e) {
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
     * Canonical position labels per spread — kept in sync with the web
     * TarotController so the same reading rendered on mobile and web
     * shows identical position names. If you add a new spread type,
     * update {@see \App\Http\Controllers\TarotController} as well.
     */
    private static function positionsFor(string $type): array
    {
        return match ($type) {
            'tarot_three'  => ['อดีต', 'ปัจจุบัน', 'อนาคต'],
            'tarot_celtic' => [
                'สถานการณ์ปัจจุบัน',
                'สิ่งที่ขวางกั้น',
                'รากฐานของเรื่อง',
                'อดีตที่ผ่านมา',
                'เป้าหมาย / สิ่งที่อาจเกิด',
                'อนาคตอันใกล้',
                'ตัวตนของคุณ',
                'สิ่งแวดล้อมรอบตัว',
                'ความหวังและความกลัว',
                'ผลลัพธ์สุดท้าย',
            ],
            default => [],
        };
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
                ];
            })->values();
        }

        return array_merge($this->readingSummary($reading), [
            'question'      => $reading->question,
            'result'        => $reading->result,
            'payload'       => $reading->payload,
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
