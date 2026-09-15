<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Verify every wallet balance equals the sum of its ledger entries.
 *
 * The invariant is SUM(amount) over rows that ACTUALLY mutated the wallet
 * (i.e. balance_after IS NOT NULL) == wallets.balance. We can't filter on
 * status='success', because refund()/reverseTopup() flip the original row to
 * 'refunded' while adding a separate compensating row — both the original
 * mutation and the compensation carry a balance_after, and together they net
 * out correctly.
 */
class WalletReconcile extends Command
{
    protected $signature = 'wallet:reconcile {--fix : overwrite the stored balance with the computed ledger sum when drift is found}';

    protected $description = 'Detect (and optionally fix) wallets whose balance has drifted from their ledger';

    public function handle(): int
    {
        $drift = 0;
        $checked = 0;

        Wallet::query()->orderBy('id')->chunk(200, function ($wallets) use (&$drift, &$checked) {
            foreach ($wallets as $wallet) {
                $checked++;
                $sum = '0.00';
                WalletTransaction::where('wallet_id', $wallet->id)
                    ->whereNotNull('balance_after')
                    ->orderBy('id')
                    ->chunk(500, function ($rows) use (&$sum) {
                        foreach ($rows as $r) {
                            $sum = bcadd($sum, (string) $r->amount, 2);
                        }
                    });

                if (bccomp($sum, (string) $wallet->balance, 2) !== 0) {
                    $drift++;
                    $this->error(sprintf(
                        'DRIFT wallet#%d (user#%d): ledger=%s stored=%s',
                        $wallet->id, $wallet->user_id, $sum, $wallet->balance,
                    ));
                    Log::warning('wallet:reconcile drift detected', [
                        'wallet_id' => $wallet->id,
                        'user_id'   => $wallet->user_id,
                        'ledger'    => $sum,
                        'stored'    => (string) $wallet->balance,
                    ]);
                    if ($this->option('fix')) {
                        $wallet->update(['balance' => $sum]);
                        $this->warn("  → corrected stored balance to $sum");
                    }
                }
            }
        });

        if ($drift === 0) {
            $this->info("All $checked wallet(s) reconcile cleanly.");
            return self::SUCCESS;
        }

        $this->error("$drift of $checked wallet(s) drifted." . ($this->option('fix') ? ' (corrected)' : ' Run with --fix to correct.'));

        // A balance that doesn't match its ledger means money was created or lost somewhere — the
        // owner hears about it the same night, not whenever someone next reads laravel.log.
        \App\Support\AdminAlerts::send(new \App\Support\Alerts\Alert(
            key: 'wallet-drift:' . now()->toDateString(),
            level: \App\Support\Alerts\Alert::CRITICAL,
            title: 'ยอดวอลเลตไม่ตรงกับบัญชีรายการ ' . $drift . ' กระเป๋า',
            body: "ยอดคงเหลือที่เก็บไว้ไม่เท่ากับผลรวมรายการเงินเข้า-ออก — อาจมีการเครดิต/หักเงินที่ไม่ผ่านระบบ\n"
                . 'ดูรายละเอียดใน laravel.log (wallet:reconcile drift detected) แล้วรัน php artisan wallet:reconcile --fix หลังตรวจแล้ว',
            facts: ['กระเป๋าที่ไม่ตรง' => $drift . ' / ' . $checked, 'แก้อัตโนมัติ' => $this->option('fix') ? 'แก้แล้ว' : 'ยังไม่แก้'],
            url: url('/admin'),
            urlLabel: 'เปิดหน้าแอดมิน',
            category: 'system',
        ), 720);

        return self::FAILURE;
    }
}
