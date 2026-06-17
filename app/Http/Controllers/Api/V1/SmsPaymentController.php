<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SmsCheckerDevice;
use App\Models\SmsPaymentNotification;
use App\Services\SmsPayment\SmsCheckerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SMS Checker device API (thaiprompt-smschecker-v1). The Android app POSTs an
 * encrypted, HMAC-signed bank-SMS to /notify; we verify the device, decrypt,
 * guard against replay, and hand it to SmsCheckerService to match a reserved
 * wallet top-up and auto-credit the wallet.
 *
 * Full path: /api/v1/sms-payment/*
 */
class SmsPaymentController extends Controller
{
    public function __construct(private SmsCheckerService $sms) {}

    public function notify(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');

        $signature = $request->header('X-Signature');
        $nonce     = $request->header('X-Nonce');
        $timestamp = $request->header('X-Timestamp');
        if (! $signature || ! $nonce || ! $timestamp) {
            return $this->err('Missing required security headers', 400);
        }

        // Reject stale / clock-skewed requests (replay window).
        $window = (int) config('smschecker.timestamp_window_seconds', 300) * 1000;
        if (! is_numeric($timestamp) || abs((int) (microtime(true) * 1000) - (int) $timestamp) > $window) {
            return $this->err('Request timestamp expired', 400);
        }

        $encrypted = (string) $request->input('data', '');
        if ($encrypted === '') {
            return $this->err('No payload data', 400);
        }

        // HMAC over encrypted_payload + nonce + timestamp.
        if (! $this->sms->verifySignature($encrypted . $nonce . $timestamp, $signature, $device->secret_key)) {
            return $this->err('Invalid signature', 401);
        }

        // Replay guard — nonce may be used once. Cache for the fast path; the DB
        // unique index on `nonce` is the durable backstop.
        $nonceKey = 'smschecker:nonce:' . sha1($nonce);
        if (Cache::has($nonceKey) || SmsPaymentNotification::where('nonce', $nonce)->exists()) {
            return $this->err('Duplicate request', 400);
        }

        $payload = $this->sms->decryptPayload($encrypted, $device->secret_key);
        if ($payload === null) {
            return $this->err('Failed to decrypt payload', 400);
        }
        if (! isset($payload['amount']) || ! is_numeric($payload['amount']) || (float) $payload['amount'] < 0.01) {
            return $this->err('Invalid payload data', 422);
        }
        // Carry the verified nonce into the stored record for the unique index.
        $payload['nonce'] = $nonce;

        try {
            $result = $this->sms->processNotification($payload, $device, (string) $request->ip());
        } catch (\Illuminate\Database\QueryException $e) {
            // Concurrent same-nonce slipped past the cache check → unique violation.
            return $this->err('Duplicate request', 400);
        } catch (\Throwable $e) {
            Log::error('SmsChecker notify failed', ['err' => $e->getMessage()]);
            return $this->err('Internal error processing notification', 500);
        }

        Cache::put($nonceKey, 1, config('smschecker.nonce_ttl_seconds', 900));

        return response()->json([
            'success' => true,
            'message' => $result['matched'] ? 'Payment matched and confirmed' : 'Notification recorded',
            'data'    => $result,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');
        $pending = SmsPaymentNotification::where('device_id', $device->device_id)
            ->where('status', 'pending')->count();

        return response()->json([
            'success'       => true,
            'status'        => $device->status,
            'pending_count' => $pending,
            'message'       => null,
        ]);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');
        $data = $request->validate([
            'device_id'   => 'sometimes|string|max:64',
            'device_name' => 'sometimes|nullable|string|max:120',
            'platform'    => 'sometimes|string|max:16',
            'app_version' => 'sometimes|nullable|string|max:32',
        ]);
        $device->forceFill(array_filter([
            'device_name' => $data['device_name'] ?? $device->device_name,
            'platform'    => $data['platform'] ?? $device->platform,
            'app_version' => $data['app_version'] ?? $device->app_version,
            'last_active_at' => now(),
        ], fn ($v) => $v !== null))->save();

        return response()->json(['success' => true, 'message' => 'Device registered successfully']);
    }

    public function registerFcmToken(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');
        $data = $request->validate(['fcm_token' => 'required|string|max:255']);
        $device->forceFill(['fcm_token' => $data['fcm_token']])->save();

        return response()->json(['success' => true, 'message' => 'FCM token registered successfully']);
    }

    public function getDeviceSettings(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');
        return response()->json(['success' => true, 'data' => ['approval_mode' => $device->getApprovalMode()]]);
    }

    public function updateDeviceSettings(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');
        $data = $request->validate(['approval_mode' => 'required|in:auto,manual']);
        $device->forceFill(['approval_mode' => $data['approval_mode']])->save();

        return response()->json(['success' => true, 'data' => ['approval_mode' => $device->approval_mode]]);
    }

    private function err(string $message, int $code): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $code);
    }
}
