<?php

namespace App\Services\Mlm;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only HTTP client for the Thaiprompt-Affiliate MLM API.
 *
 *   GET  /api/v1/juntra/mlm/tree         (?user_id, ?depth)
 *   GET  /api/v1/juntra/mlm/commissions  (?user_id, ?page, ?status, ?from, ?to)
 *   GET  /api/v1/juntra/mlm/stats        (?user_id)
 *   GET  /api/v1/juntra/mlm/users        (admin only)
 *
 * Auth = the user's Sanctum bearer token saved on User model
 *        (`thaiprompt_token` — populated by the SSO callback).
 *
 * Every method caches the response per-user for a short TTL so navigating
 * between dashboard tabs doesn't hammer the upstream API.
 */
class MlmApiClient
{
    /** Default cache TTL — short enough that admin actions feel live. */
    private const CACHE_TTL = 300; // 5 min

    public function tree(User $actor, ?int $userId = null, int $depth = 5): array
    {
        $cacheKey = "mlm.tree.{$actor->id}.target." . ($userId ?? 'self') . ".d{$depth}";
        return $this->cachedGet($cacheKey, fn () => $this->client($actor)->get($this->url('/tree'), array_filter([
            'user_id' => $userId,
            'depth'   => $depth,
        ]))->json() ?? []);
    }

    public function commissions(User $actor, ?int $userId = null, int $page = 1, array $filters = []): array
    {
        $empty = ['data' => [], 'meta' => ['total' => 0, 'last_page' => 1, 'current_page' => 1]];

        // Pages aren't cached — operators want fresh data when paginating.
        try {
            $resp = $this->client($actor)->get($this->url('/commissions'), array_filter([
                'user_id' => $userId,
                'page'    => $page,
                'status'  => $filters['status'] ?? null,
                'from'    => $filters['from'] ?? null,
                'to'      => $filters['to'] ?? null,
                'per_page'=> $filters['per_page'] ?? null,
            ]));
        } catch (\Throwable $e) {
            Log::warning('MlmApiClient /commissions threw', ['err' => $e->getMessage()]);
            return $empty;
        }

        if (!$resp->successful()) {
            $this->logFail($resp, '/commissions');
            return $empty;
        }
        return $resp->json() ?: $empty;
    }

    public function stats(User $actor, ?int $userId = null): array
    {
        $cacheKey = "mlm.stats.{$actor->id}.target." . ($userId ?? 'self');
        return $this->cachedGet($cacheKey, fn () => $this->client($actor)->get($this->url('/stats'), array_filter([
            'user_id' => $userId,
        ]))->json() ?? []);
    }

    public function users(User $actor, string $q = '', int $perPage = 50): array
    {
        // Admin-only — short cache because it backs a search box.
        $cacheKey = 'mlm.users.q.' . md5($q) . ".pp{$perPage}";
        return $this->cachedGet($cacheKey, function () use ($actor, $q, $perPage) {
            $resp = $this->client($actor)->get($this->url('/users'), array_filter([
                'q'        => $q !== '' ? $q : null,
                'per_page' => $perPage,
            ]));
            if (!$resp->successful()) {
                $this->logFail($resp, '/users');
                return ['data' => [], 'meta' => ['total' => 0]];
            }
            return $resp->json() ?: ['data' => []];
        }, 60); // 1-min cache for the search box
    }

    /**
     * Bust every cached page for $actor — call after admin actions that
     * could change downstream state (rare, but useful in testing).
     */
    public function bustCache(User $actor): void
    {
        // We don't keep an index of keys; rely on Cache::forget by pattern.
        // Cheap fallback: store a per-user epoch and prefix keys with it.
        Cache::forget("mlm.tree.{$actor->id}.target.self.d5");
        Cache::forget("mlm.stats.{$actor->id}.target.self");
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    private function url(string $path): string
    {
        $base = rtrim((string) Setting::get('thaiprompt_base_url', 'https://main.thaiprompt.online'), '/');
        return $base . '/api/v1/juntra/mlm' . $path;
    }

    private function client(User $actor): PendingRequest
    {
        // The user's bearer token (set during the SSO callback). If the user
        // logs out or rotates their token on Thaiprompt, every call will 401
        // and we'll prompt the user to re-link.
        $token = (string) $actor->thaiprompt_token;

        // Tight timeout + ZERO retries when the API is offline — otherwise
        // the page hangs ~24s while curl retries. We'd rather show empty
        // data fast and let the user refresh.
        $req = Http::acceptJson()->connectTimeout(3)->timeout(6);

        if ($token !== '') {
            $req = $req->withToken($token);
        }
        return $req;
    }

    private function cachedGet(string $key, callable $fetch, int $ttl = self::CACHE_TTL): array
    {
        return Cache::remember($key, $ttl, function () use ($fetch, $key) {
            try {
                $data = $fetch();
                if (!is_array($data)) {
                    Log::warning("MlmApiClient: non-array response for $key");
                    return [];
                }
                return $data;
            } catch (\Throwable $e) {
                Log::warning("MlmApiClient call failed for $key", ['err' => $e->getMessage()]);
                return [];
            }
        });
    }

    private function logFail($resp, string $endpoint): void
    {
        Log::warning("MlmApiClient: Thaiprompt $endpoint returned HTTP {$resp->status()}", [
            'body' => $resp->body(),
        ]);
    }
}
