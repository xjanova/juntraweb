<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Chat\FreeReadingRoom;
use App\Services\Tarot\FreeTarotReading;
use App\Support\FreeReadingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FreeTarotController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $gate = FreeReadingPolicy::gate($request->user());

        return response()->json(['data' => [
            'available' => $gate['allowed'],
            'reason' => $gate['code'] === 'used' ? 'claimed' : $gate['code'],
            'message' => $gate['reason'],
            'max_chars' => FreeReadingPolicy::maxChars(),
        ]]);
    }

    public function store(Request $request, FreeTarotReading $service, HistoryController $history): JsonResponse
    {
        $data = $request->validate(['question' => ['nullable', 'string', 'max:500']]);
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 64) {
            throw \Illuminate\Validation\ValidationException::withMessages(['Idempotency-Key' => 'กรุณาส่งรหัสรายการไม่เกิน 64 ตัวอักษรค่ะ']);
        }
        $outcome = $service->cast($request->user(), $request->ip(), $data['question'] ?? null, 'free:'.$request->user()->id.':'.sha1($key));
        if (! isset($outcome['reading'])) {
            $code = $outcome['code'];
            return response()->json([
                'message' => $outcome['message'] ?? $outcome['reason']
                    ?? FreeReadingPolicy::gate($request->user())['reason'] ?? 'ยังไม่สามารถเปิดไพ่ฟรีได้ค่ะ กรุณาลองใหม่อีกครั้ง',
                'reason_code' => $code,
            ], in_array($code, ['reading_failed', 'deck_unavailable'], true) ? 503 : 409);
        }
        $reading = $outcome['reading'];
        try {
            $conversation = FreeReadingRoom::open($request->user(), $reading);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return response()->json(['message' => 'กำลังเปิดห้องให้ค่ะ กรุณาลองใหม่อีกครั้ง', 'reason_code' => 'in_flight'], 409);
        }

        return response()->json(['data' => [
            'reading' => $history->readingDetail($reading),
            'conversation_id' => $conversation->id,
        ]], ! empty($outcome['replayed']) ? 200 : 201);
    }
}
