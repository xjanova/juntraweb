<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Affiliate\MaeMorAffiliate;
use App\Services\Mlm\MlmApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Guards the "ยอดจากผังแม่หมอ" sync layer: epoch-based cache busting,
 * the fetched_at stamp, and the web + mobile refresh endpoints.
 *
 * 🌙 (2026-09-21) อ่านด้วยตัวตนของเซิร์ฟเวอร์จันทรา (/juntra/server/affiliate/*) — ลูกค้าทุกคน
 *   เห็นสายงานของตัวเองโดยไม่ต้องผูก Thaiprompt · ยังไม่อยู่ในผัง = ให้แม่หมอสร้างสมาชิกให้แล้วอ่านใหม่
 */
class MlmSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    /** Mutable "upstream state" the single Http::fake closure reads live. */
    private float $upstreamAllTime = 1234.0;
    private bool $upstreamDown = false;

    /** user_ref ที่แม่หมอรู้จักแล้ว (อ่านได้เลย) — คนอื่นได้ 404 not_enrolled จนกว่าจะเรียก /accounts */
    private array $enrolled = [];

    /** สิทธิ์รับค่าแนะนำ + ยอดกระเป๋า Thaiprompt ที่แม่หมอตอบ (null = แม่หมอรุ่นเก่ายังไม่ส่งมา) */
    private ?bool $upstreamEligible = null;

    private ?float $upstreamWallet = null;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', '9f1c-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'super-secret-value', 'thaiprompt', true);
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    /**
     * One dynamic stub for every /juntra/server/affiliate/* endpoint — tests flip the
     * properties above between calls instead of re-registering stubs (which
     * would depend on Http::fake ordering semantics).
     */
    private function fakeUpstream(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/oauth/token')) {
                return Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]);
            }
            if ($this->upstreamDown) {
                return Http::response(null, 503);
            }
            if (str_contains($url, '/affiliate/accounts')) {
                $this->enrolled[(int) $request['user_ref']] = true;

                return Http::response(['data' => [
                    'member_code' => 'TEST123',
                    'enrolled_now' => true,
                    'sponsor' => ['name' => 'ผู้เชิญ', 'member_code' => 'MLMABCD1234'],
                    'referral' => ['code' => $request['referral_code'] ?? null, 'applied' => isset($request['referral_code']), 'reason_code' => null],
                ]], 201);
            }
            if (preg_match('#/affiliate/members/(\d+)/#', $url, $m) && ! isset($this->enrolled[(int) $m[1]])) {
                return Http::response(['reason_code' => 'not_enrolled', 'message' => 'x'], 404);
            }
            if (str_contains($url, '/stats')) {
                return Http::response(array_filter([
                    'user' => ['name' => 'ทดสอบ', 'referral_code' => 'TEST123'],
                    'totals' => ['today' => 10, 'this_month' => 100, 'all_time' => $this->upstreamAllTime],
                    'mlm' => $this->upstreamEligible === null ? null : ['member_code' => 'TEST123', 'commission_eligible' => $this->upstreamEligible],
                    'wallet' => $this->upstreamWallet === null ? null : ['balance' => $this->upstreamWallet, 'currency' => 'THB'],
                ], fn ($v) => $v !== null));
            }
            if (str_contains($url, '/tree')) {
                return Http::response(['tree' => ['id' => 1, 'name' => 'ทดสอบ', 'children' => []]]);
            }

            return Http::response(['data' => [], 'meta' => ['total' => 0, 'last_page' => 1, 'current_page' => 1]]);
        });
    }

    /** Cached numbers stay pinned until bustCache bumps the epoch — then the next read is live. */
    public function test_bust_cache_makes_next_read_hit_upstream(): void
    {
        $u = $this->customer();
        $this->enrolled[$u->id] = true;
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

    /** Reads go out as the juntraweb server, keyed by this site's user id — never the customer's token. */
    public function test_reads_use_the_server_identity_and_local_user_id(): void
    {
        $u = $this->customer();
        $this->enrolled[$u->id] = true;
        $this->fakeUpstream();

        app(MlmApiClient::class)->stats($u);

        Http::assertSent(fn (Request $r) => $r->url() === self::TP . "/api/v1/juntra/server/affiliate/members/{$u->id}/stats"
            && $r->hasHeader('Authorization', 'Bearer srv-token'));
    }

    /** A customer who never linked Thaiprompt is enrolled on first view, then sees their line. */
    public function test_customer_without_thaiprompt_is_enrolled_then_sees_their_line(): void
    {
        $u = $this->customer(); // ไม่มี thaiprompt_token
        $this->fakeUpstream();

        $this->actingAs($u)->get(route('mlm.dashboard'))
            ->assertOk()
            ->assertSee('TEST123')
            ->assertDontSee('เข้าสู่ระบบด้วย Thaiprompt');

        $this->assertSame('TEST123', $u->fresh()->maemor_member_code);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/affiliate/accounts') && (int) $r['user_ref'] === $u->id);
    }

    /** Dashboard renders end-to-end with live upstream data (new org-chart blade). */
    public function test_web_dashboard_renders_for_a_member(): void
    {
        $u = $this->customer();
        $this->enrolled[$u->id] = true;
        $this->fakeUpstream();

        $r = $this->actingAs($u)->get(route('mlm.dashboard'));

        $r->assertOk()
            ->assertSee('ผังสายงาน')
            ->assertSee('TEST123')          // referral code surfaced
            ->assertSee('ดึงยอดสด')          // live-refresh button
            ->assertSee(route('referral', ['code' => 'TEST123']), false);

        // 🔍 (2026-09-23) เจ้าของสั่ง: ผังดูเต็มจอได้ และซูมด้วยลูกกลิ้งเมาส์ — ตัวสั่งงานต้องมากับหน้า
        $this->assertFileExists(public_path('js/org-chart-panzoom.js'));
        $r->assertSee('js/org-chart-panzoom.js', false)
            ->assertSee('toggleFullscreen()', false)
            ->assertSee('หมุนลูกกลิ้งเมาส์เพื่อซูม');
    }

    /**
     * 🌙 (2026-09-23) เจ้าของสั่ง: ผู้เชิญต้องเคยมีบิลที่ชำระแล้วจึงได้ค่าแนะนำ · ถอนที่เว็บ Thaiprompt
     *   ลูกค้าที่ผูก Thaiprompt แล้ว → เห็นสิทธิ์ ยอดในกระเป๋า Thaiprompt และปุ่มไปถอนที่นั่น
     */
    public function test_linked_customer_sees_eligibility_balance_and_the_thaiprompt_withdraw_page(): void
    {
        $u = User::factory()->create(['thaiprompt_user_id' => '4242']);
        $this->enrolled[$u->id] = true;
        $this->upstreamEligible = true;
        $this->upstreamWallet = 123.5;
        $this->fakeUpstream();

        $this->actingAs($u)->get(route('mlm.dashboard'))
            ->assertOk()
            ->assertSee('มีสิทธิ์รับค่าแนะนำแล้ว')
            ->assertSee('฿123.50')
            ->assertSee('ถอนที่เว็บ Thaiprompt')
            ->assertSee(self::TP.'/user/wallet/withdraw', false)
            ->assertDontSee('เชื่อมบัญชี Thaiprompt เพื่อถอน')
            // จ่ายแค่ 2 ชั้นและต้องเคยมีบิล — ห้ามโฆษณาว่า "ทุกบิลของทีม"
            ->assertDontSee('ทุกบิลดูดวงของทีม')
            ->assertSee('เมื่อคุณเคยมีบิลดูดวงที่ชำระแล้วอย่างน้อย 1 บิล');
    }

    /** ยังไม่ผูก Thaiprompt → ต้องเชื่อมบัญชีก่อนถอน (ยอดที่สะสมย้ายตามไป) · ยังไม่เคยมีบิล → บอกว่ายังไม่มีสิทธิ์ */
    public function test_unlinked_customer_is_told_to_link_thaiprompt_before_withdrawing(): void
    {
        $u = $this->customer();
        $this->enrolled[$u->id] = true;
        $this->upstreamEligible = false;
        $this->upstreamWallet = 0.0;
        $this->fakeUpstream();

        $this->actingAs($u)->get(route('mlm.dashboard'))
            ->assertOk()
            ->assertSee('ยังไม่มีสิทธิ์รับค่าแนะนำ')
            ->assertSee('เชื่อมบัญชี Thaiprompt เพื่อถอน')
            ->assertSee(route('thaiprompt.redirect', ['to' => '/mlm']), false)
            ->assertDontSee(self::TP.'/user/wallet/withdraw', false);
    }

    /** Web refresh endpoint busts the cache and lands back on the dashboard. */
    public function test_web_refresh_redirects_to_dashboard(): void
    {
        $u = $this->customer();
        $this->fakeUpstream();

        $r = $this->actingAs($u)->post(route('mlm.refresh'));

        $r->assertRedirect(route('mlm.dashboard'))->assertSessionHas('status');
    }

    /** Mobile refresh: any customer gets {refreshed:true}; the next GET is live. */
    public function test_mobile_refresh_busts_cache(): void
    {
        $u = $this->customer();
        $this->enrolled[$u->id] = true;
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

    /** แอพ: ลูกค้าที่ไม่เคยผูก Thaiprompt ต้องเห็นสายงาน (เดิมได้ 403 thaiprompt_not_linked) */
    public function test_mobile_customer_without_thaiprompt_is_not_turned_away(): void
    {
        Sanctum::actingAs($this->customer());
        $this->fakeUpstream();

        $this->getJson('/api/v1/mlm/stats')
            ->assertOk()
            ->assertJsonPath('linked', true)
            ->assertJsonPath('data.user.referral_code', 'TEST123');
        $this->postJson('/api/v1/mlm/refresh')->assertOk();
    }

    /** Stats + tree envelopes carry fetched_at so the app can show "ข้อมูล ณ เวลา". */
    public function test_mobile_stats_envelope_includes_fetched_at(): void
    {
        $u = $this->customer();
        $this->enrolled[$u->id] = true;
        $this->fakeUpstream();
        Sanctum::actingAs($u);

        $r = $this->getJson('/api/v1/mlm/stats');

        $r->assertOk()->assertJsonPath('linked', true);
        $this->assertNotEmpty($r->json('fetched_at'));
        $this->assertNotEmpty($this->getJson('/api/v1/mlm/tree')->json('fetched_at'));
    }

    /** รหัสเชิญถูกใช้แล้ว (แม่หมอตอบชัด) → ล้างทิ้ง + จำรหัสสมาชิกของลูกค้าไว้ทำลิงก์เชิญต่อ */
    public function test_referral_code_is_consumed_once_the_tree_answers(): void
    {
        $u = User::factory()->create(['pending_referral_code' => 'MLMABCD1234']);
        $this->fakeUpstream();

        $result = app(MaeMorAffiliate::class)->ensureMember($u);

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['data']['referral']['applied']);
        $u->refresh();
        $this->assertNull($u->pending_referral_code);
        $this->assertSame('TEST123', $u->maemor_member_code);
    }

    /** แม่หมอล่ม (5xx) ต้องไม่ทำรหัสเชิญหาย — เดิมถูกลบทิ้งทันที ผู้เชิญเสียลูกทีมเงียบ ๆ */
    public function test_referral_code_survives_an_outage(): void
    {
        $u = User::factory()->create(['pending_referral_code' => 'MLMABCD1234']);
        $this->upstreamDown = true;
        $this->fakeUpstream();

        $this->assertSame('unavailable', app(MaeMorAffiliate::class)->ensureMember($u)['status']);
        $this->assertSame('MLMABCD1234', $u->fresh()->pending_referral_code);
    }

    /** A failed upstream call must not pin empty numbers for the full 5-min TTL. */
    public function test_upstream_failure_is_not_cached_long(): void
    {
        $u = $this->customer();
        $this->enrolled[$u->id] = true;
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

    /** Accept header ของเบราว์เซอร์จริง — ไม่ใช่ XHR */
    private const BROWSER = ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];

    /**
     * เมนู "คอมมิชชั่นดูดวง" ในหลังบ้าน Thaiprompt ลิงก์มาที่ /mlm/commissions ตรง ๆ
     * ซึ่งเป็นปลายทาง XHR ของตาราง → เดิมผู้ใช้เจอ JSON ดิบเต็มจอ
     */
    public function test_browser_hitting_commissions_lands_on_the_dashboard_table(): void
    {
        $u = $this->customer();
        $this->fakeUpstream();

        $this->actingAs($u)->get(route('mlm.commissions'), self::BROWSER)
            ->assertRedirect(route('mlm.dashboard').'#commissions');
    }

    /** ตารางในหน้าแดชบอร์ดยังต้องได้ JSON เหมือนเดิม (อย่าแก้จนพังของเดิม) */
    public function test_commissions_still_serves_json_to_the_table_xhr(): void
    {
        $u = $this->customer();
        $this->enrolled[$u->id] = true;
        $this->fakeUpstream();

        $this->actingAs($u)
            ->get(route('mlm.commissions'), ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    /** เมนู "ทีมดูดวงของฉัน" ก็ลิงก์มาที่ /mlm/users — สมาชิกทั่วไปเคยเจอ 403 */
    public function test_browser_hitting_users_does_not_403_a_regular_member(): void
    {
        $u = $this->customer(); // ไม่ใช่แอดมิน
        $this->fakeUpstream();

        $this->actingAs($u)->get(route('mlm.users'), self::BROWSER)
            ->assertRedirect(route('mlm.dashboard').'#team');
    }

    /** ช่องค้นหาผู้ใช้ของแอดมินยังเป็น JSON และยังปิดไม่ให้สมาชิกทั่วไปเรียก */
    public function test_users_json_stays_admin_only(): void
    {
        $this->fakeUpstream();

        $this->actingAs($this->customer())->getJson(route('mlm.users'))->assertForbidden();
    }

    /** แอดมินที่ไม่ได้ผูก Thaiprompt เปิดหน้าสายงานเพื่อตรวจ — ต้องไม่ถูกสร้างเป็นสมาชิกใต้ผู้แนะนำเริ่มต้น */
    public function test_unlinked_admin_viewing_the_page_is_not_enrolled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->fakeUpstream();

        $this->actingAs($admin)->get(route('mlm.dashboard'))->assertOk();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/affiliate/accounts'));
        $this->assertNull($admin->fresh()->maemor_member_code);
    }

    /** สมาชิกทั่วไปส่ง ?user_id= มา = ยังได้ของตัวเอง (id สองฝั่งคนละชุด ห้ามดูของคนอื่น) */
    public function test_member_cannot_peek_at_another_line(): void
    {
        $u = $this->customer();
        $this->enrolled[$u->id] = true;
        $this->fakeUpstream();

        $this->actingAs($u)->get(route('mlm.dashboard', ['user_id' => 999]))->assertOk();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/affiliate/admin/'));
    }
}
