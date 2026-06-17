<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateSlipException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\SlipAmountMismatchException;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = app(WalletService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function member(): User
    {
        // Default role is 'member' (see add_role_to_users enum).
        return User::factory()->create();
    }

    public function test_credit_then_debit_updates_balance_with_bcmath(): void
    {
        $u = $this->member();
        $this->wallet->credit($u, 100, 'seed');
        $this->assertSame(100.0, $this->wallet->balance($u));

        $tx = $this->wallet->debit($u, 19, 'tarot');
        $this->assertSame(81.0, $this->wallet->balance($u));
        $this->assertSame('-19.00', $tx->amount);
        $this->assertSame('81.00', (string) $tx->balance_after);
    }

    public function test_debit_throws_when_insufficient(): void
    {
        $u = $this->member();
        $this->wallet->credit($u, 10, 'seed');
        $this->expectException(InsufficientFundsException::class);
        $this->wallet->debit($u, 19, 'tarot');
    }

    public function test_refund_restores_balance_and_marks_original_refunded(): void
    {
        $u = $this->member();
        $this->wallet->credit($u, 100, 'seed');
        $debit = $this->wallet->debit($u, 19, 'tarot');
        $this->wallet->refund($debit);

        $this->assertSame(100.0, $this->wallet->balance($u));
        $this->assertSame('refunded', $debit->fresh()->status);
    }

    public function test_idempotent_debit_charges_once(): void
    {
        $u = $this->member();
        $this->wallet->credit($u, 100, 'seed');
        $a = $this->wallet->debit($u, 19, 'tarot', ['idempotency_key' => 'abc']);
        $b = $this->wallet->debit($u, 19, 'tarot', ['idempotency_key' => 'abc']);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(81.0, $this->wallet->balance($u));
        $this->assertSame(1, WalletTransaction::where('idempotency_key', 'abc')->count());
    }

    public function test_non_admin_cannot_approve_topup(): void
    {
        $u = $this->member();
        $tx = $this->wallet->recordPendingTopup($u, 100, null, 'promptpay');
        $this->expectException(\RuntimeException::class);
        $this->wallet->approveTopup($tx, $this->member());
    }

    public function test_admin_approve_credits_balance(): void
    {
        $u = $this->member();
        $tx = $this->wallet->recordPendingTopup($u, 100, null, 'promptpay');
        $this->wallet->approveTopup($tx, $this->admin(), 100.0);

        $this->assertSame(100.0, $this->wallet->balance($u));
        $this->assertSame('success', $tx->fresh()->status);
    }

    public function test_approve_blocks_slip_amount_mismatch(): void
    {
        $u = $this->member();
        $tx = $this->wallet->recordPendingTopup($u, 100, null, 'promptpay');
        $this->expectException(SlipAmountMismatchException::class);
        $this->wallet->approveTopup($tx, $this->admin(), 20.0);
    }

    public function test_double_approve_credits_only_once(): void
    {
        $u = $this->member();
        $admin = $this->admin();
        $tx = $this->wallet->recordPendingTopup($u, 100, null, 'promptpay');
        $this->wallet->approveTopup($tx, $admin, 100.0);

        try {
            $this->wallet->approveTopup($tx->fresh(), $admin, 100.0);
        } catch (\RuntimeException) {
            // expected
        }
        $this->assertSame(100.0, $this->wallet->balance($u));
    }

    public function test_duplicate_slip_is_rejected(): void
    {
        $u = $this->member();
        $this->wallet->recordPendingTopup($u, 100, 'slips/a.jpg', 'promptpay', 'HASH1');
        $this->expectException(DuplicateSlipException::class);
        $this->wallet->recordPendingTopup($u, 100, 'slips/b.jpg', 'promptpay', 'HASH1');
    }

    public function test_pending_cap_is_enforced(): void
    {
        config(['pricing.max_pending_topups' => 2]);
        $u = $this->member();
        $this->wallet->recordPendingTopup($u, 100, null, 'promptpay');
        $this->wallet->recordPendingTopup($u, 100, null, 'promptpay');
        $this->expectException(\RuntimeException::class);
        $this->wallet->recordPendingTopup($u, 100, null, 'promptpay');
    }

    public function test_owner_can_cancel_pending_topup(): void
    {
        $u = $this->member();
        $tx = $this->wallet->recordPendingTopup($u, 100, null, 'promptpay');
        $this->wallet->cancelTopup($tx, $u);
        $this->assertSame('cancelled', $tx->fresh()->status);
    }

    public function test_reverse_approved_topup_claws_back_available_balance(): void
    {
        $u = $this->member();
        $admin = $this->admin();
        $tx = $this->wallet->recordPendingTopup($u, 100, null, 'promptpay');
        $this->wallet->approveTopup($tx, $admin, 100.0);
        $this->wallet->debit($u, 30, 'spent'); // balance 70

        $this->wallet->reverseTopup($tx->fresh(), $admin, 'wrong approval');

        // Claws back min(70, 100) = 70 → balance floors at 0, never negative.
        $this->assertSame(0.0, $this->wallet->balance($u));
        $this->assertSame('refunded', $tx->fresh()->status);
    }

    public function test_adjust_positive_then_clamped_negative(): void
    {
        $u = $this->member();
        $admin = $this->admin();
        $this->wallet->adjust($u, 50, 'promo', $admin);
        $this->assertSame(50.0, $this->wallet->balance($u));

        $this->wallet->adjust($u, -80, 'correction', $admin); // clamps to -50
        $this->assertSame(0.0, $this->wallet->balance($u));
    }

    public function test_ledger_reconciles_with_balance(): void
    {
        $u = $this->member();
        $admin = $this->admin();
        $this->wallet->credit($u, 200, 'seed');
        $debit = $this->wallet->debit($u, 19, 'tarot');
        $this->wallet->refund($debit);
        $this->wallet->debit($u, 9, 'numerology');
        $topup = $this->wallet->recordPendingTopup($u, 500, null, 'promptpay');
        $this->wallet->approveTopup($topup, $admin, 500.0);

        // 200 - 19 + 19 - 9 + 500 = 691
        $wallet = $this->wallet->getOrCreate($u);
        $sum = WalletTransaction::where('wallet_id', $wallet->id)
            ->whereNotNull('balance_after')
            ->get()
            ->reduce(fn ($carry, $t) => bcadd($carry, (string) $t->amount, 2), '0.00');

        $this->assertSame('691.00', $sum);
        $this->assertSame(691.0, (float) $wallet->balance);
    }
}
