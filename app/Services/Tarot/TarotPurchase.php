<?php

namespace App\Services\Tarot;

use App\Exceptions\InsufficientFundsException;
use App\Exceptions\VoicePremiumUnavailable;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\TarotSpreads;
use App\Support\VoicePremium;
use Illuminate\Support\Facades\DB;

final class TarotPurchase
{
    public function __construct(private WalletService $wallet) {}

    public function charge(User $user, string $type, float $cost, bool $premium, string $token, ?string $debitKey = null): array
    {
        if ($premium && ! VoicePremium::offerable()) {
            throw new VoicePremiumUnavailable;
        }
        $price = $premium ? VoicePremium::price() : 0.0;
        $balance = (float) $this->wallet->balance($user);
        if (bccomp(number_format($balance, 2, '.', ''), number_format($cost + $price, 2, '.', ''), 2) < 0) {
            throw new InsufficientFundsException($cost + $price, $balance);
        }

        return DB::transaction(function () use ($user, $type, $cost, $premium, $price, $token, $debitKey) {
            $label = TarotSpreads::nameForType($type) ?? 'ไพ่ยิปซี';
            $tx = $cost > 0 ? $this->wallet->debit($user, $cost, 'เปิดไพ่: '.$label, array_filter([
                'reference_type' => 'reading', 'method' => 'system', 'idempotency_key' => $debitKey,
            ])) : null;
            $premiumTx = $premium && $price > 0 ? $this->wallet->debit($user, $price, 'พรีเมียมเสียงผู้พยากรณ์: '.$label, [
                'reference_type' => 'voice_premium', 'method' => 'system',
                'idempotency_key' => VoicePremium::idempotencyKey($user->id, $token),
            ]) : null;

            // A reused form cannot attach an earlier reading's premium debit to a new reading.
            if ($premiumTx && ! $premiumTx->wasRecentlyCreated && $premiumTx->reference_id !== null) {
                $premium = false;
                $premiumTx = null;
            }
            if ($premiumTx && $premiumTx->status !== 'success') {
                throw new VoicePremiumUnavailable;
            }

            return [
                'tx' => $tx,
                'premium_tx' => $premiumTx,
                'premium' => $premium ? [
                    'status' => 'paid', 'price' => $price, 'tx_id' => $premiumTx?->id, 'at' => now()->toIso8601String(),
                ] : null,
            ];
        });
    }
}
