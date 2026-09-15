<?php

namespace App\Observers;

use App\Models\WalletTransaction;
use App\Services\SmsPayment\AmountReservation;
use Illuminate\Support\Facades\DB;

/**
 * คืนยอดเศษสตางค์ที่จองไว้กับ Thaiprompt ทันทีที่รายการเติมเงินออกจากสถานะ pending
 *
 * ทำไมเป็น observer แทนการแทรกโค้ดในแต่ละเมธอด: เส้นทางที่ทำให้รายการพ้น pending มีหลายทาง
 * (WalletService::confirmTopupAuto / approveTopup / rejectTopup / cancelTopup /
 * rejectTopupFromDevice และ wallet:cleanup-expired-topups) และเพิ่มได้อีกในอนาคต
 * จุดเดียวตรงนี้ครอบทุกทางที่อัปเดตผ่าน Eloquent — ไม่ต้องไปแก้เส้นทางเงินทีละจุด
 *
 * ปลอดภัยต่อเส้นทางเงิน:
 *   - ตรวจการเปลี่ยนสถานะแบบ synchronous (ตอนนี้ getOriginal ยังเป็นค่าเดิม)
 *   - งานคืนยอดรอ DB commit (ถ้า transaction ของเงิน rollback จะไม่คืน)
 *   - ตัวคืนยิงหลังส่ง response และไม่โยน (AmountReservation::releaseFor)
 *
 * ข้อจำกัด: mass update (Model::query()->update()) ไม่ยิง event — ห้ามใช้เปลี่ยนสถานะรายการเติมเงิน
 */
class TopupReservationObserver
{
    public function updated(WalletTransaction $tx): void
    {
        if ($tx->type !== 'topup' || ! $tx->wasChanged('status')) {
            return;
        }
        if ($tx->getOriginal('status') !== 'pending' || $tx->status === 'pending') {
            return;
        }

        $meta = (array) $tx->meta;
        if (empty($meta['amount_reserved'])) {
            return;
        }

        $status = $tx->status === 'success' ? 'used' : 'cancelled';
        $ref    = $tx->reference_code;
        $id     = (int) $tx->id;

        DB::afterCommit(fn () => app(AmountReservation::class)->releaseFor($meta, $ref, $status, $id));
    }
}
