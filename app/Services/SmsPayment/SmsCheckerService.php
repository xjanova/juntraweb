<?php

namespace App\Services\SmsPayment;

use App\Models\SmsCheckerDevice;
use App\Models\SmsPaymentNotification;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SMS Checker payment gateway — server side of the `thaiprompt-smschecker-v1`
 * protocol. Decrypts the AES-256-GCM payload the Android app sends, verifies
 * the HMAC, records the notification, and matches a credited amount to a
 * reserved pending wallet top-up (auto-crediting the wallet).
 *
 * The crypto here MUST stay byte-for-byte compatible with the app's
 * CryptoManager.kt (AES-256-GCM, PBKDF2-SHA256 100k, salt
 * "thaiprompt-smschecker-v1:{context}", packing Base64(IV[12] ‖ cipher ‖ tag[16])).
 */
class SmsCheckerService
{
    public function __construct(private WalletService $wallet) {}

    /* ============================ CRYPTO ============================ */

    /** Decrypt Base64(IV[12] + ciphertext + tag[16]) → decoded JSON array, or null. */
    public function decryptPayload(string $encryptedData, string $secretKey): ?array
    {
        try {
            $combined = base64_decode($encryptedData, true);
            if ($combined === false || strlen($combined) < 12 + 16) {
                return null;
            }
            $iv      = substr($combined, 0, 12);
            $rest    = substr($combined, 12);
            $tag     = substr($rest, -16);
            $cipher  = substr($rest, 0, -16);

            $plain = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $this->deriveKey($secretKey, 'encryption'),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
            );
            if ($plain === false) {
                return null;
            }
            $payload = json_decode($plain, true);
            return json_last_error() === JSON_ERROR_NONE ? $payload : null;
        } catch (\Throwable $e) {
            Log::error('SmsChecker decrypt error', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /** Verify HMAC-SHA256(encrypted_data + nonce + timestamp) signed with the hmac key. */
    public function verifySignature(string $data, string $signature, string $secretKey): bool
    {
        $hmacKey  = $this->deriveKey($secretKey, 'hmac-signing');
        $expected = base64_encode(hash_hmac('sha256', $data, $hmacKey, true));
        return hash_equals($expected, $signature);
    }

    /** AES key for tests / parity (encrypt side) — same derivation as the app. */
    public function encryptPayload(array $payload, string $secretKey): string
    {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt(
            json_encode($payload),
            'aes-256-gcm',
            $this->deriveKey($secretKey, 'encryption'),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16,
        );
        return base64_encode($iv . $cipher . $tag);
    }

    /** HMAC sign helper (mirrors the app), used by tests. */
    public function sign(string $data, string $secretKey): string
    {
        return base64_encode(hash_hmac('sha256', $data, $this->deriveKey($secretKey, 'hmac-signing'), true));
    }

    private function deriveKey(string $secret, string $context = 'encryption'): string
    {
        return hash_pbkdf2('sha256', $secret, "thaiprompt-smschecker-v1:{$context}", 100000, 32, true);
    }

    /* ========================== MATCHING ========================== */

    /**
     * Record one SMS notification and, if it's a credit that matches a reserved
     * pending top-up, auto-credit the wallet (or flag for manual review).
     */
    public function processNotification(array $payload, SmsCheckerDevice $device, string $ip): array
    {
        return DB::transaction(function () use ($payload, $device, $ip) {
            $amount = (float) ($payload['amount'] ?? 0);
            $type   = $payload['type'] ?? 'credit';
            $smsTs  = $this->parseSmsTimestamp($payload['sms_timestamp'] ?? null);

            $notification = SmsPaymentNotification::create([
                'device_id'          => $device->device_id,
                'bank'               => $payload['bank'] ?? null,
                'type'               => $type,
                'amount'             => number_format($amount, 2, '.', ''),
                'account_number'     => $payload['account_number'] ?? null,
                'sender_or_receiver' => $payload['sender_or_receiver'] ?? null,
                'reference_number'   => $payload['reference_number'] ?? null,
                'nonce'              => $payload['nonce'] ?? null,
                'sms_timestamp'      => $smsTs,
                'status'             => 'pending',
                'raw_payload'        => $payload,
                'ip_address'         => $ip,
            ]);

            $matched = false;
            $matchedTxId = null;

            if ($type === 'credit' && $amount > 0 && config('smschecker.enabled')) {
                $topup = $this->findMatchingTopup($amount, $smsTs);
                if ($topup) {
                    $matchedTxId = $topup->id;
                    if ($device->getApprovalMode() === 'auto') {
                        $this->wallet->confirmTopupAuto($topup, [
                            'confirmed_via'       => 'sms',
                            'sms_notification_id' => $notification->id,
                            'bank'                => $payload['bank'] ?? null,
                            'sms_ref'             => $payload['reference_number'] ?? null,
                        ]);
                        $notification->update(['status' => 'confirmed', 'matched_transaction_id' => $topup->id]);
                    } else {
                        // Manual mode: stamp the match on the top-up for the admin.
                        $topup->update([
                            'slip_amount'    => number_format($amount, 2, '.', ''),
                            'bank_reference' => $payload['reference_number'] ?? null,
                            'meta'           => array_merge((array) $topup->meta, [
                                'sms_matched'         => true,
                                'sms_notification_id' => $notification->id,
                            ]),
                        ]);
                        $notification->update(['status' => 'matched', 'matched_transaction_id' => $topup->id]);
                    }
                    $matched = true;
                }
            }

            $device->forceFill(['last_active_at' => now(), 'ip_address' => $ip])->save();

            return [
                'notification_id'        => $notification->id,
                'status'                 => $notification->fresh()->status,
                'matched'                => $matched,
                'matched_transaction_id' => $matchedTxId,
            ];
        });
    }

    /**
     * Find the reserved pending top-up whose unique amount equals the credited
     * SMS amount. Safety: exact amount, still-pending, not expired, the transfer
     * happened at/after the top-up was created (temporal binding), FIFO oldest
     * first (so an older waiting customer matches before a newer one).
     */
    public function findMatchingTopup(float $amount, ?Carbon $smsTimestamp): ?WalletTransaction
    {
        $q = WalletTransaction::where('type', 'topup')
            ->where('status', 'pending')
            ->where('method', 'promptpay')
            ->where('amount', number_format($amount, 2, '.', ''))
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at');

        // Only bind on time when the SMS timestamp is sane (not absurdly in the past/future).
        if ($smsTimestamp && $smsTimestamp->gt(now()->subDays(2)) && $smsTimestamp->lt(now()->addHour())) {
            $q->where('created_at', '<=', $smsTimestamp);
        }

        return $q->first();
    }

    /**
     * Pick a unique payable amount for a top-up of $base baht by appending a
     * satang suffix not currently used by another reserved (pending, unexpired)
     * top-up — so an incoming SMS of that exact amount maps to exactly one bill.
     */
    public function uniqueAmountFor(float $base): float
    {
        $min  = (int) config('smschecker.unique_suffix_min', 1);
        $max  = (int) config('smschecker.unique_suffix_max', 99);
        $baht = (int) floor($base);

        $used = WalletTransaction::where('type', 'topup')
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereBetween('amount', [$baht + $min / 100, $baht + $max / 100])
            ->pluck('amount')
            ->map(fn ($a) => number_format((float) $a, 2, '.', ''))
            ->flip();

        for ($s = $min; $s <= $max; $s++) {
            $candidate = number_format($baht + $s / 100, 2, '.', '');
            if (! $used->has($candidate)) {
                return (float) $candidate;
            }
        }
        // Pool exhausted for this base amount — fall back to the plain amount
        // (matching may be ambiguous; temporal binding + FIFO still mitigate).
        return (float) $base;
    }

    private function parseSmsTimestamp($raw): ?Carbon
    {
        if (empty($raw)) {
            return null;
        }
        try {
            // App sends epoch milliseconds (UTC instant). Convert to the app
            // timezone so the wall-clock compares correctly with created_at,
            // which Eloquent stores in the app timezone.
            $tz = config('app.timezone', 'UTC');
            if (is_numeric($raw)) {
                return Carbon::createFromTimestampMs((int) $raw)->setTimezone($tz);
            }
            return Carbon::parse($raw)->setTimezone($tz);
        } catch (\Throwable) {
            return null;
        }
    }
}
