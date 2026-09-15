<?php

namespace App\Console\Commands;

use App\Support\AdminAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly: the new members of the last hour as one card, and whatever the per-category ceilings
 * held back (a burst of contact-form spam, say) as another. Self-gates when alerts are off. Also
 * trims the alert history to 90 days.
 */
class AlertsDigestCommand extends Command
{
    protected $signature = 'alerts:digest';

    protected $description = 'Send the hourly signup digest and any alerts folded by the hourly caps.';

    public function handle(): int
    {
        $signups = AdminAlerts::flushSignups();
        $folded = AdminAlerts::flushOverflow();

        // The history is for "what was that alert?", not an archive: 90 days is plenty.
        $pruned = DB::table('admin_alerts')->where('created_at', '<', now()->subDays(90))->limit(5000)->delete();

        $this->info("signups: {$signups}, folded: {$folded}, pruned: {$pruned}");

        return self::SUCCESS;
    }
}
