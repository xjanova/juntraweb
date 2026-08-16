<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Http\Controllers\AuspiciousController as WebAuspiciousController;
use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Services\AiOracle;
use App\Services\AuspiciousAdvisor;
use App\Services\AuspiciousScorer;
use App\Services\Numerology;
use App\Services\Wallet\WalletService;
use App\Support\AuspiciousOccasions;
use App\Support\Pricing;
use App\Support\ThaiAstro;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mobile non-tarot paid readings (numerology / auspicious / palmistry) —
 * the JSON parity to the web Numerology/Auspicious/Palmistry controllers.
 * Same debit → create → refund-on-failure money safety. The success payload
 * carries the new Reading id so the app routes to its reading detail screen
 * (GET /v1/history/readings/{id}) which already renders non-tarot results.
 */
class FortuneController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(private WalletService $wallet) {}

    public function numerology(Request $request, Numerology $numerology): JsonResponse
    {
        $data = $request->validate([
            'name'       => 'required|string|max:128',
            'birth_date' => 'required|date|before_or_equal:today|after:1900-01-01',
        ]);

        return $this->withCharge($request, 'numerology', 'ดูเลขศาสตร์: ' . $data['name'], function () use ($numerology, $data, $request) {
            $result = $numerology->analyze($data['name'], $data['birth_date']);
            return Reading::create([
                'user_id'       => $request->user()->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => 'numerology',
                'question'      => $data['name'],
                // เก็บเลขทั้งสามลง payload ด้วย — หน้าผลของเว็บโชว์เป็นตัวเลข
                // ใหญ่สามช่อง (life_path / expression / birth_day) แต่ payload
                // เดิมมีแค่ name+birth_date แอพจึงไม่มีทางแสดงเลขได้เลย
                'payload'       => array_merge($data, [
                    'source'             => 'mobile',
                    'life_path'          => $result['life_path'],
                    'life_path_meaning'  => $result['life_path_meaning'],
                    'expression'         => $result['expression'],
                    'expression_meaning' => $result['expression_meaning'],
                    'birth_day'          => $result['birth_day'],
                    'birth_day_reduced'  => $result['birth_day_reduced'],
                    'birth_day_meaning'  => $result['birth_day_meaning'],
                ]),
                'result'        => $result['narrative'],
            ]);
        });
    }

    /**
     * GET /v1/fortune/occasions — หมวดงานมงคลทั้ง 9 หมวด
     *
     * เว็บกาง `<select>` ให้เลือกหมวดตั้งแต่แรก แต่แอพมีแต่ช่องพิมพ์ข้อความ
     * แล้วปล่อยให้เซิร์ฟเวอร์เดาหมวดจากคีย์เวิร์ด (`AuspiciousOccasions::detect`)
     * พิมพ์ "ฤกษ์เข้าบ้าน" ตกหมวด general ทันทีเพราะคีย์เวิร์ดมีแต่ "ขึ้นบ้าน"
     * — น้ำหนักคนละชุด ได้วันคนละชุดกับเว็บ ทั้งที่เป็นงานเดียวกัน
     */
    public function occasions(): JsonResponse
    {
        return response()->json([
            'data' => AuspiciousOccasions::options(),
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * ฤกษ์ยามบนแอพ — ใช้ scorer/advisor ตัวเดียวกับเว็บเป๊ะ (ดู AuspiciousController)
     * ถ้าแยกสองชุด วันที่แอพกับเว็บแนะนำจะไม่ตรงกัน แล้วลูกค้าจะไม่เชื่อทั้งสองที่
     */
    public function auspicious(Request $request, AuspiciousAdvisor $advisor, AuspiciousScorer $scorer): JsonResponse
    {
        $data = $request->validate([
            'occasion'      => 'required|string|max:128',
            'occasion_type' => 'nullable|string|max:32',
            'from_date'     => 'nullable|date',
            'to_date'       => 'nullable|date|after_or_equal:from_date',
        ]);

        $occasionKey = AuspiciousOccasions::has($data['occasion_type'] ?? null)
            ? $data['occasion_type']
            : AuspiciousOccasions::detect($data['occasion']);

        $today = Carbon::today(ThaiAstro::TZ);
        $from = isset($data['from_date']) ? Carbon::parse($data['from_date'], ThaiAstro::TZ) : $today->copy();
        if ($from->lt($today)) {
            $from = $today->copy();
        }
        $to = isset($data['to_date']) ? Carbon::parse($data['to_date'], ThaiAstro::TZ) : $from->copy()->addDays(59);
        $max = $from->copy()->addDays(AuspiciousScorer::MAX_SCAN_DAYS - 1);
        if ($to->lt($from) || $to->gt($max)) {
            $to = $to->lt($from) ? $from->copy()->addDays(59) : $max;
        }

        // Compute candidates BEFORE charging — empty window = no charge.
        $candidates = $scorer->candidateDays($from, $to, $occasionKey);
        if (empty($candidates)) {
            return response()->json([
                'message'     => 'ช่วงวันที่เลือกไม่มีวันไหนผ่านเกณฑ์ฤกษ์สำหรับงานนี้ กรุณาขยายช่วงวัน — ยังไม่มีการหักเครดิต',
                'reason_code' => 'no_auspicious_day',
            ], 422);
        }

        return $this->withCharge($request, 'auspicious', 'หาฤกษ์ยาม: ' . $data['occasion'], function () use ($advisor, $data, $occasionKey, $from, $to, $candidates, $request) {
            $advice = $advisor->advise($request->user(), $occasionKey, $data['occasion'], $candidates);

            return Reading::create([
                'user_id'       => $request->user()->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => 'auspicious',
                'question'      => $data['occasion'],
                'payload'       => WebAuspiciousController::payload(
                    $occasionKey,
                    $data['occasion'],
                    $from,
                    $to,
                    $candidates,
                    (float) Pricing::for('auspicious'),
                ) + ['source' => 'mobile'],
                'result'        => $advice['text'],
                'ai_provider'   => $advice['ai_provider'],
                'ai_model'      => $advice['ai_model'],
            ]);
        });
    }

    public function palmistry(Request $request, AiOracle $oracle): JsonResponse
    {
        $request->validate([
            'image'    => 'required|image|max:4096',
            'question' => 'nullable|string|max:500',
        ]);

        // Vision-only feature — refuse before charging if the AI isn't set up.
        if (!$oracle->isConfigured()) {
            return response()->json([
                'message'     => 'บริการดูลายมือ AI ยังไม่พร้อมให้บริการในขณะนี้ — ยังไม่มีการหักเครดิต',
                'reason_code' => 'palmistry_unavailable',
            ], 503);
        }

        return $this->withCharge($request, 'palmistry', 'ดูลายมือ AI', function () use ($oracle, $request) {
            // Store AFTER debit so a balance/lock failure leaves no orphan file.
            $path     = $request->file('image')->store('palmistry', 'public');
            $absolute = storage_path('app/public/' . $path);
            $analysis = $oracle->analyzePalmImage($absolute, $request->input('question'));
            return Reading::create([
                'user_id'       => $request->user()->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => 'palmistry',
                'question'      => $request->input('question'),
                'payload'       => ['image_path' => $path, 'source' => 'mobile'],
                'result'        => $analysis,
                'ai_provider'   => $oracle->provider(),
                'ai_model'      => $oracle->model(),
            ]);
        });
    }

    /**
     * Shared idempotency → balance-check → debit → ($build) → refund-on-failure
     * wrapper. $build creates and returns the Reading; any throw refunds.
     */
    private function withCharge(Request $request, string $feature, string $debitLabel, callable $build): JsonResponse
    {
        // ล็อกกันกดซ้ำ — ต้อง release เมื่อจบงานไม่ว่าทางไหน ไม่งั้นผู้ใช้ที่เจอ
        // error แล้วกดลองใหม่ทันทีจะโดน "รายการก่อนหน้ากำลังประมวลผล" ค้าง 90 วิ
        // ทั้งที่ไม่มีอะไรกำลังประมวลผลจริง (และเงินถูกคืนไปแล้ว)
        if (!$this->guardChargeAuto($request, 'reading')) {
            return response()->json(['message' => 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่', 'reason_code' => 'in_flight'], 409);
        }

        return $this->runCharge($request, $feature, $debitLabel, $build);
    }

    /** เนื้อในของ {@see withCharge()} — แยกออกมาเพื่อให้ finally ปล่อยล็อกได้เสมอ */
    private function runCharge(Request $request, string $feature, string $debitLabel, callable $build): JsonResponse
    {
        $user = $request->user();

        $cost    = Pricing::for($feature);
        $balance = $this->wallet->balance($user);
        if ($cost > 0 && bccomp(number_format($balance, 2, '.', ''), number_format($cost, 2, '.', ''), 2) < 0) {
            $this->releaseChargeLock();   // ยังไม่ได้ตัดเงิน กดใหม่ได้ทันที
            return response()->json([
                'message'     => sprintf('เครดิตไม่พอ (ต้องการ %s คงเหลือ %s) — กรุณาเติมเงิน', Pricing::format($cost), Pricing::format($balance)),
                'reason_code' => 'insufficient_funds',
                'balance'     => (float) $balance,
                'cost'        => $cost,
            ], 402);
        }

        try {
            $tx = $cost > 0
                ? $this->wallet->debit($user, $cost, $debitLabel, ['reference_type' => 'reading'])
                : null;
        } catch (InsufficientFundsException $e) {
            $this->releaseChargeLock();   // ยังไม่ได้ตัดเงิน กดใหม่ได้ทันที
            return response()->json([
                'message'     => $e->getMessage() . ' — กรุณาเติมเงิน',
                'reason_code' => 'insufficient_funds',
                'balance'     => (float) $e->balance,
                'cost'        => $cost,
            ], 402);
        }

        try {
            /** @var Reading $reading */
            $reading = $build();
            if ($tx) {
                $tx->update(['reference_id' => $reading->id]);
            }
        } catch (\Throwable $e) {
            // คืนเงินแล้ว = รายการนี้ไม่สำเร็จ ปล่อยล็อกให้ลองใหม่ได้ทันที
            $this->releaseChargeLock();
            Log::error("Mobile {$feature} reading failed after debit — refunding", [
                'user_id' => $user->id, 'tx_id' => $tx?->id, 'err' => $e->getMessage(),
            ]);
            if ($tx) {
                try {
                    $this->wallet->refund($tx, 'ระบบขัดข้องระหว่างทำนาย');
                } catch (\Throwable $refundErr) {
                    Log::critical("Refund FAILED after mobile {$feature} failure", ['tx_id' => $tx->id, 'err' => $refundErr->getMessage()]);
                }
            }
            return response()->json(['message' => 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนแล้ว กรุณาลองใหม่อีกครั้ง', 'reason_code' => 'reading_failed'], 503);
        }

        return response()->json([
            'data' => [
                'id'         => $reading->id,
                'type'       => $reading->type,
                'question'   => $reading->question,
                'result'     => $reading->result,
                'created_at' => optional($reading->created_at)->toIso8601String(),
            ],
            'balance' => (float) $this->wallet->balance($user),
            'cost'    => $cost,
        ], 201);
    }
}
