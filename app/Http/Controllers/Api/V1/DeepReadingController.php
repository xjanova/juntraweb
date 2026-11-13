<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Tarot\TarotReadingFinisher;
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

    private const MAX_QUESTIONS = \App\Services\Deep\DeepReadingFinisher::MAX_QUESTIONS;

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
            'mode'        => 'sometimes|in:sync,async',
            'birth_date'  => 'nullable|date_format:Y-m-d|before:today',
            'questions'   => 'required|array|min:1|max:'.self::MAX_QUESTIONS,
            'questions.*' => 'nullable|string|max:500',
        ]);
        // 🪐 (2026-11-07) เว้นช่องวันเกิด = ใช้วันเกิดในโปรไฟล์ — คำทำนายผูกดวงจริงได้เหมือนบิลของบอทแชทเพจ
        $data['birth_date'] = ($data['birth_date'] ?? null) ?: \App\Support\ProfileBirth::date($user);

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

        $async = ($data['mode'] ?? null) === 'async';
        $key = $this->idempotencyToken($request);
        $purchaseKey = $key ? 'deep:'.$user->id.':'.sha1($key) : null;
        if ($async && $purchaseKey) {
            $debit = \App\Models\WalletTransaction::where('user_id', $user->id)->where('idempotency_key', $purchaseKey)->first();
            if ($debit?->status === 'refunded') {
                return response()->json([
                    'message' => 'รายการนี้ไม่สำเร็จและคืนเครดิตแล้วค่ะ กรุณาสร้างรายการใหม่',
                    'reason_code' => 'reading_failed',
                ], 503);
            }
            $prior = Reading::where('user_id', $user->id)->where('type', 'deep')
                ->where('payload->purchase_key', $purchaseKey)->first();
            if ($prior) {
                return $this->asyncResponse($prior, $user);
            }
            if ($debit) {
                return response()->json(['message' => 'รายการก่อนหน้ากำลังประมวลผลค่ะ', 'reason_code' => 'in_flight'], 409);
            }
        }

        // กดซ้ำจากเน็ตกระตุก ต้องไม่ถูกคิดเงินสองรอบ (Idempotency-Key header)
        // ล็อกถูกปล่อยอัตโนมัติเมื่อจบ request (ดู guardChargeAuto) — ของเดิม
        // ปล่อยให้หมดอายุเอง 90 วิ ผู้ใช้ที่เจอ error แล้วกดลองใหม่จึงโดน 409
        // ค้างทั้งที่ไม่มีอะไรกำลังประมวลผลจริง และเงินถูกคืนไปแล้ว
        if (!$this->guardChargeAuto($request, 'reading')) {
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
                    'idempotency_key' => $async ? $purchaseKey : null,
                ]);
            } catch (InsufficientFundsException $e) {
                $this->releaseChargeLock();   // ยังไม่ได้ตัดเงิน
                return response()->json([
                    'message'     => $e->getMessage(),
                    'reason_code' => 'insufficient_funds',
                    'balance'     => (float) $this->wallet->balance($user),
                    'cost'        => $cost,
                ], 402);
            }
        }

        if ($async) {
            try {
                $row = \App\Services\Deep\DeepReadingFinisher::pending($user, $questions, $data['birth_date'] ?? null, $cost, $tx,
                    array_filter(['purchase_key' => $purchaseKey]));
                $tx?->update(['reference_id' => $row->id]);
                \App\Jobs\InterpretDeepReading::dispatchAfterResponse($row->id);

                return $this->asyncResponse($row, $user);
            } catch (\Throwable $e) {
                Log::error('Deep reading (app async) persist/dispatch failed', ['user_id' => $user->id, 'err' => $e->getMessage()]);
                if (isset($row)) {
                    app(TarotReadingFinisher::class)->fail($row, 'ส่งงานดูดวงเชิงลึกไม่สำเร็จ', [Reading::STATUS_PENDING]);
                } else {
                    $this->refundQuietly($tx, 'บันทึกคำทำนายไม่สำเร็จ');
                }
                return response()->json(['message' => 'ระบบขัดข้องชั่วคราวค่ะ กรุณาลองใหม่', 'reason_code' => 'reading_failed'], 503);
            }
        }

        try {
            $reading = $this->bot->deepReading($user, $questions, $data['birth_date'] ?? null, $user->name);
        } catch (\Throwable $e) {
            $reading = null;
        }

        // 🛡️ (2026-11-07) โมเดลตอบคำปฏิเสธ ("ขออภัยค่ะ…ไม่สามารถ…") แทนคำทำนาย = ไม่มีสินค้า → คืนเงิน
        //   Thaiprompt ตีกลับเป็น 503 ให้แล้ว นี่คือด่านสุดท้ายเผื่อ Thaiprompt รุ่นเก่าส่งคำปัดมาเป็น 200
        $refused = $reading && ! empty($reading['reading'])
            && TarotReadingFinisher::looksLikeRefusal((string) $reading['reading']);
        if ($refused) {
            Log::warning('Mobile deep reading: model refused — refunding', [
                'user_id' => $user->id, 'model' => $reading['ai_model'] ?? null,
                'text' => mb_substr((string) $reading['reading'], 0, 120),
            ]);
        }

        if (! $reading || empty($reading['reading']) || $refused) {
            $this->refundQuietly($tx, $refused ? 'แม่หมอดูดวงไม่สำเร็จ (ระบบทำนายปฏิเสธ)' : 'ระบบคำทำนายเชิงลึกไม่พร้อม');

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

    private function asyncResponse(Reading $reading, $user): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $reading->id,
            'status' => $reading->isInProgress() ? $reading->status : ($reading->isFailed() ? 'failed' : 'done'),
            'reading' => $reading->result,
            'questions' => data_get($reading->payload, 'questions', []),
            'birth_date' => data_get($reading->payload, 'birth_date'),
            'cost' => (float) data_get($reading->payload, 'cost', 0),
            'balance' => (float) $this->wallet->balance($user),
            'created_at' => $reading->created_at?->toIso8601String(),
        ]], $reading->isInProgress() ? 202 : ($reading->isFailed() ? 503 : 201));
    }

    private function refundQuietly($tx, string $reason): void
    {
        // คืนเงินแล้ว = รายการไม่สำเร็จ ต้องปล่อยล็อกกันกดซ้ำด้วย ไม่งั้นผู้ใช้
        // กดลองใหม่ทันทีจะได้ 409 ค้างถึง 90 วินาที ทั้งที่เงินคืนไปแล้ว
        $this->releaseChargeLock();

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
