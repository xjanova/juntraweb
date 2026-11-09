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
// 🧾 สลิปจากแชทเพจที่บอทพักไว้แต่กริ่งมาไม่ถึง — รับมาเติมกระเป๋า (ปกติกริ่งทำให้เสร็จทันที)
Schedule::command('wallet:sweep-chat-slips')->everyMinute()->withoutOverlapping(5)->runInBackground();
// คำทำนายไพ่ที่อ่านเบื้องหลังแล้วค้าง (PHP ตายกลางงาน) → คืนเงินภายใน ~5-10 นาที
Schedule::command('readings:sweep-stuck')->everyFiveMinutes()->withoutOverlapping();
// 🌅 ดวงรายวัน 12 ราศีจากดาวจริง — เขียนไว้ก่อนคนแรกของวันเปิด (หน้าเว็บรอ AI ได้ไม่ถึง 60 วิ)
Schedule::command('horoscope:warm')->dailyAt('00:05')->timezone('Asia/Bangkok')->withoutOverlapping(30)->runInBackground();

// 🌙 บิลคำทำนายเว็บ+แอพ → ผังแม่หมอแจกค่าแนะนำ (คืนเงินลูกค้า = ดึงค่าแนะนำคืน)
//   ส่งบิลที่เกิน 15 นาที = พ้นรอบ sweep-stuck (5 นาที) ที่คืนเงินไพ่ที่อ่านไม่สำเร็จ
Schedule::command('affiliate:sync-bills')->everyMinute()->withoutOverlapping(10)->runInBackground();

// Admin alerts on Telegram (inert until a bot is set up in /admin → แจ้งเตือน Telegram).
//  - watchdog: cron heartbeat, slips waiting for an admin, silent SMS phone, Thaiprompt link, disk
//  - digest: hourly signups + anything the per-category ceilings folded
//  - daily report: yesterday on one card at 09:00 Thai time
Schedule::command('alerts:watchdog')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('alerts:digest')->hourly()->withoutOverlapping()->runInBackground();
Schedule::command('alerts:daily-report')->dailyAt('09:00')->timezone('Asia/Bangkok')->withoutOverlapping()->runInBackground();

// 🛒 Google Play Billing (แอพช่อง Play) — ไม่ได้ตั้ง service account = ทั้งสองตัวไม่ทำอะไร
//  - consume ที่พลาดไป (ต้องทันภายใน 3 วัน ไม่งั้น Google คืนเงินลูกค้าอัตโนมัติ)
//  - ลูกค้าขอคืนเงิน/chargeback กับ Google → ดึงเครดิตคืน
Schedule::command('googleplay:consume-pending')->everyTenMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('googleplay:sync-voided')->hourly()->withoutOverlapping()->runInBackground();
