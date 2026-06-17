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
