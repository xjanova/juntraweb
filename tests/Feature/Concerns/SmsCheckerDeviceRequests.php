<?php

namespace Tests\Feature\Concerns;

use App\Models\SmsCheckerDevice;
use App\Services\SmsPayment\SmsCheckerService;

/**
 * Build requests exactly like the SmsChecker Android app does
 * (thaiprompt-smschecker-v1: AES-256-GCM payload + HMAC over
 * encrypted_data ‖ nonce ‖ timestamp, device auth by X-Api-Key).
 */
trait SmsCheckerDeviceRequests
{
    protected string $secret = 'b4f1c0de00112233445566778899aabbccddeeff00112233445566778899aabb';

    protected function device(string $mode = 'auto', string $status = 'active'): SmsCheckerDevice
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

    /** Build the encrypted + signed /notify (or /notify-action) request the app would send. */
    protected function notifyRequest(SmsCheckerDevice $device, array $payload): array
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

    /** Plain device-auth headers for the GET/PUT endpoints (no payload crypto). */
    protected function deviceHeaders(SmsCheckerDevice $device): array
    {
        return ['X-Api-Key' => $device->api_key, 'X-Device-Id' => $device->device_id];
    }
}
