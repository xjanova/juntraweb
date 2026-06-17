<?php

namespace App\Http\Middleware;

use App\Models\SmsCheckerDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate an SMS Checker device by its X-Api-Key. On success the device
 * is attached to the request (`sms_checker_device`) for the controller. The
 * device_id auto-syncs if the app reports a new one (the app's device_id is a
 * single global value but each backend stores its own copy).
 */
class VerifySmsCheckerDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Api-Key');
        if (! $apiKey) {
            return response()->json(['success' => false, 'message' => 'API key is required'], 401);
        }

        $device = SmsCheckerDevice::findByApiKey($apiKey);
        if (! $device) {
            Log::warning('SmsChecker: invalid API key', ['ip' => $request->ip()]);
            return response()->json(['success' => false, 'message' => 'Invalid API key'], 401);
        }

        if (! $device->isActive()) {
            return response()->json(['success' => false, 'message' => 'Device is ' . $device->status], 403);
        }

        $deviceId = $request->header('X-Device-Id');
        if ($deviceId && $device->device_id !== $deviceId) {
            $device->forceFill(['device_id' => $deviceId])->save();
        }

        $request->attributes->set('sms_checker_device', $device);

        return $next($request);
    }
}
