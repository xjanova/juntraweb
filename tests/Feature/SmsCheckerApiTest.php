<?php

namespace Tests\Feature;

use App\Models\SmsCheckerDevice;
use App\Models\SmsPaymentNotification;
use App\Models\User;
use App\Services\SmsPayment\SmsCheckerService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsCheckerApiTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'b4f1c0de00112233445566778899aabbccddeeff00112233445566778899aabb';

    private function device(string $mode = 'auto', string $status = 'active'): SmsCheckerDevice
    {
        return SmsCheckerDevice::create([
            'device_id'     => 'SMSCHK-TESTONLY',
            'device_name'   => 'Test device',
            'api_key'       => 'apikey-' . bin2hex(random_bytes(8)),
            'secret_key'    => $this->secret,
            'platform'      => 'android',
            'status'        => $status,
            'approval_mode' => $mode,
        ]);
    }

    /** Build the encrypted + signed /notify request the app would send. */
    private function notifyRequest(SmsCheckerDevice $device, array $payload): array
    {
        $svc       = app(SmsCheckerService::class);
        $encrypted = $svc->encryptPayload($payload, $this->secret);
        $nonce     = base64_encode(random_bytes(16));
        $timestamp = (string) (int) round(microtime(true) * 1000);
        $signature = $svc->sign($encrypted . $nonce . $timestamp, $this->secret);

        return [
            'body'    => ['data' => $encrypted],
            'headers' => [
                'X-Api-Key'   => $device->api_key,
                'X-Device-Id' => $device->device_id,
                'X-Signature' => $signature,
                'X-Nonce'     => $nonce,
                'X-Timestamp' => $timestamp,
            ],
        ];
    }

    private function pendingTopup(User $user, float $amount)
    {
        return app(WalletService::class)->recordPendingTopup($user, $amount, null, 'promptpay');
    }

    public function test_crypto_roundtrip_matches_protocol(): void
    {
        $svc = app(SmsCheckerService::class);
        $payload = ['bank' => 'KBANK', 'type' => 'credit', 'amount' => '100.37', 'nonce' => 'n'];
        $enc = $svc->encryptPayload($payload, $this->secret);
        $this->assertSame($payload, $svc->decryptPayload($enc, $this->secret));
        // Wrong key must fail to decrypt (auth tag mismatch).
        $this->assertNull($svc->decryptPayload($enc, str_repeat('0', 64)));
    }

    public function test_notify_matches_reserved_topup_and_auto_credits(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);
        $device = $this->device('auto');

        $req = $this->notifyRequest($device, [
            'bank' => 'KBANK', 'type' => 'credit', 'amount' => '100.37',
            'sender_or_receiver' => 'ผู้โอน', 'reference_number' => 'REF1',
            'sms_timestamp' => (int) round(microtime(true) * 1000),
        ]);

        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])
            ->assertOk()
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(100.37, app(WalletService::class)->balance($user));
    }

    public function test_notify_no_match_records_pending(): void
    {
        $user = User::factory()->create();
        $this->pendingTopup($user, 100.37);
        $device = $this->device('auto');

        $req = $this->notifyRequest($device, [
            'bank' => 'SCB', 'type' => 'credit', 'amount' => '55.00',
            'sms_timestamp' => (int) round(microtime(true) * 1000),
        ]);

        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])
            ->assertOk()
            ->assertJsonPath('data.matched', false);

        $this->assertSame(0.0, app(WalletService::class)->balance($user));
        $this->assertSame(1, SmsPaymentNotification::where('status', 'pending')->count());
    }

    public function test_notify_rejects_replayed_nonce(): void
    {
        $user = User::factory()->create();
        $this->pendingTopup($user, 100.37);
        $device = $this->device('auto');
        $req = $this->notifyRequest($device, [
            'type' => 'credit', 'amount' => '100.37',
            'sms_timestamp' => (int) round(microtime(true) * 1000),
        ]);

        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])->assertOk();
        // Same nonce again → duplicate.
        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Duplicate request');
    }

    public function test_notify_rejects_bad_signature(): void
    {
        $device = $this->device('auto');
        $req = $this->notifyRequest($device, ['type' => 'credit', 'amount' => '10.00']);
        $req['headers']['X-Signature'] = base64_encode('tampered-signature-value-here-xx');

        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid signature');
    }

    public function test_invalid_api_key_rejected(): void
    {
        $this->postJson('/api/v1/sms-payment/notify', ['data' => 'x'], [
            'X-Api-Key' => 'nope', 'X-Signature' => 's', 'X-Nonce' => 'n', 'X-Timestamp' => '1',
        ])->assertStatus(401)->assertJsonPath('message', 'Invalid API key');
    }

    public function test_manual_mode_flags_but_does_not_credit(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);
        $device = $this->device('manual');

        $req = $this->notifyRequest($device, [
            'type' => 'credit', 'amount' => '100.37',
            'sms_timestamp' => (int) round(microtime(true) * 1000),
        ]);
        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'matched');

        // Not credited yet — still pending for admin.
        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, app(WalletService::class)->balance($user));
    }

    public function test_unique_amount_avoids_collisions(): void
    {
        $svc = app(SmsCheckerService::class);
        $user = User::factory()->create();
        $a = $svc->uniqueAmountFor(100);
        $this->pendingTopup($user, $a);
        $b = $svc->uniqueAmountFor(100);
        $this->assertNotSame($a, $b);
        $this->assertGreaterThanOrEqual(100.01, $a);
        $this->assertLessThanOrEqual(100.99, $a);
    }
}
