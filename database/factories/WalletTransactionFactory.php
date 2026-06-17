<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WalletTransaction> */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'wallet_id'   => Wallet::factory(),
            'type'        => 'debit',
            'status'      => 'success',
            'amount'      => '-19.00',
            'description' => 'test transaction',
            'method'      => 'system',
        ];
    }

    public function pendingTopup(float $amount = 100, ?string $slipHash = null): static
    {
        return $this->state(fn () => [
            'type'           => 'topup',
            'status'         => 'pending',
            'amount'         => number_format($amount, 2, '.', ''),
            'method'         => 'promptpay',
            'reference_code' => 'TUP-' . strtoupper(Str::random(8)),
            'slip_hash'      => $slipHash,
            'expires_at'     => now()->addHours(48),
        ]);
    }
}
