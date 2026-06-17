<?php

namespace App\Console\Commands;

use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Mark expired pending top-ups as failed and delete their slip images so
 * abandoned requests don't accumulate in the admin queue or fill the private
 * disk. Runs hourly (see routes/console.php).
 */
class CleanupExpiredTopups extends Command
{
    protected $signature = 'wallet:cleanup-expired-topups';

    protected $description = 'Expire stale pending top-ups (past expires_at) and remove their slip files';

    public function handle(): int
    {
        $count = 0;

        WalletTransaction::where('type', 'topup')
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($rows) use (&$count) {
                foreach ($rows as $tx) {
                    if ($tx->slip_path && Storage::disk('local')->exists($tx->slip_path)) {
                        Storage::disk('local')->delete($tx->slip_path);
                    }
                    $tx->update([
                        'status' => 'failed',
                        'meta'   => array_merge((array) $tx->meta, ['expired' => true]),
                    ]);
                    $count++;
                }
            });

        if ($count > 0) {
            Cache::forget('wallet:pending_topup_count');
        }
        $this->info("Expired $count pending top-up(s).");
        return self::SUCCESS;
    }
}
