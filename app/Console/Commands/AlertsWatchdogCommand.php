<?php

namespace App\Console\Commands;

use App\Models\SmsCheckerDevice;
use App\Models\WalletTransaction;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use App\Support\Alerts\Redact;
use App\Support\Alerts\Reports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The quiet failures nothing else reports, checked every 5 minutes:
 *
 *  - the scheduler's own heartbeat — read back by [App\Http\Middleware\WatchScheduler] on web
 *    requests, because a dead cron cannot report itself;
 *  - slips nobody has looked at — a customer who paid and is still waiting is the most expensive
 *    thing this site can do wrong;
 *  - the SMS Checker phone going silent while customers are waiting to be auto-credited;
 *  - the link to Thaiprompt (slip checks + unique amounts) being refused;
 *  - the database queue backing up, and queued jobs that failed for good;
 *  - disk space — a full disk takes the whole site down, database included.
 *
 *   php artisan alerts:watchdog
 */
class AlertsWatchdogCommand extends Command
{
    protected $signature = 'alerts:watchdog';

    protected $description = 'Scheduler heartbeat + waiting slips + SMS phone + Thaiprompt link + queue + disk (alerts the admin on Telegram).';

    public function handle(): int
    {
        Cache::forever('scheduler:heartbeat', now()->timestamp);

        $checks = [];
        if (AdminAlerts::wants('money')) {
            $checks[] = 'checkWaitingSlips';
            $checks[] = 'checkSmsPhone';
        }
        if (AdminAlerts::wants('system')) {
            array_push($checks, 'checkThaiprompt', 'checkQueue', 'checkFailedJobs', 'checkDisk');
        }

        foreach ($checks as $check) {
            try {
                $this->{$check}();
            } catch (Throwable $e) {
                // One broken check must not silence the others.
                $this->warn("{$check}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /** Slips sitting in "รอแอดมินตรวจ" for 20+ minutes — a reminder, at most every 2 hours. */
    private function checkWaitingSlips(): void
    {
        $rows = WalletTransaction::where('type', 'topup')->where('status', 'pending')
            ->whereNotNull('slip_path')
            ->where('updated_at', '<=', now()->subMinutes(20))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('updated_at')
            ->get(['id', 'amount', 'updated_at']);
        $this->line('waiting slips: ' . $rows->count());
        if ($rows->isEmpty()) {
            AdminAlerts::forgetThrottle('slips-waiting');

            return;
        }

        $oldest = (int) $rows->first()->updated_at->diffInMinutes(now());
        AdminAlerts::send(new Alert(
            key: 'slips-waiting',
            level: Alert::WARNING,
            title: 'มีสลิปรอแอดมินตรวจ ' . $rows->count() . ' รายการ',
            body: 'ลูกค้าโอนเงินแล้วแต่ระบบยืนยันอัตโนมัติไม่ได้ — เปิดหน้าธุรกรรมวอลเลตแล้วกดอนุมัติ/ปฏิเสธ',
            facts: [
                'รอนานสุด' => $oldest >= 120 ? intdiv($oldest, 60) . ' ชม.' : $oldest . ' นาที',
                'ยอดรวม' => '฿' . number_format((float) $rows->sum(fn ($r) => (float) $r->amount), 2),
            ],
            url: url('/admin/wallet-transactions?tableFilters[status][value]=pending'),
            urlLabel: 'เปิดรายการที่รอตรวจ',
            category: 'money',
        ), 120);
    }

    /**
     * The phone that reads the bank SMS has stopped talking to us while customers are waiting for an
     * automatic credit — every one of them will end up asking an admin instead.
     */
    private function checkSmsPhone(): void
    {
        if (! config('smschecker.enabled') || ! Schema::hasTable('sms_checker_devices')) {
            return;
        }
        $devices = SmsCheckerDevice::where('status', 'active')->get(['device_name', 'last_active_at']);
        if ($devices->isEmpty()) {
            return;
        }
        $waiting = WalletTransaction::where('type', 'topup')->where('status', 'pending')
            ->where('method', 'promptpay')->where('created_at', '<=', now()->subMinutes(15))
            ->where('created_at', '>=', now()->subHours(6))->count();
        $freshest = $devices->max('last_active_at');
        $silentFor = $freshest ? (int) $freshest->diffInMinutes(now()) : null;
        $this->line('sms phone: silent ' . ($silentFor ?? '-') . ' min, waiting top-ups ' . $waiting);

        if ($waiting === 0 || ($silentFor !== null && $silentFor < 60)) {
            AdminAlerts::forgetThrottle('sms-phone-silent');

            return;
        }

        AdminAlerts::send(new Alert(
            key: 'sms-phone-silent',
            level: Alert::WARNING,
            title: 'มือถือ SMS Checker เงียบไป ' . ($silentFor === null ? '(ยังไม่เคยเชื่อมต่อ)' : ($silentFor >= 120 ? intdiv($silentFor, 60) . ' ชม.' : $silentFor . ' นาที')),
            body: "มีลูกค้ารอเครดิตอัตโนมัติ {$waiting} รายการ แต่แอพ SMS Checker ไม่ได้ส่งอะไรมาเลย\n"
                . 'ตรวจว่ามือถือเปิดอยู่ มีเน็ต และแอพยังทำงาน (ไม่ถูกปิดโหมดประหยัดแบต)',
            facts: ['รอเครดิต' => $waiting . ' รายการ', 'อุปกรณ์' => $devices->count() . ' เครื่อง'],
            url: url('/admin/sms-checker-devices'),
            urlLabel: 'ดูอุปกรณ์ SMS Checker',
            category: 'money',
        ), 180);
    }

    /** The server-to-server link is how slips are cross-checked with แม่หมอ — say when it breaks. */
    private function checkThaiprompt(): void
    {
        $client = app(JuntraServerClient::class);
        if (! $client->isConfigured()) {
            return;
        }
        $ok = $client->ping();
        $this->line('thaiprompt link: ' . ($ok ? 'ok' : 'FAILED'));
        if ($ok) {
            AdminAlerts::forgetThrottle('thaiprompt-link');
            Cache::forget('watchdog:thaiprompt:fails');

            return;
        }
        // Two misses in a row (10 min) before anyone's phone buzzes: a deploy on the other side
        // takes a minute or two and is not news.
        $fails = (int) Cache::increment('watchdog:thaiprompt:fails');
        if ($fails < 2) {
            return;
        }

        AdminAlerts::send(new Alert(
            key: 'thaiprompt-link',
            level: Alert::WARNING,
            title: 'เว็บจันทราเชื่อมต่อ Thaiprompt ไม่ได้',
            body: "ช่วงนี้ตรวจสลิปกับแม่หมอ/จองยอดไม่ชนกันไม่ได้ — สลิปใหม่จะไปรอแอดมินตรวจแทน (ไม่มีใครถูกตัดเงิน)\n"
                . 'ตรวจ Client ID/Secret ในหน้า เชื่อมต่อ Thaiprompt และสถานะเซิร์ฟเวอร์ Thaiprompt',
            facts: ['ล้มเหลวติดกัน' => $fails . ' รอบ'],
            url: url('/admin'),
            urlLabel: 'เปิดหน้าแอดมิน',
            category: 'system',
        ), 180);
    }

    private function checkQueue(): void
    {
        [$waiting, $oldest] = Reports::queueBacklog();
        $this->line("queue: {$waiting} waiting, oldest " . ($oldest ?? '-') . ' min');
        if ($oldest === null || $oldest < 15) {
            AdminAlerts::forgetThrottle('queue-stuck');

            return;
        }

        AdminAlerts::send(new Alert(
            key: 'queue-stuck',
            level: $oldest >= 60 ? Alert::CRITICAL : Alert::WARNING,
            title: 'งานในคิวไม่ขยับมา ' . ($oldest >= 120 ? intdiv($oldest, 60) . ' ชม.' : $oldest . ' นาที'),
            body: 'queue worker น่าจะหยุดทำงาน — งานที่รออยู่จะค้างจนกว่าจะมีคนสั่งรันใหม่'
                . "\nรันบนเซิร์ฟเวอร์: php artisan queue:work",
            facts: ['งานที่รอ' => number_format($waiting), 'ไม่ขยับมา' => $oldest . ' นาที', 'คิว' => (string) config('queue.default')],
            url: url('/admin'),
            urlLabel: 'เปิดหน้าแอดมิน',
            category: 'system',
        ), 120);
    }

    /** Jobs that used up their retries since the last look, grouped by job type. */
    private function checkFailedJobs(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }
        $lastSeen = Cache::get('watchdog:failed_jobs:last_id');
        $maxId = (int) DB::table('failed_jobs')->max('id');
        Cache::forever('watchdog:failed_jobs:last_id', $maxId);
        if ($lastSeen === null || $maxId <= (int) $lastSeen) {
            return;     // first run starts the clock — old history is not news
        }

        $rows = DB::table('failed_jobs')->where('id', '>', (int) $lastSeen)->orderBy('id')->limit(200)->get(['payload', 'exception']);
        $byJob = [];
        $firstError = [];
        foreach ($rows as $row) {
            $name = class_basename((string) (json_decode((string) $row->payload, true)['displayName'] ?? 'งานไม่ทราบชื่อ'));
            $byJob[$name] = ($byJob[$name] ?? 0) + 1;
            $firstError[$name] ??= strtok((string) $row->exception, "\n") ?: '';
        }
        arsort($byJob);
        $top = array_key_first($byJob);
        $this->line('failed jobs: ' . count($rows));

        AdminAlerts::send(new Alert(
            key: 'failed-jobs',
            level: Alert::WARNING,
            title: 'งานเบื้องหลังล้มเหลว ' . number_format(count($rows)) . ' งาน',
            body: "ล้มเหลวหลังลองครบทุกครั้งแล้ว — ตัวอย่างสาเหตุ ({$top}):\n"
                . mb_substr(Redact::text($firstError[$top] ?? ''), 0, 300),
            facts: ['งานที่ล้มเหลว' => number_format(count($rows)) . ' งาน', 'ประเภท' => count($byJob) . ' แบบ'],
            bars: array_slice($byJob, 0, 6, true),
            url: url('/admin'),
            urlLabel: 'เปิดหน้าแอดมิน',
            category: 'system',
            barsLabel: 'แยกตามประเภทงาน',
        ), 180);
    }

    private function checkDisk(): void
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (! $free || ! $total) {
            return;
        }
        $gb = $free / 1024 ** 3;
        $pct = $free / $total * 100;
        $this->line(sprintf('disk: %.1f GB free (%.1f%%)', $gb, $pct));

        $level = match (true) {
            $gb < 2 || $pct < 3 => Alert::CRITICAL,
            $gb < 5 || $pct < 8 => Alert::WARNING,
            default => null,
        };
        if ($level === null) {
            AdminAlerts::forgetThrottle('disk-low:' . Alert::WARNING);
            AdminAlerts::forgetThrottle('disk-low:' . Alert::CRITICAL);

            return;
        }

        $usedPct = 100 - $pct;
        AdminAlerts::send(new Alert(
            key: 'disk-low:' . $level,
            level: $level,
            title: $level === Alert::CRITICAL ? 'ดิสก์เซิร์ฟเวอร์ใกล้เต็มมาก' : 'ดิสก์เซิร์ฟเวอร์เหลือน้อย',
            body: $level === Alert::CRITICAL
                ? 'ถ้าเต็ม เว็บจะเขียนไฟล์/ฐานข้อมูลไม่ได้และล่มทั้งเว็บ — ลบ log/สลิปเก่า/ไฟล์ชั่วคราวที่ไม่ใช้ด่วน'
                : 'ควรเคลียร์ log เก่าและไฟล์ชั่วคราว ก่อนดิสก์เต็ม',
            facts: [
                'เหลือว่าง' => number_format($gb, 1) . ' GB',
                'ใช้ไปแล้ว' => number_format($usedPct, 1) . '%',
                'ทั้งหมด' => number_format($total / 1024 ** 3, 0) . ' GB',
            ],
            bars: ['ใช้แล้ว' => (int) round($usedPct), 'ว่าง' => (int) round($pct)],
            category: 'system',
            barsLabel: 'สัดส่วนดิสก์ (%)',
        ), $level === Alert::CRITICAL ? 180 : 720);
    }
}
