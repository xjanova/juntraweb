<?php

namespace App\Console\Commands;

use App\Support\AdminAlerts;
use App\Support\Alerts\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Yesterday on one card, every morning: sales with a 7-day chart, best sellers, new members,
 * contacts, and what is still waiting on a person.
 *
 *   php artisan alerts:daily-report              # yesterday (Thai time)
 *   php artisan alerts:daily-report --date=2026-09-12
 */
class AlertsDailyReportCommand extends Command
{
    protected $signature = 'alerts:daily-report {--date= : the Thai calendar day to report (default: yesterday)}';

    protected $description = 'Send yesterday\'s summary card to the admin Telegram chat.';

    public function handle(): int
    {
        if (! AdminAlerts::wants('daily')) {
            $this->info('Daily report is switched off (or Telegram is not set up) — skipping.');

            return self::SUCCESS;
        }

        $day = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Bangkok')
            : CarbonImmutable::now('Asia/Bangkok')->subDay();

        $sent = AdminAlerts::send(Reports::day($day), 1200);
        $this->info($sent ? 'Daily report sent for ' . $day->toDateString() . '.' : 'Not sent (already sent today, or Telegram refused it).');

        return self::SUCCESS;
    }
}
