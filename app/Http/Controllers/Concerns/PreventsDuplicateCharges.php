<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Idempotency for paid actions. A double-tap, a browser "resubmit", or a
 * flaky-network retry can fire the same paid POST twice within the throttle
 * window and debit twice. We block that with a short cache lock keyed by a
 * per-submit idempotency token:
 *
 *   - Web forms embed a hidden `_idem` UUID (one per rendered form), so
 *     resubmitting the SAME form reuses the token and is blocked, while a
 *     freshly loaded form gets a new token and is allowed.
 *   - The mobile app sends an `Idempotency-Key` header, generated per action.
 *
 * When no token is present we fall through (no idempotency) so older clients
 * keep working exactly as before.
 */
trait PreventsDuplicateCharges
{
    /**
     * @return Lock|null|false  null = no token (proceed), Lock = acquired,
     *                          false = an identical action is already in flight.
     */
    protected function guardCharge(Request $request, string $scope, int $ttl = 90): Lock|null|false
    {
        $token = $this->idempotencyToken($request);
        if ($token === null) {
            return null;
        }
        $lock = Cache::lock("charge:{$scope}:" . sha1($token), $ttl);
        return $lock->get() ? $lock : false;
    }

    protected function idempotencyToken(Request $request): ?string
    {
        $token = $request->header('Idempotency-Key') ?: $request->input('_idem');
        $token = is_string($token) ? trim($token) : '';
        return $token !== '' ? substr($token, 0, 64) : null;
    }
}
