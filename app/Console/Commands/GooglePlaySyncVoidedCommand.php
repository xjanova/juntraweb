<?php

namespace App\Console\Commands;

use App\Services\GooglePlay\GooglePlayBilling;
use Illuminate\Console\Command;

/**
 * ลูกค้าขอคืนเงิน/ยกเลิก/chargeback กับ Google Play → ดึงเครดิตที่เติมไปคืน (Voided Purchases API)
 * ไม่ได้ตั้งค่า service account = ข้ามเงียบ ๆ
 */
class GooglePlaySyncVoidedCommand extends Command
{
    protected $signature = 'googleplay:sync-voided';

    protected $description = 'Claw back credits for Google Play purchases that were refunded or charged back';

    public function handle(GooglePlayBilling $billing): int
    {
        try {
            $n = $billing->syncVoided();
        } catch (\Throwable $e) {
            $this->error('sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->info("voided {$n} purchase(s)");

        return self::SUCCESS;
    }
}
