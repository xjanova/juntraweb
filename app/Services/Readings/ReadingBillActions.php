<?php

namespace App\Services\Readings;

use App\Jobs\InterpretTarotReading;
use App\Models\Reading;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\ReadingBill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * งานของแอดมินในหน้า "บิลดูดวง" — คืนเงิน / ทำนายใหม่ (แบบเดียวกับปุ่มในหลังบ้านบิลของ Thaiprompt)
 */
class ReadingBillActions
{
    public function __construct(private WalletService $wallet) {}

    /**
     * คืนเงินบิลนี้ (เช่น ลูกค้าร้องเรียน) — คำทำนายยังอยู่ให้ลูกค้าอ่านได้ บิลขึ้นว่า "คืนเงินแล้ว"
     *
     * ล็อกแถวตัดเงินก่อนคืน: แอดมินสองคนกดพร้อมกันต้องคืนได้ครั้งเดียว
     */
    public function refund(Reading $reading, string $reason, ?User $admin): bool
    {
        $txId = data_get($reading->payload, 'wallet_tx_id');
        if (! $txId || $reading->isInProgress() || $reading->isFailed()) {
            return false;
        }

        $done = DB::transaction(function () use ($txId, $reason) {
            $tx = WalletTransaction::whereKey($txId)->lockForUpdate()->first();
            if (! $tx || $tx->type !== 'debit' || $tx->status !== 'success') {
                return false;
            }
            $this->wallet->refund($tx, 'แอดมินคืนเงิน: '.$reason);

            return true;
        });

        if ($done) {
            $reading->forceFill(['payload' => array_merge((array) $reading->payload, [
                'refunded_at' => now()->toIso8601String(),
                'refunded_by' => $admin?->id,
                'refund_reason' => mb_substr($reason, 0, 300),
            ])])->save();
            Log::info('Reading bill refunded by admin', ['reading' => $reading->id, 'admin' => $admin?->id]);
        }

        return $done;
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
