<?php

namespace App\Console\Commands;

use App\Services\GooglePlay\GooglePlayBilling;
use Illuminate\Console\Command;

/**
 * การซื้อผ่าน Google Play ที่เติมเครดิตแล้วแต่ยังบอก Google ไม่สำเร็จ (consume) → ทำซ้ำ
 * ต้องทันภายใน 3 วัน ไม่งั้น Google คืนเงินลูกค้าอัตโนมัติ (แล้ว sync-voided จะดึงเครดิตคืน)
 */
class GooglePlayConsumePendingCommand extends Command
{
    protected $signature = 'googleplay:consume-pending {--limit=50}';

    protected $description = 'Retry consuming Google Play purchases that were credited but not yet consumed';

    public function handle(GooglePlayBilling $billing): int
    {
        $n = $billing->consumePending(max(1, (int) $this->option('limit')));
        $this->info("consumed {$n} purchase(s)");

        return self::SUCCESS;
    }
}
