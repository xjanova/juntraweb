<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyChatSlipCallback implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.thaiprompt.chat_slip_ping_token', '');
        $provided = $request->bearerToken();

        if (strlen($expected) < 32 || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'reason_code' => 'unauthenticated',
            ], 401);
        }

        return $next($request);
    }
}
