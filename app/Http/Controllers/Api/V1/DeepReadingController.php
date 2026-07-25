<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ดูดวงเชิงลึก 39฿ สำหรับแอพ juntra — แพ็กเดียวกับเว็บและบอท FB/LINE
 *
 * กติกาเรื่องเงินเหมือนฝั่งเว็บเป๊ะ (DeepReadingController ของ web):
 *   - หักเครดิตก่อนเรียก AI กันคนยิงฟรี
 *   - ไม่ได้คำทำนายจริง = คืนเครดิตทุกกรณี ห้ามส่งข้อความปลอมแทน
 *     เพราะนี่คือสินค้าที่ลูกค้าจ่ายเงินซื้อโดยเฉพาะ
 */
class DeepReadingController extends Controller
{
    use PreventsDuplicateCharges;

    private const MAX_QUESTIONS = 3;

    public function __construct(
        private FortuneBotClient $bot,
        private WalletService $wallet,
    ) {}

    /** ราคา + ยอดคงเหลือ ให้แอพโชว์ก่อนกดสั่ง */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'cost'          => Pricing::for('deep'),
            'balance'       => (float) $this->wallet->balance($user),
            'max_questions' => self::MAX_QUESTIONS,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'birth_date'  => 'nullable|date_format:Y-m-d|before:today',
            'questions'   => 'required|array|min:1|max:'.self::MAX_QUESTIONS,
            'questions.*' => 'nullable|string|max:500',
        ]);

        $questions = array_values(array_filter(array_map(
            fn ($q) => trim((string) $q),
            $data['questions'],
        ), fn ($q) => $q !== ''));

        if (empty($questions)) {
            return response()->json([
                'message'     => 'กรุณาพิมพ์คำถามอย่างน้อย 1 ข้อ',
                'reason_code' => 'no_question',
            ], 422);
        }

        // กดซ้ำจากเน็ตกระตุก ต้องไม่ถูกคิดเงินสองรอบ (Idempotency-Key header)
        if ($this->guardCharge($request, 'reading') === false) {
            return response()->json([
                'message'     => 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่',
                'reason_code' => 'in_flight',
            ], 409);
        }

        $cost = Pricing::for('deep');
        $tx   = null;

        if ($cost > 0) {
            try {
                $tx = $this->wallet->debit($user, $cost, 'ดูดวงเชิงลึก', [
                    'reference_type' => 'reading',
                    'method'         => 'system',
                ]);
            } catch (InsufficientFundsException $e) {
                return response()->json([
                    'message'     => $e->getMessage(),
                    'reason_code' => 'insufficient_funds',
                    'balance'     => (float) $this->wallet->balance($user),
                    'cost'        => $cost,
                ], 402);
            }
        }

        $reading = $this->bot->deepReading($user, $questions, $data['birth_date'] ?? null, $user->name);

        if (! $reading || empty($reading['reading'])) {
            $this->refundQuietly($tx, 'ระบบคำทำนายเชิงลึกไม่พร้อม');

            return response()->json([
                'message'     => 'ระบบคำทำนายเชิงลึกไม่พร้อมชั่วคราว'
                    .($tx ? ' — เครดิตถูกคืนแล้ว' : ''),
                'reason_code' => 'upstream_unavailable',
                'balance'     => (float) $this->wallet->balance($user),
            ], 503);
        }

        try {
            $row = Reading::create([
                'user_id'       => $user->id,
                'session_token' => (string) Str::uuid(),
                'type'          => 'deep',
                'question'      => $questions[0],
                'payload'       => [
                    'questions'    => $questions,
                    'birth_date'   => $data['birth_date'] ?? null,
                    'cost'         => $cost,
                    'wallet_tx_id' => $tx?->id,
                ],
                'result'      => $reading['reading'],
                'ai_provider' => $reading['ai_provider'] ?? null,
                'ai_model'    => $reading['ai_model'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->refundQuietly($tx, 'บันทึกคำทำนายไม่สำเร็จ');
            Log::error('Mobile deep reading persist failed', ['user_id' => $user->id, 'err' => $e->getMessage()]);

            return response()->json([
                'message'     => 'ระบบขัดข้องชั่วคราว'.($tx ? ' — เครดิตถูกคืนแล้ว' : ''),
                'reason_code' => 'persist_failed',
            ], 500);
        }

        return response()->json(['data' => [
            'id'         => $row->id,
            'reading'    => $row->result,
            'questions'  => $questions,
            'birth_date' => $data['birth_date'] ?? null,
            'cost'       => $cost,
            'balance'    => (float) $this->wallet->balance($user),
            'created_at' => optional($row->created_at)->toIso8601String(),
        ]], 201);
    }

    private function refundQuietly($tx, string $reason): void
    {
        if (! $tx) {
            return;
        }
        try {
            $this->wallet->refund($tx, $reason);
        } catch (\Throwable $e) {
            Log::critical('Mobile deep reading refund FAILED — ต้องคืนเงินด้วยมือ', [
                'tx_id' => $tx->id, 'err' => $e->getMessage(),
            ]);
        }
    }
}
