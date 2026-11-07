<?php

namespace App\Services\Deep;

use App\Models\Reading;
use App\Models\WalletTransaction;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Tarot\TarotReadingFinisher;
use Illuminate\Support\Facades\Log;

/**
 * ปิดงานดูดวงเชิงลึก 39฿ ที่แม่หมออ่านเบื้องหลัง — ทีละข้อ เลนเดียวกับบิลดูดวงในแชท FB/LINE
 *
 * ทำไมต้องเบื้องหลัง + ทีละข้อ (2026-11-07):
 *   บอทใน FB ตอบบิล 39฿ ทีละคำถาม (พรอมต์ต่อข้อ ผังดวงจริง ดาวจร กราฟชีวิต ฤกษ์ · ด่านหลุดบท ด่านความครบ)
 *   ใช้เวลารวมหลายนาที แต่คำขอหน้าเว็บรอได้ ~55-60 วิ (Apache) → Thaiprompt ตอบข้อละคำขอ (งบ 50 วิ)
 *   ส่วนที่นี่วนถามทีละข้อหลังส่งหน้าผลให้ลูกค้าแล้ว (InterpretDeepReading::dispatchAfterResponse)
 *
 * กติกาเรื่องเงิน (เหมือนไพ่ยิปซี — ใช้ TarotReadingFinisher::fail ตัวเดียวกัน):
 *   - สถานะเปลี่ยนแบบ atomic pending → working → null/failed (ตัวกวาดรายการค้างคืนเงินซ้ำไม่ได้)
 *   - ได้คำปฏิเสธ/หลุดบท/คำตอบไม่ครบ/คำถามถูกปัด = ไม่มีสินค้าให้ลูกค้า → ล้มทั้งบิล + คืนเงิน
 *   - ทุกข้อที่ตอบเสร็จ ต่ออายุ updated_at (heartbeat) ไม่ให้ตัวกวาด 5 นาทีตัดงานที่ยังเดินอยู่
 */
class DeepReadingFinisher
{
    public function __construct(
        private FortuneBotClient $bot,
        private TarotReadingFinisher $tarot,
    ) {}

    /** อ่านคำทำนายของรายการนี้ให้เสร็จ — เรียกซ้ำได้ (รายการที่ไม่ใช่ pending จะถูกข้าม) */
    public function finish(int $readingId, int $timeout = TarotReadingFinisher::BACKGROUND_TIMEOUT): void
    {
        $claimed = Reading::whereKey($readingId)->where('type', 'deep')->where('status', Reading::STATUS_PENDING)
            ->update(['status' => Reading::STATUS_WORKING, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $reading = Reading::with('user')->find($readingId);
        if (! $reading || ! $reading->user) {
            if ($reading) {
                $this->tarot->fail($reading, 'ไม่พบบัญชีลูกค้า', [Reading::STATUS_WORKING]);
            }

            return;
        }

        $questions = array_values(array_filter(array_map('strval', (array) data_get($reading->payload, 'questions', []))));
        $birth = data_get($reading->payload, 'birth_date');
        $name = $reading->user->name;

        if ($questions === []) {
            $this->tarot->fail($reading, 'ไม่มีคำถาม', [Reading::STATUS_WORKING]);

            return;
        }

        $answers = [];
        $provider = $model = null;
        $whole = null;

        foreach ($questions as $i => $question) {
            $previous = [];
            foreach ($answers as $j => $answer) {
                $previous[] = ['question' => $questions[$j], 'answer' => $answer];
            }

            try {
                $res = $this->bot->deepQuestion($reading->user, $questions, $i, $previous, $birth, $name, $timeout);
            } catch (\Throwable $e) {
                Log::warning('Deep reading: question threw', ['reading' => $readingId, 'index' => $i, 'err' => mb_substr($e->getMessage(), 0, 200)]);
                $this->tarot->fail($reading, 'ระบบขัดข้องระหว่างดูดวงเชิงลึก', [Reading::STATUS_WORKING]);

                return;
            }

            if ($res['status'] !== 'ok') {
                $this->failWith($reading, $res, $i);

                return;
            }

            // ด่านสุดท้ายฝั่งเว็บ — Thaiprompt ตรวจให้แล้ว แต่รุ่นเก่ายังอาจส่งคำปัดมาเป็น 200
            if (TarotReadingFinisher::looksLikeRefusal($res['reading'])) {
                Log::warning('Deep reading: model refused — refunding', [
                    'reading' => $readingId, 'index' => $i, 'model' => $res['ai_model'] ?? null,
                    'text' => mb_substr($res['reading'], 0, 120),
                ]);
                $this->tarot->fail($reading, 'แม่หมอดูดวงไม่สำเร็จ (ระบบทำนายปฏิเสธ)', [Reading::STATUS_WORKING]);

                return;
            }

            $provider = $res['ai_provider'] ?? $provider;
            $model = $res['ai_model'] ?? $model;

            // Thaiprompt รุ่นเก่า (ไม่รู้จัก question_index) ตอบทั้งบิลในครั้งเดียว — ใช้ทั้งก้อน จบ
            if ($res['whole']) {
                $whole = $res['reading'];
                break;
            }

            $answers[$i] = $res['reading'];

            // heartbeat — ถ้าตัวกวาดปิดรายการไปแล้ว (เกิน 5 นาที) หยุดทันที ไม่ยิง AI ต่อให้เปลือง
            $alive = Reading::whereKey($readingId)->where('status', Reading::STATUS_WORKING)->update(['updated_at' => now()]);
            if ($alive !== 1) {
                Log::warning('Deep reading: reading was closed while answering — stopping', ['reading' => $readingId, 'index' => $i]);

                return;
            }
        }

        $text = $whole ?? self::combine($questions, $answers);

        $done = Reading::whereKey($readingId)->where('status', Reading::STATUS_WORKING)->update([
            'result' => $text,
            'ai_provider' => $provider,
            'ai_model' => $model,
            'status' => null,
            'updated_at' => now(),
        ]);
        if ($done !== 1) {
            Log::warning('Deep reading: finished after it was already failed/refunded', ['reading' => $readingId]);

            return;
        }

        if ($txId = data_get($reading->payload, 'wallet_tx_id')) {
            WalletTransaction::whereKey($txId)->update(['reference_id' => $reading->id]);
        }
    }

    /**
     * รวมคำตอบทีละข้อเป็นคำทำนายฉบับเดียว — ข้อเดียวไม่ต้องมีหัวข้อ · หลายข้อใส่หัวคำถามให้อ่านย้อนได้
     *
     * @param  array<int,string>  $questions
     * @param  array<int,string>  $answers
     */
    public static function combine(array $questions, array $answers): string
    {
        if (count($answers) === 1) {
            return trim((string) reset($answers));
        }

        $parts = [];
        foreach ($answers as $i => $answer) {
            $parts[] = '### คำถามข้อที่ '.($i + 1).' · '.trim((string) ($questions[$i] ?? ''))."\n\n".trim($answer);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Thaiprompt ไม่ให้คำทำนาย — ล้มทั้งบิล + คืนเงิน
     *   คำถามที่ส่อสัญญาณวิกฤต (crisis) เก็บข้อความปลอบไว้ให้หน้าผลแสดงแทนข้อความ "ไม่สำเร็จ"
     *
     * @param  array{status:string,crisis?:bool,message?:?string,reason_code?:?string}  $res
     */
    private function failWith(Reading $reading, array $res, int $index): void
    {
        $reason = $res['reason_code'] ?? null;
        Log::warning('Deep reading: Thaiprompt gave no reading — refunding', [
            'reading' => $reading->id, 'index' => $index, 'reason_code' => $reason, 'crisis' => $res['crisis'] ?? false,
        ]);

        if ($reason === 'question_rejected') {
            $payload = (array) $reading->payload;
            $payload['failure'] = array_filter([
                'reason' => 'question_rejected',
                'crisis' => ($res['crisis'] ?? false) === true ? true : null,
                'message' => is_string($res['message'] ?? null) ? mb_substr($res['message'], 0, 1000) : null,
            ], fn ($v) => $v !== null);
            // update ผ่าน query builder ไม่ผ่าน cast → เข้ารหัส JSON เอง
            Reading::whereKey($reading->id)->where('status', Reading::STATUS_WORKING)
                ->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        }

        $this->tarot->fail($reading, match ($reason) {
            'question_rejected' => 'แม่หมอทำนายคำถามนี้ให้ไม่ได้',
            'model_refused', 'incomplete' => 'แม่หมอดูดวงไม่สำเร็จ (คำทำนายไม่สมบูรณ์)',
            'busy' => 'ระบบคำทำนายคิวเต็มชั่วคราว',
            default => 'ระบบคำทำนายเชิงลึกไม่พร้อม',
        }, [Reading::STATUS_WORKING]);
    }
}
