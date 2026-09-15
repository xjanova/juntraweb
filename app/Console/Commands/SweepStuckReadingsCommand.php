<?php

namespace App\Console\Commands;

use App\Services\Tarot\TarotReadingFinisher;
use Illuminate\Console\Command;

/**
 * รายการไพ่ที่แม่หมออ่านเบื้องหลังแล้วค้าง (PHP ถูกรีสตาร์ต/deploy กลางงาน) → ปิดเป็นไม่สำเร็จ + คืนเงิน
 * ไม่มีตัวนี้ = ลูกค้าจ่ายแล้วเห็น "แม่หมอกำลังอ่านไพ่" ไปตลอดกาล
 */
class SweepStuckReadingsCommand extends Command
{
    protected $signature = 'readings:sweep-stuck {--minutes=5 : ค้างนานเกินกี่นาทีถึงถือว่าตาย}';

    protected $description = 'Refund tarot readings whose background interpretation never finished';

    public function handle(TarotReadingFinisher $finisher): int
    {
        $n = $finisher->sweepStuck(max(3, (int) $this->option('minutes')));
        $this->info("refunded {$n} stuck reading(s)");

        return self::SUCCESS;
    }
}
