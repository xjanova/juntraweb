<?php

namespace App\Services\Wallet;

use App\Exceptions\AlreadyRefundedException;
use App\Exceptions\DuplicateSlipException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\SlipAmountMismatchException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Wallet operations are *always* wrapped in a DB transaction with
 * SELECT … FOR UPDATE on the wallet row, so two simultaneous debit
 * attempts for the same user serialise. bccomp/bcsub/bcadd are used
 * for the actual arithmetic to avoid float drift on long histories.
 *
 * Lock order: a path that locks both a wallet row and a wallet_transactions row locks the WALLET
 * first, then the transaction (same as AdminWalletService) — two paths in opposite order deadlock.
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

    /**
     * Refund what is still owed on a previous debit (creates a paired credit row pointing to the original).
     *
     * Pays back only charged − {@see refundedSoFar()}: an admin may already have refunded part of
     * this debit (AdminWalletService::refundDebit leaves a partly refunded debit as 'success'), and
     * that money must not go back twice. The debit row is re-read under lock — a caller holding a
     * stale model (a reading finisher, the bill page) cannot refund a debit that was refunded or
     * rolled back meanwhile.
     *
     * Lock order is the same as AdminWalletService: wallet row first, then the transaction row.
     *
     * @throws AlreadyRefundedException  nothing is left to refund
     * @throws \RuntimeException          not a successful debit
     */
    public function refund(WalletTransaction $debit, ?string $reason = null): WalletTransaction
    {
        if ($debit->type !== 'debit' || ! $debit->wallet_id) {
            throw new \RuntimeException('Only successful debits can be refunded');
        }

        // attempts = 3: a deadlock / lock timeout at the top level is retried by Laravel
        $tx = DB::transaction(function () use ($debit, $reason) {
            $wallet = Wallet::whereKey($debit->wallet_id)->lockForUpdate()->firstOrFail();
            $locked = WalletTransaction::whereKey($debit->id)->lockForUpdate()->firstOrFail();
            if ($locked->type === 'debit' && $locked->status === 'refunded') {
                throw new AlreadyRefundedException('This debit has already been refunded');
            }
            if ($locked->type !== 'debit' || $locked->status !== 'success') {
                throw new \RuntimeException('Only successful debits can be refunded');
            }

            $charged   = number_format(abs((float) $locked->amount), 2, '.', '');
            $already   = $this->refundedSoFar($locked);
            $remaining = bcsub($charged, $already, 2);
            if (bccomp($remaining, '0', 2) <= 0) {
                throw new AlreadyRefundedException('This debit has already been refunded in full');
            }

            $newBal = bcadd((string) $wallet->balance, $remaining, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            $meta = ['reason' => $reason];
            if (bccomp($already, '0', 2) > 0) {
                // ส่วนที่เหลือหลังแอดมินคืนไปบางส่วนแล้ว
                $meta['charged']         = $charged;
                $meta['refunded_before'] = $already;
            }

            $refund = WalletTransaction::create([
                'user_id'        => $locked->user_id,
                'wallet_id'      => $wallet->id,
                'type'           => 'refund',
                'status'         => 'success',
                'amount'         => $remaining,
                'balance_after'  => $newBal,
                'description'    => 'คืนเครดิต: ' . ($locked->description ?? ''),
                'reference_type' => 'wallet_transaction',
                'reference_id'   => $locked->id,
                'method'         => 'system',
                'meta'           => $meta,
            ]);
            $locked->update(['status' => 'refunded']);

            return $refund;
        }, 3);

        // the caller's model is in sync again (it was the old behaviour of $debit->update())
        $debit->status = 'refunded';
        $debit->syncOriginalAttribute('status');

        return $tx;
    }

    /**
     * Sum already paid back against this debit — by the system ({@see refund()}), an admin refund
     * (AdminWalletService::refundDebit) or an admin rollback. All of them write a 'refund' row with
     * reference_type = wallet_transaction / reference_id = the debit.
     */
    public function refundedSoFar(WalletTransaction $debit): string
    {
        $sum = '0.00';
        WalletTransaction::where('reference_type', 'wallet_transaction')
            ->where('reference_id', $debit->id)
            ->where('type', 'refund')
            ->whereNotNull('balance_after')
            ->pluck('amount')
            ->each(function ($a) use (&$sum) {
                $sum = bcadd($sum, (string) $a, 2);
            });

        return $sum;
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
            // (check-and-set is atomic inside the transaction). Wallet first — see class doc.
            $wallet = Wallet::where('id', $tx->wallet_id)->lockForUpdate()->firstOrFail();
            $locked = WalletTransaction::where('id', $tx->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending' || (int) $locked->wallet_id !== (int) $wallet->id) {
                throw new \RuntimeException('Not a pending top-up');
            }
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
    /**
     * @param  string|null  $creditAmount  ยอดที่จะเครดิตจริง ถ้าไม่เท่ายอดบิล — ใช้กับสลิปที่ลูกค้าโอน
     *                                      "ตัดเศษสตางค์" (บิล ฿39.80 โอนมา ฿39.00) เท่านั้น
     *                                      ต้องอยู่ระหว่างยอดฐาน (meta.base_amount) ถึงยอดบิล ห้ามเกินยอดบิล
     */
    public function confirmTopupAuto(WalletTransaction $tx, array $meta = [], ?string $creditAmount = null): WalletTransaction
    {
        if ($tx->type !== 'topup') {
            throw new \RuntimeException('Not a top-up');
        }
        $result = DB::transaction(function () use ($tx, $meta, $creditAmount) {
            // wallet first, then the top-up — see class doc
            $wallet = Wallet::where('id', $tx->wallet_id)->lockForUpdate()->firstOrFail();
            $locked = WalletTransaction::where('id', $tx->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending' || (int) $locked->wallet_id !== (int) $wallet->id) {
                throw new \RuntimeException('Not a pending top-up');
            }

            // ยอดเครดิต — ปกติ = ยอดบิล · สลิปตัดเศษสตางค์ = ยอดที่โอนจริง (ตรวจขอบเขตใต้ล็อก)
            $payable = number_format((float) $locked->amount, 2, '.', '');
            $credit  = $payable;
            $extra   = [];
            if ($creditAmount !== null) {
                $credit = number_format((float) $creditAmount, 2, '.', '');
                $base   = number_format((float) (((array) $locked->meta)['base_amount'] ?? $payable), 2, '.', '');
                if (bccomp($credit, $payable, 2) > 0 || bccomp($credit, $base, 2) < 0 || bccomp($credit, '0', 2) <= 0) {
                    throw new \RuntimeException('Credit amount out of range');
                }
                if (bccomp($credit, $payable, 2) !== 0) {
                    $extra['payable_amount'] = $payable; // ยอดบิลเดิม (มีเศษสตางค์ไว้จับคู่ SMS)
                }
            }

            $newBal = bcadd((string) $wallet->balance, $credit, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            // 🧾 audit: อนุมัติอัตโนมัติ (ไม่มีแอดมินกด) — เก็บว่าอนุมัติด้วยอะไร เวลาไหน
            $by = $meta['auto_approved_by'] ?? null;
            unset($meta['auto_approved_by']);
            if ($by !== null) {
                $extra['auto_approved'] = [
                    'by'    => $by,
                    'label' => \App\Support\TopupAutoApprove::label($by),
                    'at'    => now()->toIso8601String(),
                ];
            }

            $locked->update([
                'status'        => 'success',
                'amount'        => $credit,
                'balance_after' => $newBal,
                'slip_amount'   => $credit, // ยอดที่ยืนยันแล้ว == ยอดที่เครดิต (ยอดในสลิปจริงอยู่ใน meta.slip_amount)
                'approved_at'   => now(),
                'meta'          => array_merge((array) $locked->meta, $meta, $extra),
            ]);

            if ($by !== null) {
                Log::info('Wallet: top-up auto-approved', [
                    'tx_id'     => $locked->id,
                    'reference' => $locked->reference_code,
                    'by'        => $by,
                    'credit'    => $credit,
                    'payable'   => $payable,
                ]);
            }

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
     *
     * @deprecated หน้าแอดมินเปลี่ยนไปใช้ AdminWalletService::rollback() แล้ว (2026-11-04) —
     *             มี audit + กันกดซ้ำ และปฏิเสธเมื่อยอดไม่พอแทนการหักเท่าที่มี
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

    /**
     * System-initiated reversal of a credited top-up whose money went back to the customer outside
     * this site — a Google Play refund / chargeback (GooglePlayBilling::void). Same rules as
     * {@see reverseTopup()}: claws back up to the available balance (never negative; the shortfall
     * is recorded in meta) and flips the top-up to 'refunded'. No admin actor — the store is the source.
     */
    public function clawBackTopup(WalletTransaction $tx, string $reason, array $meta = []): WalletTransaction
    {
        if ($tx->type !== 'topup' || $tx->status !== 'success') {
            throw new \RuntimeException('เรียกคืนได้เฉพาะการเติมเงินที่สำเร็จแล้วเท่านั้น');
        }

        return DB::transaction(function () use ($tx, $reason, $meta) {
            // lock order: wallet first, then the transaction (same as refund() / AdminWalletService)
            $wallet = Wallet::where('id', $tx->wallet_id)->lockForUpdate()->firstOrFail();
            $locked = WalletTransaction::where('id', $tx->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'success' || (int) $locked->wallet_id !== (int) $wallet->id) {
                throw new \RuntimeException('รายการนี้ถูกเรียกคืนไปแล้ว');
            }
            $amount   = number_format((float) $locked->amount, 2, '.', '');
            $clawable = bccomp((string) $wallet->balance, $amount, 2) >= 0
                ? $amount
                : number_format((float) $wallet->balance, 2, '.', '');
            $newBal = bcsub((string) $wallet->balance, $clawable, 2);
            $wallet->balance = $newBal;
            $wallet->save();

            $shortfall = bcsub($amount, $clawable, 2);
            $adj = WalletTransaction::create([
                'user_id'        => $locked->user_id,
                'wallet_id'      => $wallet->id,
                'type'           => 'adjustment',
                'status'         => 'success',
                'amount'         => '-' . $clawable,
                'balance_after'  => $newBal,
                'description'    => 'เรียกคืนเครดิต: ' . $reason,
                'reference_type' => 'wallet_transaction',
                'reference_id'   => $locked->id,
                'method'         => $locked->method ?? 'system',
                'meta'           => array_filter(array_merge($meta, [
                    'reason'    => $reason,
                    'shortfall' => bccomp($shortfall, '0', 2) > 0 ? $shortfall : null,
                ]), fn ($v) => $v !== null),
            ]);
            $locked->update(['status' => 'refunded']);

            return $adj;
        });
    }

    /**
     * Manual signed admin adjustment (promo credit, correction). Never goes negative.
     *
     * @deprecated หน้าแอดมินเลิกใช้แล้ว (2026-11-04) — ใช้ AdminWalletService::adjust() ที่มี audit,
     *             กันกดซ้ำ, ยอดอ้างอิง และปฏิเสธเมื่อยอดไม่พอ (ตัวนี้หักเท่าที่มีแบบเงียบ ๆ)
     */
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

    /**
     * Reject a pending top-up on behalf of the SMS Checker device (encrypted,
     * HMAC-verified /api/v1/sms-payment/notify-action from the admin's phone).
     * That request has no Filament User, so unlike rejectTopup() the actor is
     * recorded in meta (rejected_via / rejected_by_device) instead of
     * approved_by. Moves NO money (pending → failed) and uses the same lock +
     * status re-check as rejectTopup(). Only SmsCheckerService may call this,
     * after the device has been authenticated — never expose it to end users.
     */
    public function rejectTopupFromDevice(WalletTransaction $tx, string $deviceId, ?string $reason = null): WalletTransaction
    {
        if ($tx->type !== 'topup') {
            throw new \RuntimeException('Not a top-up');
        }
        $result = DB::transaction(function () use ($tx, $deviceId, $reason) {
            $locked = WalletTransaction::where('id', $tx->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new \RuntimeException('Not a pending top-up');
            }
            $locked->update([
                'status'      => 'failed',
                'approved_at' => now(),
                'meta'        => array_merge((array) $locked->meta, [
                    'reject_reason'      => $reason,
                    'rejected_via'       => 'smschecker_app',
                    'rejected_by_device' => $deviceId,
                ]),
            ]);
            return $locked->fresh();
        });
        Cache::forget('wallet:pending_topup_count');
        return $result;
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
