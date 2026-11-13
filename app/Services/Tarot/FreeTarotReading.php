<?php

namespace App\Services\Tarot;

use App\Models\FreeReadingClaim;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Models\User;
use App\Services\FortuneBot\FortuneAiService;
use App\Support\FreeReadingPolicy;
use App\Support\TarotSpreads;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class FreeTarotReading
{
    public function __construct(private FortuneAiService $ai) {}

    public function cast(User $user, ?string $ip, ?string $question = null, ?string $purchaseKey = null): array
    {
        $lock = Cache::lock('free_reading:'.$user->id, 60);
        if (! $lock->get()) {
            return ['code' => 'in_flight', 'message' => 'แม่หมอกำลังเปิดไพ่ให้อยู่ค่ะ รอสักครู่นะคะ 🔮'];
        }
        try {
            if ($purchaseKey) {
                $prior = Reading::where('user_id', $user->id)->where('payload->free_request_key', $purchaseKey)->first();
                if ($prior && filled($prior->result)) {
                    return ['reading' => $prior, 'replayed' => true];
                }
            }
            $gate = FreeReadingPolicy::gate($user);
            if (! $gate['allowed']) {
                if ($gate['reading_id']) {
                    return ['reading' => Reading::where('user_id', $user->id)->findOrFail($gate['reading_id']), 'replayed' => true];
                }
                // 'reason' not 'message': the web page already prints the gate reason (a flash would show it twice)
                return ['code' => $gate['code'], 'reason' => $gate['reason']];
            }
            $card = TarotCard::where('active', true)->inRandomOrder()->first();
            if (! $card) {
                Log::error('FreeTarot: ไม่มีไพ่ที่ active ในสำรับ');

                return ['code' => 'deck_unavailable', 'message' => 'ขออภัย สำรับไพ่ยังไม่พร้อมค่ะ'];
            }
            $capLock = Cache::lock('free_reading:cap', 10);
            try {
                $capLock->block(5);
                if (FreeReadingPolicy::capReached()) {
                    return ['code' => 'daily_cap'];
                }
                $claim = $this->reserveClaim($user, $ip);
            } catch (LockTimeoutException) {
                return ['code' => 'in_flight', 'message' => 'ตอนนี้มีคนขอดูดวงพร้อมกันเยอะค่ะ 🙏 รอสักครู่แล้วลองใหม่นะคะ'];
            } finally {
                $capLock->release();
            }
            $reading = null;
            try {
                $reading = $this->createReading($user, $card, $question, $purchaseKey);
                $result = $this->ai->freeTarot($reading, $user, FreeReadingPolicy::maxChars());
                if (! $result || blank($result['text'] ?? null)) {
                    throw new \RuntimeException('free_reading_unavailable');
                }
                $payload = $reading->payload ?? [];
                $payload['next_questions'] = array_values(array_slice($result['next_questions'], 0, 2));
                $reading->forceFill([
                    'payload' => $payload, 'result' => $result['text'],
                    'ai_provider' => $result['provider'], 'ai_model' => $result['model'],
                ])->save();
                $claim->forceFill(['reading_id' => $reading->id])->save();
            } catch (\Throwable $e) {
                Log::error('FreeTarot: cast failed; releasing claim', ['user_id' => $user->id, 'err' => $e->getMessage()]);
                $this->releaseClaim($claim, $reading);

                return ['code' => 'reading_failed', 'retry' => true, 'message' => $e->getMessage() === 'free_reading_unavailable'
                    ? 'ตอนนี้แม่หมอยังเปิดไพ่ให้ไม่ได้ค่ะ 🙏 สิทธิ์ดูฟรียังอยู่ครบ ลองใหม่อีกครั้งนะคะ'
                    : 'ระบบขัดข้องชั่วคราวค่ะ 🙏 สิทธิ์ดูฟรียังอยู่ครบ ลองใหม่อีกครั้งนะคะ'];
            }
            return ['reading' => $reading, 'replayed' => false];
        } finally {
            $lock->release();
        }
    }

    /**
     * จองสิทธิ์ก่อนยิง AI — UNIQUE(user_id,batch) เป็นด่านสุดท้ายกันแจกซ้ำ
     *
     * ถ้ามีแถวค้างจากรอบที่โปรเซสตายกลางคัน (reading_id ว่าง + เก่าเกินกำหนด)
     * ให้ใช้แถวเดิมซ้ำ ไม่ใช่ insert ใหม่ (จะชน unique)
     */
    private function reserveClaim($user, ?string $ip): FreeReadingClaim
    {
        $claim = FreeReadingPolicy::claimOf($user);

        if ($claim) {
            // ใช้แถวเดิมซ้ำ (ไม่งั้นชน UNIQUE) — ล้างสถานะล้มเหลวของรอบก่อน
            // และเลื่อน created_at เพื่อให้หน้าต่าง in-flight เริ่มนับใหม่
            $claim->forceFill([
                'ip' => $ip,
                'failed_at' => null,
                'reading_id' => null,
                'created_at' => now(),
            ])->save();

            return $claim;
        }

        return FreeReadingClaim::create([
            'user_id' => $user->id,
            'batch' => FreeReadingPolicy::batch(),
            'ip' => $ip,
        ]);
    }

    /**
     * คืนสิทธิ์ให้ลูกค้า + เก็บกวาด reading ที่ไม่มีคำทำนาย
     *
     * ⚠️ ไม่ลบแถว claim ทิ้ง — ตั้ง failed_at แทน เพราะ:
     *   1. ลูกค้าได้สิทธิ์คืนอยู่แล้ว (claimBlocks มองแถว failed = ไม่กัน)
     *   2. เพดานต้นทุนต่อวันยังนับครั้งนี้อยู่ — รอบที่ AI ตอบช้าจนหมดเวลา
     *      ฝั่งโน้น generate เสร็จและจ่าย token ไปแล้วจริง ถ้าลบแถวทิ้ง
     *      เพดานจะค้างที่ 0 ตอนที่อัปสตรีมมีปัญหา = ตอนที่ต้องเบรกที่สุด
     */
    private function releaseClaim(?FreeReadingClaim $claim, ?Reading $reading): void
    {
        try {
            if ($reading && $reading->exists) {
                $reading->tarotCards()->delete();
                $reading->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('FreeTarot: ลบ reading ที่ล้มเหลวไม่สำเร็จ', ['err' => $e->getMessage()]);
        }

        try {
            $claim?->forceFill(['failed_at' => now(), 'reading_id' => null])->save();
        } catch (\Throwable $e) {
            Log::warning('FreeTarot: คืนสิทธิ์ไม่สำเร็จ', ['err' => $e->getMessage()]);
        }
    }

    /**
     * สร้าง reading ไพ่ใบเดียว — ใช้สเปรด 'single' ที่ลงทะเบียนอยู่แล้ว
     *
     * ต้องเป็น type ที่ TarotSpreads::isTarotType() รู้จัก ไม่งั้นหน้าผลลัพธ์
     * (tarot.show) จะ abort(404) ทันที
     */
    private function createReading($user, TarotCard $card, ?string $question, ?string $purchaseKey): Reading
    {
        $key = 'single';
        $type = TarotSpreads::typeFromKey($key);
        $positions = TarotSpreads::positionLabels($key);

        // ห่อ transaction — ถ้าแถวไพ่ insert ไม่ผ่าน จะได้ไม่เหลือ reading กำพร้า
        // ที่ไม่มีคำทำนายและไม่มีใครลบ (releaseClaim จะไม่เห็นมันด้วยซ้ำ)
        return DB::transaction(function () use ($user, $card, $type, $positions, $question, $purchaseKey) {
            return $this->persistReading($user, $card, $type, $positions, $question, $purchaseKey);
        });
    }

    /** @param  array<int,string>  $positions */
    private function persistReading($user, TarotCard $card, string $type, array $positions, ?string $question, ?string $purchaseKey): Reading
    {
        $reading = Reading::create([
            'user_id' => $user->id,
            'session_token' => Str::uuid()->toString(),
            'type' => $type,
            'question' => $question,
            'payload' => [
                'positions' => $positions,
                'cost' => 0,
                'wallet_tx_id' => null,
                // ธงนี้คือตัวแยก "ของฟรี" ออกจากไพ่ใบเดียวที่ขาย 9฿
                // (ห้ามใช้ราคาเป็นตัวแยก — แอดมินปรับราคา/ปิด billing ได้ตลอด)
                'free' => true,
                'free_request_key' => $purchaseKey,
                'free_batch' => FreeReadingPolicy::batch(),
            ],
        ]);

        $reading->tarotCards()->create([
            'tarot_card_id' => $card->id,
            'position' => 1,
            'position_label' => $positions[0] ?? 'คำตอบของไพ่',
            'reversed' => (bool) random_int(0, 1),
        ]);

        return $reading->load('tarotCards.card');
    }
}
