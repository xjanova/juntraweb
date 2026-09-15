<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Wallet housekeeping.
//  - expire abandoned pending top-ups + drop their slips (hourly)
//  - reconcile every wallet balance against its ledger and log drift (nightly)
Schedule::command('wallet:cleanup-expired-topups')->hourly();
Schedule::command('wallet:reconcile')->dailyAt('00:30');
// คำทำนายไพ่ที่อ่านเบื้องหลังแล้วค้าง (PHP ตายกลางงาน) → คืนเงินภายใน ~5-10 นาที
Schedule::command('readings:sweep-stuck')->everyFiveMinutes()->withoutOverlapping();

// Admin alerts on Telegram (inert until a bot is set up in /admin → แจ้งเตือน Telegram).
//  - watchdog: cron heartbeat, slips waiting for an admin, silent SMS phone, Thaiprompt link, disk
//  - digest: hourly signups + anything the per-category ceilings folded
//  - daily report: yesterday on one card at 09:00 Thai time
Schedule::command('alerts:watchdog')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('alerts:digest')->hourly()->withoutOverlapping()->runInBackground();
Schedule::command('alerts:daily-report')->dailyAt('09:00')->timezone('Asia/Bangkok')->withoutOverlapping()->runInBackground();
