<?php

namespace App\Services\Tarot;

use App\Models\Reading;
use App\Models\WalletTransaction;
use App\Services\FortuneBot\FortuneAiService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Log;

/**
 * ปิดงานคำทำนายไพ่ที่แม่หมออ่านเบื้องหลัง — ที่เดียวที่ตัดสินว่ารายการ "สำเร็จ" หรือ "ไม่สำเร็จ + คืนเงิน"
 *
 * ทำไมต้องอ่านเบื้องหลัง (2026-09-15): แพ็กเกจยาวบนเลนทำนาย GPT-5.6 ใช้ ~36 วิ (Celtic) · ~48 วิ (12 เดือน)
 * · ~55 วิ (คุณไสย) ชนเพดาน ~55-60 วิ ของคำขอหน้าเว็บ (Apache) → ถ้ารอในคำขอเดียว ลูกค้าได้เงินคืน
 * แทนคำทำนายเกือบทุกครั้ง ตอนนี้: ตัดเงิน → สร้างรายการ (pending) → ส่งหน้าผลให้ลูกค้าทันที →
 * อ่านต่อหลังส่งหน้า (InterpretTarotReading::dispatchAfterResponse) → หน้าผลถามสถานะจนเสร็จ
 *
 * กติกาเรื่องเงิน:
 *   - ทุกการเปลี่ยนสถานะเป็น atomic (UPDATE … WHERE status IN …) — ใครชนะคนนั้นเป็นเจ้าของผลลัพธ์
 *     งานเบื้องหลังกับตัวกวาดรายการค้าง (sweepStuck) จึงคืนเงินซ้ำกันไม่ได้
 *   - คืนเงินจากแถวธุรกรรมที่โหลดใหม่เสมอ (ของค้างในหน่วยความจำอาจคืนซ้ำ)
 *   - ได้ข้อความประกอบจากความหมายไพ่ (source=local) แทนคำทำนายจริง = ไม่สำเร็จ → คืนเงิน
 */
class TarotReadingFinisher
{
    /** รอ Thaiprompt ได้นานแค่ไหนตอนอ่านเบื้องหลัง (ไม่มีคำขอหน้าเว็บค้างรออยู่แล้ว) */
    public const BACKGROUND_TIMEOUT = 150;

    /** ค้างเกินนี้ถือว่างานเบื้องหลังตายกลางทาง (PHP ถูกรีสตาร์ต/deploy) → คืนเงิน */
    public const STUCK_AFTER_MINUTES = 5;

    public function __construct(
        private FortuneAiService $ai,
        private WalletService $wallet,
    ) {}

    /** อ่านไพ่ของรายการนี้ให้เสร็จ — เรียกซ้ำได้ (รายการที่ไม่ใช่ pending จะถูกข้าม) */
    public function interpret(int $readingId, int $timeout = self::BACKGROUND_TIMEOUT): void
    {
        // จองงาน pending → working (ถ้าคนอื่นจองไปแล้ว หรือเสร็จ/ล้มไปแล้ว = ไม่ใช่งานของเรา)
        $claimed = Reading::whereKey($readingId)->where('status', Reading::STATUS_PENDING)
            ->update(['status' => Reading::STATUS_WORKING, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $reading = Reading::with(['tarotCards.card', 'user'])->find($readingId);
        if (! $reading) {
            return;
        }

        try {
            $result = $this->ai->interpretTarot($reading, $reading->user, $timeout);
        } catch (\Throwable $e) {
            Log::warning('Tarot reading: interpretation threw', ['reading' => $readingId, 'err' => mb_substr($e->getMessage(), 0, 200)]);
            $this->fail($reading, 'ระบบขัดข้องระหว่างอ่านไพ่', [Reading::STATUS_WORKING]);

            return;
        }

        $paid = $this->debitOf($reading) !== null;
        if (trim((string) ($result['text'] ?? '')) === '' || ($paid && ($result['source'] ?? null) === 'local')) {
            $this->fail($reading, 'แม่หมออ่านไพ่ไม่สำเร็จ (ระบบทำนายไม่ตอบ)', [Reading::STATUS_WORKING]);

            return;
        }

        $done = Reading::whereKey($readingId)->where('status', Reading::STATUS_WORKING)->update([
            'result' => $result['text'],
            'ai_provider' => $result['provider'] ?? null,
            'ai_model' => $result['model'] ?? null,
            'status' => null,
            'updated_at' => now(),
        ]);
        if ($done !== 1) {
            // ตัวกวาดรายการค้างตัดสินไปแล้วว่าล้ม (และคืนเงินแล้ว) — ห้ามเขียนทับ
            Log::warning('Tarot reading: finished after it was already failed/refunded', ['reading' => $readingId]);

            return;
        }

        if ($tx = $this->debitOf($reading)) {
            $tx->update(['reference_id' => $reading->id]);
        }
    }

    /**
     * ปิดรายการเป็น "ไม่สำเร็จ" และคืนเงิน — เฉพาะเมื่อชนะการเปลี่ยนสถานะจาก $from
     *
     * @param  array<int,string>  $from
     * @return bool true = เราเป็นคนปิดรายการนี้
     */
    public function fail(Reading $reading, string $reason, array $from): bool
    {
        $won = Reading::whereKey($reading->id)->whereIn('status', $from)
            ->update(['status' => Reading::STATUS_FAILED, 'updated_at' => now()]);
        if ($won !== 1) {
            return false;
        }

        $tx = $this->debitOf($reading);
        if ($tx && $tx->status === 'success') {
            try {
                $this->wallet->refund($tx, $reason);
            } catch (\Throwable $e) {
                Log::critical('Tarot reading refund FAILED — manual intervention needed', [
                    'reading' => $reading->id, 'tx_id' => $tx->id, 'err' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /** รายการที่ค้าง pending/working นานเกินควร → ปิดเป็นไม่สำเร็จ + คืนเงิน */
    public function sweepStuck(int $minutes = self::STUCK_AFTER_MINUTES): int
    {
        $n = 0;
        Reading::whereIn('status', [Reading::STATUS_PENDING, Reading::STATUS_WORKING])
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->orderBy('id')
            ->each(function (Reading $r) use (&$n) {
                if ($this->fail($r, 'แม่หมออ่านไพ่ไม่เสร็จในเวลา', [Reading::STATUS_PENDING, Reading::STATUS_WORKING])) {
                    $n++;
                    Log::warning('Tarot reading: stuck reading failed and refunded by the sweeper', ['reading' => $r->id]);
                }
            });

        return $n;
    }

    /** แถวตัดเงินของรายการนี้ (โหลดใหม่จาก DB เสมอ) */
    private function debitOf(Reading $reading): ?WalletTransaction
    {
        $id = data_get($reading->payload, 'wallet_tx_id');

        return $id ? WalletTransaction::find($id) : null;
    }
}
