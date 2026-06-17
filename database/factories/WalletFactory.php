<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Wallet> */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            'user_id'  => User::factory(),
            'balance'  => 0,
            'currency' => 'THB',
        ];
    }

    public function balance(float $amount): static
    {
        return $this->state(fn () => ['balance' => number_format($amount, 2, '.', '')]);
    }
}
