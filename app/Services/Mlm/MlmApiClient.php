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

    /**
     * Oldest fetch time among the calls served during this request —
     * i.e. "ข้อมูล ณ เวลา X". Commissions are always fresh; stats/tree
     * may come from cache, so the oldest timestamp is the honest one.
     */
    private ?\Illuminate\Support\Carbon $lastFetchedAt = null;

    public function tree(User $actor, ?int $userId = null, int $depth = 5): array
    {
        $cacheKey = $this->key($actor, 'tree.target.' . ($userId ?? 'self') . ".d{$depth}");
        return $this->cachedGet($cacheKey, fn () => $this->client($actor)->get($this->url('/tree'), array_filter([
            'user_id' => $userId,
            'depth'   => $depth,
        ]))->json() ?? []);
    }

    public function commissions(User $actor, ?int $userId = null, int $page = 1, array $filters = []): array
    {
        $empty = ['data' => [], 'meta' => ['total' => 0, 'last_page' => 1, 'current_page' => 1]];

        // Pages aren't cached — operators want fresh data when paginating.
        $this->noteFetchedAt(now());
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
        $cacheKey = $this->key($actor, 'stats.target.' . ($userId ?? 'self'));
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
     * Bust every cached page for $actor — bumping the per-user epoch makes
     * every previously cached key unreachable at once (any depth, any admin
     * target). Backs the "รีเฟรชยอดสด" button so the next dashboard load
     * hits Thaiprompt live and the totals are guaranteed to match upstream.
     */
    public function bustCache(User $actor): void
    {
        Cache::forever("mlm.epoch.{$actor->id}", $this->epoch($actor) + 1);
    }

    /** When the data shown in this request was actually fetched from Thaiprompt. */
    public function lastFetchedAt(): ?\Illuminate\Support\Carbon
    {
        return $this->lastFetchedAt;
    }

    /**
     * Claim a referral upstream — enrolls $actor under the inviter whose
     * member_code is $code (captured from the จันทรา.online/r/{code} cookie).
     *
     * Returns ['claimed' => bool, 'reason_code' => ?string, 'status' => int,
     * 'sponsor' => ?array]. `status` 0 = network failure (caller should keep
     * the cookie and retry on a later login); any HTTP status = a definitive
     * upstream answer (caller can clear the cookie).
     */
    public function claimReferral(User $actor, string $code): array
    {
        try {
            $resp = $this->client($actor)->post($this->url('/claim-referral'), ['code' => $code]);
        } catch (\Throwable $e) {
            Log::warning('MlmApiClient /claim-referral threw', ['err' => $e->getMessage()]);
            return ['claimed' => false, 'reason_code' => 'network', 'status' => 0, 'sponsor' => null];
        }

        $json = $resp->json() ?: [];
        if ($resp->successful() && ($json['claimed'] ?? false) === true) {
            // Downline changed — make the next dashboard read live.
            $this->bustCache($actor);
        }

        return [
            'claimed'     => (bool) ($json['claimed'] ?? false),
            'reason_code' => $json['reason_code'] ?? null,
            'status'      => $resp->status(),
            'sponsor'     => is_array($json['sponsor'] ?? null) ? $json['sponsor'] : null,
        ];
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    private function url(string $path): string
    {
        $base = rtrim((string) Setting::get('thaiprompt_base_url', 'https://main.thaiprompt.online'), '/');
        return $base . '/api/v1/juntra/mlm' . $path;
    }

    /** Cache-key namespace versioned by the actor's epoch (see bustCache). */
    private function key(User $actor, string $suffix): string
    {
        return "mlm.v{$this->epoch($actor)}.{$actor->id}.{$suffix}";
    }

    private function epoch(User $actor): int
    {
        return (int) Cache::rememberForever("mlm.epoch.{$actor->id}", fn () => 1);
    }

    /** Keep the OLDEST fetch time of the request — the honest "ข้อมูล ณ เวลา" stamp. */
    private function noteFetchedAt(\Illuminate\Support\Carbon $ts): void
    {
        if ($this->lastFetchedAt === null || $ts->lt($this->lastFetchedAt)) {
            $this->lastFetchedAt = $ts;
        }
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
        $wrapped = Cache::get($key);

        if (!is_array($wrapped) || !array_key_exists('payload', $wrapped)) {
            $failed = false;
            try {
                $data = $fetch();
                if (!is_array($data)) {
                    Log::warning("MlmApiClient: non-array response for $key");
                    $data   = [];
                    $failed = true;
                }
            } catch (\Throwable $e) {
                Log::warning("MlmApiClient call failed for $key", ['err' => $e->getMessage()]);
                $data   = [];
                $failed = true;
            }
            // An empty payload is a failure in disguise (upstream 5xx bodies
            // json-decode to null → []). Real stats/tree payloads always have
            // keys; worst case a truly-empty payload just refetches sooner.
            $failed  = $failed || $data === [];
            $wrapped = ['payload' => $data, 'fetched_at' => now()->toIso8601String()];
            // A failed upstream call only lingers seconds — a Thaiprompt blip
            // must not pin zeroed-out totals on the dashboard for 5 minutes.
            Cache::put($key, $wrapped, $failed ? 20 : $ttl);
        }

        // Record when this payload was actually pulled from upstream.
        $ts = \Illuminate\Support\Carbon::make($wrapped['fetched_at'] ?? null);
        if ($ts !== null) {
            $this->noteFetchedAt($ts);
        }
        return is_array($wrapped['payload']) ? $wrapped['payload'] : [];
    }

    private function logFail($resp, string $endpoint): void
    {
        Log::warning("MlmApiClient: Thaiprompt $endpoint returned HTTP {$resp->status()}", [
            'body' => $resp->body(),
        ]);
    }
}
