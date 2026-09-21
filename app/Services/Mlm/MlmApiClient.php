<?php

namespace App\Services\Mlm;

use App\Models\User;
use App\Services\Affiliate\MaeMorAffiliate;
use App\Services\Thaiprompt\JuntraServerClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * อ่านผังแม่หมอ (Thaiprompt) มาแสดงบนเว็บ/แอพ — ตัวเลขทุกตัวมาจากที่นั่น ไม่คำนวณซ้ำที่นี่
 *
 * 🌙 (2026-09-21) ยิงด้วยตัวตนของเซิร์ฟเวอร์จันทรา (/api/v1/juntra/server/affiliate/*)
 *   เดิมใช้ token Thaiprompt ของลูกค้า → ลูกค้าที่สมัครด้วยเบอร์/อีเมล (ส่วนใหญ่) ไม่เห็นสายงานเลย
 *   ตอนนี้ลูกค้าทุกคนเห็นของตัวเอง · แอดมินดูของคนอื่นด้วย id ผู้ใช้ฝั่ง Thaiprompt (?user_id=)
 *
 *   ของตัวเอง:   /members/{users.id ของเว็บนี้}/{stats,tree,commissions}
 *   แอดมินดูคนอื่น: /admin/users/{id ผู้ใช้ Thaiprompt}/{stats,tree,commissions} · /admin/users
 *
 * cache ต่อผู้ใช้ 5 นาที (ประวัติค่าแนะนำไม่ cache) · ปุ่ม "ดึงยอดสด" ล้างด้วย bustCache()
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
    private ?Carbon $lastFetchedAt = null;

    public function __construct(
        private JuntraServerClient $server,
        private MaeMorAffiliate $affiliate,
    ) {}

    public function tree(User $actor, ?int $userId = null, int $depth = 5): array
    {
        $cacheKey = $this->key($actor, 'tree.target.' . ($userId ?? 'self') . ".d{$depth}");

        return $this->cachedGet($cacheKey, fn () => $this->read($actor, $userId, '/tree', ['depth' => $depth]));
    }

    public function commissions(User $actor, ?int $userId = null, int $page = 1, array $filters = []): array
    {
        $empty = ['data' => [], 'meta' => ['total' => 0, 'last_page' => 1, 'current_page' => 1]];

        // Pages aren't cached — operators want fresh data when paginating.
        $this->noteFetchedAt(now());
        $data = $this->read($actor, $userId, '/commissions', array_filter([
            'page' => $page,
            'status' => $filters['status'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'per_page' => $filters['per_page'] ?? null,
        ]));

        return $data ?? $empty;
    }

    public function stats(User $actor, ?int $userId = null): array
    {
        $cacheKey = $this->key($actor, 'stats.target.' . ($userId ?? 'self'));

        return $this->cachedGet($cacheKey, fn () => $this->read($actor, $userId, '/stats'));
    }

    /** Admin-only — ผู้ใช้ที่มีกิจกรรมดูดวง (ตัวเลือก "ดูข้อมูลของใคร") */
    public function users(User $actor, string $q = '', int $perPage = 50): array
    {
        $cacheKey = 'mlm.users.q.' . md5($q) . ".pp{$perPage}";

        return $this->cachedGet($cacheKey, function () use ($q, $perPage) {
            $res = $this->server->affiliate('GET', '/admin/users', array_filter([
                'q' => $q !== '' ? $q : null,
                'per_page' => $perPage,
            ]));

            return $res['status'] === 'ok' ? (array) $res['data'] : ['data' => [], 'meta' => ['total' => 0]];
        }, 60);
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
    public function lastFetchedAt(): ?Carbon
    {
        return $this->lastFetchedAt;
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    /**
     * อ่านหนึ่งเส้น — ของตัวเองยังไม่อยู่ในผัง (not_enrolled) → ให้ Thaiprompt สร้างสมาชิกให้แล้วอ่านใหม่
     *
     * @return array|null null = ต่อไม่ได้/ถูกปฏิเสธ (หน้าเว็บแสดงว่ายังดึงข้อมูลไม่ได้)
     */
    private function read(User $actor, ?int $targetUserId, string $what, array $query = []): ?array
    {
        $path = $targetUserId !== null
            ? "/admin/users/{$targetUserId}{$what}"
            : "/members/{$actor->id}{$what}";

        $res = $this->server->affiliate('GET', $path, $query);

        // แอดมินที่ไม่ได้ผูก Thaiprompt เปิดหน้านี้เพื่อตรวจงาน ไม่ได้มาเป็นสมาชิก — ห้ามสร้างตัวตนเงาให้
        $mayEnroll = ! $actor->isAdmin() || $actor->thaiprompt_user_id;
        if ($targetUserId === null && $res['reason_code'] === 'not_enrolled' && $mayEnroll
            && $this->affiliate->ensureMember($actor)['status'] === 'ok') {
            $res = $this->server->affiliate('GET', $path, $query);
        }

        if ($res['status'] !== 'ok') {
            Log::warning("MlmApiClient: Thaiprompt {$path} → {$res['status']}", [
                'code' => $res['code'],
                'reason' => $res['reason_code'],
            ]);

            return null;
        }

        return (array) $res['data'];
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
    private function noteFetchedAt(Carbon $ts): void
    {
        if ($this->lastFetchedAt === null || $ts->lt($this->lastFetchedAt)) {
            $this->lastFetchedAt = $ts;
        }
    }

    private function cachedGet(string $key, callable $fetch, int $ttl = self::CACHE_TTL): array
    {
        $wrapped = Cache::get($key);

        if (! is_array($wrapped) || ! array_key_exists('payload', $wrapped)) {
            $failed = false;
            try {
                $data = $fetch();
                if (! is_array($data)) {
                    $data = [];
                    $failed = true;
                }
            } catch (\Throwable $e) {
                Log::warning("MlmApiClient call failed for $key", ['err' => $e->getMessage()]);
                $data = [];
                $failed = true;
            }
            // An empty payload is a failure in disguise. Real stats/tree payloads
            // always have keys; worst case a truly-empty payload refetches sooner.
            $failed = $failed || $data === [];
            $wrapped = ['payload' => $data, 'fetched_at' => now()->toIso8601String()];
            // A failed upstream call only lingers seconds — a Thaiprompt blip
            // must not pin zeroed-out totals on the dashboard for 5 minutes.
            Cache::put($key, $wrapped, $failed ? 20 : $ttl);
        }

        // Record when this payload was actually pulled from upstream.
        $ts = Carbon::make($wrapped['fetched_at'] ?? null);
        if ($ts !== null) {
            $this->noteFetchedAt($ts);
        }

        return is_array($wrapped['payload']) ? $wrapped['payload'] : [];
    }
}
