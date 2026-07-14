<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Mlm\MlmApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Guards the "ยอดตรงกับ Thaiprompt" sync layer: epoch-based cache busting,
 * the fetched_at stamp, and the web + mobile refresh endpoints.
 */
class MlmSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Mutable "upstream state" the single Http::fake closure reads live. */
    private float $upstreamAllTime = 1234.0;
    private bool $upstreamDown = false;
    /** Response body for POST /claim-referral (null → 404 invalid_code). */
    private ?array $upstreamClaim = null;

    private function linkedUser(): User
    {
        return User::factory()->create(['thaiprompt_token' => 'tp-token-123']);
    }

    /**
     * One dynamic stub for every /juntra/mlm/* endpoint — tests flip the
     * properties above between calls instead of re-registering stubs (which
     * would depend on Http::fake ordering semantics).
     */
    private function fakeUpstream(): void
    {
        Http::fake(function ($request) {
            if ($this->upstreamDown) {
                return Http::response(null, 503);
            }
            $url = $request->url();
            if (str_contains($url, '/juntra/mlm/claim-referral')) {
                return $this->upstreamClaim !== null
                    ? Http::response($this->upstreamClaim, 201)
                    : Http::response(['claimed' => false, 'reason_code' => 'invalid_code'], 404);
            }
            if (str_contains($url, '/juntra/mlm/stats')) {
                return Http::response([
                    'user'   => ['name' => 'ทดสอบ', 'referral_code' => 'TEST123'],
                    'totals' => ['today' => 10, 'this_month' => 100, 'all_time' => $this->upstreamAllTime],
                ]);
            }
            if (str_contains($url, '/juntra/mlm/tree')) {
                return Http::response([
                    'tree' => ['id' => 1, 'name' => 'ทดสอบ', 'children' => []],
                ]);
            }
            return Http::response([
                'data' => [], 'meta' => ['total' => 0, 'last_page' => 1, 'current_page' => 1],
            ]);
        });
    }

    /** Cached numbers stay pinned until bustCache bumps the epoch — then the next read is live. */
    public function test_bust_cache_makes_next_read_hit_upstream(): void
    {
        $u = $this->linkedUser();
        $this->upstreamAllTime = 1000.0;
        $this->fakeUpstream();

        $first = app(MlmApiClient::class)->stats($u);
        $this->assertSame(1000.0, (float) $first['totals']['all_time']);

        // Upstream total changes but the cache still serves the old figure…
        $this->upstreamAllTime = 2000.0;
        $cached = app(MlmApiClient::class)->stats($u);
        $this->assertSame(1000.0, (float) $cached['totals']['all_time'], 'should still be cached');

        // …until the epoch bump invalidates every cached key for this user.
        app(MlmApiClient::class)->bustCache($u);
        $fresh = app(MlmApiClient::class)->stats($u);
        $this->assertSame(2000.0, (float) $fresh['totals']['all_time'], 'must be live after bust');
    }

    /** Dashboard renders end-to-end with live upstream data (new org-chart blade). */
    public function test_web_dashboard_renders_with_linked_user(): void
    {
        $u = $this->linkedUser();
        $this->fakeUpstream();

        $r = $this->actingAs($u)->get(route('mlm.dashboard'));

        $r->assertOk()
            ->assertSee('ผังสายงาน')
            ->assertSee('TEST123')          // referral code surfaced
            ->assertSee('ดึงยอดสด')          // live-refresh button
            ->assertSee(route('referral', ['code' => 'TEST123']), false);
    }

    /** Web refresh endpoint busts the cache and lands back on the dashboard. */
    public function test_web_refresh_redirects_to_dashboard(): void
    {
        $u = $this->linkedUser();
        $this->fakeUpstream();

        $r = $this->actingAs($u)->post(route('mlm.refresh'));

        $r->assertRedirect(route('mlm.dashboard'))->assertSessionHas('status');
    }

    /** Mobile refresh: linked user gets {refreshed:true}; the next GET is live. */
    public function test_mobile_refresh_busts_cache_for_linked_user(): void
    {
        $u = $this->linkedUser();
        $this->upstreamAllTime = 500.0;
        $this->fakeUpstream();
        Sanctum::actingAs($u);

        // Prime the cache, then move upstream.
        $this->getJson('/api/v1/mlm/stats')->assertOk();
        $this->upstreamAllTime = 900.0;

        $this->postJson('/api/v1/mlm/refresh')->assertOk()->assertJsonPath('refreshed', true);

        $r = $this->getJson('/api/v1/mlm/stats');
        $r->assertOk();
        $this->assertSame(900.0, (float) $r->json('data.totals.all_time'));
    }

    /** Mobile refresh short-circuits with the standard not-linked envelope. */
    public function test_mobile_refresh_requires_thaiprompt_link(): void
    {
        Sanctum::actingAs(User::factory()->create()); // no token

        $this->postJson('/api/v1/mlm/refresh')
            ->assertStatus(403)
            ->assertJsonPath('reason_code', 'thaiprompt_not_linked');
    }

    /** Stats + tree envelopes carry fetched_at so the app can show "ข้อมูล ณ เวลา". */
    public function test_mobile_stats_envelope_includes_fetched_at(): void
    {
        $u = $this->linkedUser();
        $this->fakeUpstream();
        Sanctum::actingAs($u);

        $r = $this->getJson('/api/v1/mlm/stats');

        $r->assertOk()->assertJsonPath('linked', true);
        $this->assertNotEmpty($r->json('fetched_at'));
        $this->assertNotEmpty($this->getJson('/api/v1/mlm/tree')->json('fetched_at'));
    }

    /** Successful claim enrolls + busts the local cache so the tree shows the new sponsor line. */
    public function test_claim_referral_success_busts_local_cache(): void
    {
        $u = $this->linkedUser();
        $this->upstreamAllTime = 500.0;
        $this->upstreamClaim = [
            'claimed'     => true,
            'member_code' => 'MLMNEW1234',
            'sponsor'     => ['name' => 'ผู้เชิญ', 'member_code' => 'MLMABCD1234'],
        ];
        $this->fakeUpstream();

        // Prime cache, then claim — next read must be live.
        app(MlmApiClient::class)->stats($u);
        $this->upstreamAllTime = 800.0;

        $result = app(MlmApiClient::class)->claimReferral($u, 'MLMABCD1234');

        $this->assertTrue($result['claimed']);
        $this->assertSame('ผู้เชิญ', $result['sponsor']['name']);
        $this->assertSame(800.0, (float) app(MlmApiClient::class)->stats($u)['totals']['all_time']);
    }

    /** A definitive upstream rejection reports its reason and HTTP status (cookie consumed by caller). */
    public function test_claim_referral_rejection_reports_reason(): void
    {
        $u = $this->linkedUser();
        $this->fakeUpstream(); // upstreamClaim stays null → 404 invalid_code

        $result = app(MlmApiClient::class)->claimReferral($u, 'BADCODE');

        $this->assertFalse($result['claimed']);
        $this->assertSame('invalid_code', $result['reason_code']);
        $this->assertSame(404, $result['status']);
    }

    /** A failed upstream call must not pin empty numbers for the full 5-min TTL. */
    public function test_upstream_failure_is_not_cached_long(): void
    {
        $u = $this->linkedUser();
        $this->upstreamDown = true;
        $this->fakeUpstream();

        $down = app(MlmApiClient::class)->stats($u);
        $this->assertSame([], $down);

        // Upstream recovers → travel past the short failure TTL → live again.
        $this->upstreamDown = false;
        $this->upstreamAllTime = 777.0;
        $this->travel(30)->seconds();
        $up = app(MlmApiClient::class)->stats($u);
        $this->assertSame(777.0, (float) $up['totals']['all_time']);
    }
}
