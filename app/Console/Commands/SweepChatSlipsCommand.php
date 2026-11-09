<?php

namespace App\Console\Commands;

use App\Services\Wallet\ChatSlipIntake;
use Illuminate\Console\Command;

/**
 * 🧾 (2026-11-10) รับสลิปจากแชทเพจที่บอท Thaiprompt พักไว้ แต่กริ่งมาไม่ถึงเว็บ
 */
class SweepChatSlipsCommand extends Command
{
    protected $signature = 'wallet:sweep-chat-slips';

    protected $description = 'รับสลิปที่ลูกค้าส่งในแชทเพจ (บอทตรวจแล้ว) มาเติมกระเป๋าบนเว็บ';

    public function handle(ChatSlipIntake $intake): int
    {
        $done = $intake->sweep();
        if ($done > 0) {
            $this->info("รับสลิปจากแชทแล้ว {$done} ใบ");
        }

        return self::SUCCESS;
    }
}
