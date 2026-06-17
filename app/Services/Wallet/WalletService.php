<?php

namespace App\Services\Wallet;

use App\Exceptions\DuplicateSlipException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\SlipAmountMismatchException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Debit credit from a user. Throws InsufficientFundsException when balance < amount.
     *
     * Pass `idempotency_key` in $opts to make a retry/double-tap safe: under
     * the wallet lock, an existing debit with the same key is returned instead
     * of charging again.
     */
    public function debit(User $user, float $amount, string $description, array $opts = []): WalletTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be > 0');
        }
        $this->getOrCreate($user); // ensure row exists before locking
        $key = $opts['idempotency_key'] ?? null;

        return DB::transaction(function () use ($user, $amount, $description, $opts, $key) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            // Idempotency check happens AFTER the lock so concurrent same-key
            // debits serialise — the second one sees the first's row.
            if ($key) {
                $existing = WalletTransaction::where('user_id', $user->id)
                    ->where('idempotency_key', $key)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $amt = number_format($amount, 2, '.', '');
            if (bccomp((string) $wallet->balance, $amt, 2) < 0) {
                throw new InsufficientFundsException($amount, (float) $wallet->balance);
            }
            $newBal = bcsub((string) $wallet->balance, $amt, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            return WalletTransaction::create([
                'user_id'         => $user->id,
                'wallet_id'       => $wallet->id,
                'type'            => 'debit',
                'status'          => 'success',
                'amount'          => '-' . $amt,
                'balance_after'   => $newBal,
                'description'     => $description,
                'reference_type'  => $opts['reference_type'] ?? null,
                'reference_id'    => $opts['reference_id']   ?? null,
                'method'          => $opts['method']         ?? 'system',
                'meta'            => $opts['meta']           ?? null,
                'idempotency_key' => $key,
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
    public function recordPendingTopup(
        User $user,
        float $amount,
        ?string $slipPath,
        string $method = 'promptpay',
        ?string $slipHash = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be > 0');
        }
        // Same slip can't be used for two top-ups (one payment = one credit).
        if ($slipHash) {
            $this->assertSlipNotReused($slipHash);
        }
        // Anti-spam: cap concurrent pending top-ups per user.
        $cap = (int) config('pricing.max_pending_topups', 5);
        if ($cap > 0 && $this->pendingTopupCount($user) >= $cap) {
            throw new \RuntimeException('คุณมีรายการเติมเงินที่รอตรวจสอบอยู่หลายรายการแล้ว กรุณารอแอดมินอนุมัติก่อนทำรายการใหม่');
        }

        $wallet = $this->getOrCreate($user);
        $ttl    = (int) config('pricing.topup_pending_ttl_hours', 48);
        $tx = WalletTransaction::create([
            'user_id'        => $user->id,
            'wallet_id'      => $wallet->id,
            'type'           => 'topup',
            'status'         => 'pending',
            'amount'         => number_format($amount, 2, '.', ''),
            'description'    => 'เติมเงินเข้าวอลเลต',
            'method'         => $method,
            'slip_path'      => $slipPath,
            'slip_hash'      => $slipHash,
            'reference_code' => 'TUP-' . strtoupper(Str::random(8)),
            'expires_at'     => $ttl > 0 ? now()->addHours($ttl) : null,
        ]);
        Cache::forget('wallet:pending_topup_count');
        return $tx;
    }

    /**
     * Approve a pending top-up — credits balance + flips status to success.
     *
     * @param  float|null  $verifiedSlipAmount  the amount the admin actually
     *         read on the slip. When given, it must match the claimed amount
     *         (±0.01) or we refuse — guards against amount tampering.
     */
    public function approveTopup(WalletTransaction $tx, User $admin, ?float $verifiedSlipAmount = null): WalletTransaction
    {
        // Crediting real money is admin-only. `canAccessPanel` also lets in
        // 'editor', and the Filament button visibility is UI-only — this is the
        // real security boundary, so enforce it here regardless of caller.
        $this->assertAdmin($admin);
        if ($tx->type !== 'topup') {
            throw new \RuntimeException('Not a top-up');
        }
        // Amount-tampering guard.
        if ($verifiedSlipAmount !== null && bccomp(
            number_format($verifiedSlipAmount, 2, '.', ''),
            number_format((float) $tx->amount, 2, '.', ''),
            2
        ) !== 0) {
            throw new SlipAmountMismatchException((float) $tx->amount, $verifiedSlipAmount);
        }
        // Re-check the slip hasn't been credited elsewhere since submission.
        if ($tx->slip_hash) {
            $this->assertSlipNotReused($tx->slip_hash, $tx->id);
        }

        $result = DB::transaction(function () use ($tx, $admin, $verifiedSlipAmount) {
            // Re-fetch the row UNDER LOCK and re-check status, so two admins
            // approving the same slip simultaneously can't both credit it
            // (check-and-set is atomic inside the transaction).
            $locked = WalletTransaction::where('id', $tx->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new \RuntimeException('Not a pending top-up');
            }
            $wallet = Wallet::where('id', $locked->wallet_id)->lockForUpdate()->firstOrFail();
            $newBal = bcadd((string) $wallet->balance, (string) $locked->amount, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            $locked->update([
                'status'        => 'success',
                'balance_after' => $newBal,
                'slip_amount'   => $verifiedSlipAmount,
                'approved_by'   => $admin->id,
                'approved_at'   => now(),
            ]);
            return $locked->fresh();
        });
        Cache::forget('wallet:pending_topup_count');
        return $result;
    }

    public function rejectTopup(WalletTransaction $tx, User $admin, ?string $reason = null): WalletTransaction
    {
        $this->assertAdmin($admin);
        if ($tx->type !== 'topup') {
            throw new \RuntimeException('Not a top-up');
        }
        $result = DB::transaction(function () use ($tx, $admin, $reason) {
            $locked = WalletTransaction::where('id', $tx->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new \RuntimeException('Not a pending top-up');
            }
            $locked->update([
                'status'      => 'failed',
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'meta'        => array_merge((array) $locked->meta, ['reject_reason' => $reason]),
            ]);
            return $locked->fresh();
        });
        Cache::forget('wallet:pending_topup_count');
        return $result;
    }

    /**
     * Auto-confirm a pending top-up that the SMS gateway matched to a bank
     * transfer. NO admin gate — this is only ever called by the trusted
     * SmsCheckerService AFTER it has cryptographically verified the device and
     * matched the exact unique amount. Same atomic lock + bcmath as approve.
     */
    public function confirmTopupAuto(WalletTransaction $tx, array $meta = []): WalletTransaction
    {
        if ($tx->type !== 'topup') {
            throw new \RuntimeException('Not a top-up');
        }
        $result = DB::transaction(function () use ($tx, $meta) {
            $locked = WalletTransaction::where('id', $tx->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new \RuntimeException('Not a pending top-up');
            }
            $wallet = Wallet::where('id', $locked->wallet_id)->lockForUpdate()->firstOrFail();
            $newBal = bcadd((string) $wallet->balance, (string) $locked->amount, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            $locked->update([
                'status'        => 'success',
                'balance_after' => $newBal,
                'slip_amount'   => $locked->amount, // SMS-verified amount == charged amount
                'approved_at'   => now(),
                'meta'          => array_merge((array) $locked->meta, $meta),
            ]);
            return $locked->fresh();
        });
        Cache::forget('wallet:pending_topup_count');
        return $result;
    }

    /** Owner cancels their own still-pending top-up (and we drop the slip file). */
    public function cancelTopup(WalletTransaction $tx, User $owner): WalletTransaction
    {
        if ($tx->user_id !== $owner->id) {
            throw new \RuntimeException('ไม่สามารถยกเลิกรายการของผู้อื่นได้');
        }
        if ($tx->type !== 'topup' || $tx->status !== 'pending') {
            throw new \RuntimeException('ยกเลิกได้เฉพาะรายการเติมเงินที่ยังรอตรวจสอบ');
        }
        $result = DB::transaction(function () use ($tx) {
            $locked = WalletTransaction::where('id', $tx->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new \RuntimeException('รายการนี้ถูกดำเนินการไปแล้ว');
            }
            $locked->update([
                'status' => 'cancelled',
                'meta'   => array_merge((array) $locked->meta, ['cancelled_by_user' => true]),
            ]);
            return $locked->fresh();
        });
        if ($result->slip_path && Storage::disk('local')->exists($result->slip_path)) {
            Storage::disk('local')->delete($result->slip_path);
        }
        Cache::forget('wallet:pending_topup_count');
        return $result;
    }

    /**
     * Reverse an already-approved top-up that was credited in error. Claws back
     * up to the available balance (never forces the wallet negative — if the
     * user already spent part of it, the shortfall is recorded in meta) and
     * flips the original top-up to 'refunded'.
     */
    public function reverseTopup(WalletTransaction $tx, User $admin, ?string $reason = null): WalletTransaction
    {
        $this->assertAdmin($admin);
        if ($tx->type !== 'topup' || $tx->status !== 'success') {
            throw new \RuntimeException('เรียกคืนได้เฉพาะการเติมเงินที่อนุมัติแล้วเท่านั้น');
        }
        $result = DB::transaction(function () use ($tx, $admin, $reason) {
            $wallet = Wallet::where('id', $tx->wallet_id)->lockForUpdate()->firstOrFail();
            $amount   = number_format((float) $tx->amount, 2, '.', '');
            $clawable = bccomp((string) $wallet->balance, $amount, 2) >= 0
                ? $amount
                : number_format((float) $wallet->balance, 2, '.', '');
            $newBal = bcsub((string) $wallet->balance, $clawable, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            $shortfall = bcsub($amount, $clawable, 2);
            $adj = WalletTransaction::create([
                'user_id'        => $tx->user_id,
                'wallet_id'      => $wallet->id,
                'type'           => 'adjustment',
                'status'         => 'success',
                'amount'         => '-' . $clawable,
                'balance_after'  => $newBal,
                'description'    => 'เรียกคืนการเติมเงินที่อนุมัติผิด' . ($reason ? ' — ' . $reason : ''),
                'reference_type' => 'wallet_transaction',
                'reference_id'   => $tx->id,
                'method'         => 'system',
                'approved_by'    => $admin->id,
                'approved_at'    => now(),
                'meta'           => array_filter([
                    'reason'    => $reason,
                    'shortfall' => bccomp($shortfall, '0', 2) > 0 ? $shortfall : null,
                ]),
            ]);
            $tx->update(['status' => 'refunded']);
            return $adj;
        });
        Cache::forget('wallet:pending_topup_count');
        return $result;
    }

    /** Manual signed admin adjustment (promo credit, correction). Never goes negative. */
    public function adjust(User $user, float $signedAmount, string $reason, User $admin): WalletTransaction
    {
        $this->assertAdmin($admin);
        if (bccomp(number_format(abs($signedAmount), 2, '.', ''), '0.00', 2) === 0) {
            throw new \InvalidArgumentException('amount must be non-zero');
        }
        $this->getOrCreate($user);

        return DB::transaction(function () use ($user, $signedAmount, $reason, $admin) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $abs    = number_format(abs($signedAmount), 2, '.', '');

            if ($signedAmount < 0) {
                $applied = bccomp((string) $wallet->balance, $abs, 2) >= 0
                    ? $abs
                    : number_format((float) $wallet->balance, 2, '.', '');
                $newBal = bcsub((string) $wallet->balance, $applied, 2);
                $signed = '-' . $applied;
            } else {
                $newBal = bcadd((string) $wallet->balance, $abs, 2);
                $signed = $abs;
            }
            $wallet->balance = $newBal;
            $wallet->save();

            return WalletTransaction::create([
                'user_id'       => $user->id,
                'wallet_id'     => $wallet->id,
                'type'          => 'adjustment',
                'status'        => 'success',
                'amount'        => $signed,
                'balance_after' => $newBal,
                'description'   => 'ปรับยอดโดยแอดมิน: ' . $reason,
                'method'        => 'system',
                'approved_by'   => $admin->id,
                'approved_at'   => now(),
                'meta'          => ['reason' => $reason],
            ]);
        });
    }

    /** Number of still-pending top-ups for a user. */
    public function pendingTopupCount(User $user): int
    {
        return WalletTransaction::where('user_id', $user->id)
            ->where('type', 'topup')
            ->where('status', 'pending')
            ->count();
    }

    /** Throw if a slip hash is already attached to a pending/approved top-up. */
    public function assertSlipNotReused(string $slipHash, ?int $exceptId = null): void
    {
        $q = WalletTransaction::where('slip_hash', $slipHash)
            ->whereIn('status', ['pending', 'success']);
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }
        if ($q->exists()) {
            throw new DuplicateSlipException();
        }
    }

    /** Hard gate: only users whose role is admin may approve/reject/reverse/adjust. */
    private function assertAdmin(User $admin): void
    {
        if (!method_exists($admin, 'isAdmin') || !$admin->isAdmin()) {
            throw new \RuntimeException('เฉพาะแอดมินเท่านั้นที่ทำรายการนี้ได้');
        }
    }
}
