<?php

namespace App\Services\Wallet;

use App\Exceptions\InsufficientFundsException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wallet operations are *always* wrapped in a DB transaction with
 * SELECT … FOR UPDATE on the wallet row, so two simultaneous debit
 * attempts for the same user serialise. bccomp/bcsub/bcadd are used
 * for the actual arithmetic to avoid float drift on long histories.
 */
class WalletService
{
    public function getOrCreate(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'currency' => config('pricing.currency', 'THB')],
        );
    }

    public function balance(User $user): float
    {
        return (float) $this->getOrCreate($user)->balance;
    }

    /** Debit credit from a user. Throws InsufficientFundsException when balance < amount. */
    public function debit(User $user, float $amount, string $description, array $opts = []): WalletTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be > 0');
        }
        $this->getOrCreate($user); // ensure row exists before locking

        return DB::transaction(function () use ($user, $amount, $description, $opts) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $amt = number_format($amount, 2, '.', '');
            if (bccomp((string) $wallet->balance, $amt, 2) < 0) {
                throw new InsufficientFundsException($amount, (float) $wallet->balance);
            }
            $newBal = bcsub((string) $wallet->balance, $amt, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            return WalletTransaction::create([
                'user_id'        => $user->id,
                'wallet_id'      => $wallet->id,
                'type'           => 'debit',
                'status'         => 'success',
                'amount'         => '-' . $amt,
                'balance_after'  => $newBal,
                'description'    => $description,
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id'   => $opts['reference_id']   ?? null,
                'method'         => $opts['method']         ?? 'system',
                'meta'           => $opts['meta']           ?? null,
            ]);
        });
    }

    /** Credit a user immediately (e.g. promo, refund, manual top-up). */
    public function credit(User $user, float $amount, string $description, array $opts = []): WalletTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be > 0');
        }
        $this->getOrCreate($user);

        return DB::transaction(function () use ($user, $amount, $description, $opts) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $amt    = number_format($amount, 2, '.', '');
            $newBal = bcadd((string) $wallet->balance, $amt, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            return WalletTransaction::create([
                'user_id'        => $user->id,
                'wallet_id'      => $wallet->id,
                'type'           => $opts['type']           ?? 'topup',
                'status'         => 'success',
                'amount'         => $amt,
                'balance_after'  => $newBal,
                'description'    => $description,
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id'   => $opts['reference_id']   ?? null,
                'method'         => $opts['method']         ?? 'system',
                'reference_code' => $opts['reference_code'] ?? null,
                'meta'           => $opts['meta']           ?? null,
                'approved_by'    => $opts['approved_by']    ?? null,
                'approved_at'    => $opts['approved_at']    ?? null,
            ]);
        });
    }

    /** Refund a previous debit (creates a paired credit row pointing to the original). */
    public function refund(WalletTransaction $debit, ?string $reason = null): WalletTransaction
    {
        if ($debit->type !== 'debit' || $debit->status !== 'success') {
            throw new \RuntimeException('Only successful debits can be refunded');
        }
        $amount = abs((float) $debit->amount);
        $tx = $this->credit($debit->user, $amount, 'คืนเครดิต: ' . ($debit->description ?? ''), [
            'type'           => 'refund',
            'reference_type' => 'wallet_transaction',
            'reference_id'   => $debit->id,
            'method'         => 'system',
            'meta'           => ['reason' => $reason],
        ]);
        $debit->update(['status' => 'refunded']);
        return $tx;
    }

    /** Record a top-up request awaiting admin approval (e.g. PromptPay slip uploaded). */
    public function recordPendingTopup(User $user, float $amount, ?string $slipPath, string $method = 'promptpay'): WalletTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be > 0');
        }
        $wallet = $this->getOrCreate($user);
        $tx = WalletTransaction::create([
            'user_id'        => $user->id,
            'wallet_id'      => $wallet->id,
            'type'           => 'topup',
            'status'         => 'pending',
            'amount'         => number_format($amount, 2, '.', ''),
            'description'    => 'เติมเงินเข้าวอลเลต',
            'method'         => $method,
            'slip_path'      => $slipPath,
            'reference_code' => 'TUP-' . strtoupper(Str::random(8)),
        ]);
        Cache::forget('wallet:pending_topup_count');
        return $tx;
    }

    /** Approve a pending top-up — credits balance + flips status to success. */
    public function approveTopup(WalletTransaction $tx, User $admin): WalletTransaction
    {
        if ($tx->type !== 'topup' || $tx->status !== 'pending') {
            throw new \RuntimeException('Not a pending top-up');
        }
        $result = DB::transaction(function () use ($tx, $admin) {
            $wallet = Wallet::where('id', $tx->wallet_id)->lockForUpdate()->firstOrFail();
            $newBal = bcadd((string) $wallet->balance, (string) $tx->amount, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            $tx->update([
                'status'        => 'success',
                'balance_after' => $newBal,
                'approved_by'   => $admin->id,
                'approved_at'   => now(),
            ]);
            return $tx->fresh();
        });
        Cache::forget('wallet:pending_topup_count');
        return $result;
    }

    public function rejectTopup(WalletTransaction $tx, User $admin, ?string $reason = null): WalletTransaction
    {
        if ($tx->type !== 'topup' || $tx->status !== 'pending') {
            throw new \RuntimeException('Not a pending top-up');
        }
        $tx->update([
            'status'      => 'failed',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'meta'        => array_merge((array) $tx->meta, ['reject_reason' => $reason]),
        ]);
        Cache::forget('wallet:pending_topup_count');
        return $tx->fresh();
    }
}
