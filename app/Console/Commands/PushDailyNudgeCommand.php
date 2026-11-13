<?php

namespace App\Console\Commands;

use App\Models\DailyCheckin;
use App\Models\User;
use App\Services\Push\PushNotifier;
use App\Support\DailyLuck;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 🌅 ชวนรับดวงประจำวันผ่าน web push — ช่วงเช้า วันละครั้ง เฉพาะคนที่ยังไม่เช็กอินวันนี้
 *
 * รันทุก 30 นาที (routes/console.php) แต่ส่งเฉพาะช่วง "หมดเวลาเงียบ → เที่ยง" ตามเวลาไทย
 * รอบแรกหลังเวลาเงียบจึงเป็นรอบที่ส่งจริง รอบต่อ ๆ ไปเก็บตกคนที่เพิ่งเปิดแจ้งเตือน
 * เพดานวันละ 1 ครั้งต่อคนบังคับที่ PushNotifier (นับทุกหมวดชวนกลับมารวมกัน)
 */
class PushDailyNudgeCommand extends Command
{
    protected $signature = 'push:daily-nudge';

    protected $description = 'Morning web push inviting members who have not checked in today to open their daily luck';

    public function handle(PushNotifier $notifier): int
    {
        $now = CarbonImmutable::now(PushNotifier::TZ);
        if (! PushNotifier::ready() || ! PushNotifier::typeEnabled('daily')
            || PushNotifier::isQuiet() || (int) $now->format('G') >= PushNotifier::DAILY_UNTIL_HOUR) {
            return self::SUCCESS;
        }

        $today = DailyLuck::today();
        $sent = 0;
        User::query()
            ->tap(fn ($q) => PushNotifier::whereReachable($q, 'id', 'daily')) // เบราว์เซอร์หรือแอป Android
            ->whereNotIn('id', DailyCheckin::select('user_id')->whereDate('day', $today->toDateString()))
            ->with('profile')
            ->chunkById(200, function ($users) use ($notifier, $today, &$sent) {
                foreach ($users as $user) {
                    if ($notifier->dailyNudge($user, $today) === 'sent') {
                        $sent++;
                    }
                }
            });

        $this->info("Daily nudges sent: $sent");

        return self::SUCCESS;
    }
}
