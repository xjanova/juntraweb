<?php

namespace App\Services\Readings;

use App\Exceptions\WalletAdminException;
use App\Jobs\InterpretTarotReading;
use App\Models\Reading;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\ReadingBill;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * งานของแอดมินในหน้า "บิลดูดวง" — คืนเงิน / ทำนายใหม่ (แบบเดียวกับปุ่มในหลังบ้านบิลของ Thaiprompt)
 */
class ReadingBillActions
{
    use DetectsConcurrencyErrors;

    public function __construct(private WalletService $wallet) {}

    /**
     * คืนเงินบิลนี้ (เช่น ลูกค้าร้องเรียน) — คำทำนายยังอยู่ให้ลูกค้าอ่านได้ บิลขึ้นว่า "คืนเงินแล้ว"
     *
     * คืนเฉพาะส่วนที่ยังไม่ได้คืน (แอดมินอาจคืนบางส่วนไปแล้วจากหน้ารายการวอลเลต) —
     * WalletService::refund() lock วอลเลตก่อนแล้วค่อย lock แถวตัดเงิน (ลำดับเดียวกับทุกเส้นทางเงิน)
     * และอ่านสถานะใหม่หลัง lock: แอดมินสองคนกดพร้อมกันต้องคืนได้ครั้งเดียว
     *
     * @throws WalletAdminException  ชนกับรายการเงินอื่นของลูกค้าคนนี้ (deadlock / รอ lock นานเกิน) — ข้อความไทยแสดงให้แอดมินได้
     */
    public function refund(Reading $reading, string $reason, ?User $admin): bool
    {
        $txId = data_get($reading->payload, 'wallet_tx_id');
        if (! $txId || $reading->isInProgress() || $reading->isFailed()) {
            return false;
        }
        $tx = WalletTransaction::find($txId);
        if (! $tx || $tx->type !== 'debit') {
            return false;
        }

        try {
            $refund = $this->wallet->refund($tx, 'แอดมินคืนเงิน: '.$reason);
        } catch (QueryException $e) {
            if ($this->causedByConcurrencyError($e)) {
                Log::warning('Reading bill refund hit a lock conflict', ['reading' => $reading->id, 'err' => $e->getMessage()]);
                throw new WalletAdminException('มีรายการเงินอื่นของลูกค้าคนนี้กำลังทำอยู่ — ยังไม่ได้คืนเงิน กรุณาลองใหม่อีกครั้งในอีกสักครู่');
            }
            throw $e;
        } catch (\RuntimeException) {
            // คืนครบไปแล้ว / ไม่ใช่รายการตัดเงินที่สำเร็จ
            return false;
        }

        $reading->forceFill(['payload' => array_merge((array) $reading->payload, [
            'refunded_at' => now()->toIso8601String(),
            'refunded_by' => $admin?->id,
            'refund_reason' => mb_substr($reason, 0, 300),
            'refund_amount' => (string) $refund->amount,
        ])])->save();
        Log::info('Reading bill refunded by admin', ['reading' => $reading->id, 'admin' => $admin?->id, 'amount' => (string) $refund->amount]);

        return true;
    }

    /**
     * ให้แม่หมออ่านไพ่ชุดเดิมใหม่ (ไพ่ใบเดิม ตำแหน่งเดิม) — ไม่หักเงินเพิ่ม
     *
     * บิลที่คืนเงินไปแล้ว (ไม่สำเร็จ/แอดมินคืน) ทำนายใหม่ได้เป็นน้ำใจ — บิลยังขึ้น "คืนเงินแล้ว" ไม่กลายเป็น "สำเร็จ"
     */
    public function retry(Reading $reading): bool
    {
        if (! ReadingBill::retryable($reading)) {
            return false;
        }

        $debit = ReadingBill::debit($reading);
        if ($debit && $debit->status === 'refunded' && data_get($reading->payload, 'refunded_at') === null) {
            $reading->forceFill(['payload' => array_merge((array) $reading->payload, [
                'refunded_at' => optional($debit->updated_at)->toIso8601String() ?? now()->toIso8601String(),
                'refund_reason' => 'คืนเงินอัตโนมัติก่อนแอดมินสั่งทำนายใหม่',
            ])])->save();
        }

        $claimed = Reading::whereKey($reading->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', [Reading::STATUS_PENDING, Reading::STATUS_WORKING]))
            ->update(['status' => Reading::STATUS_PENDING, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }

        InterpretTarotReading::dispatchAfterResponse($reading->id);

        return true;
    }
}
