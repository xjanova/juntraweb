<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * สมัครครั้งแรกผ่าน Thaiprompt SSO ต้องสำเร็จ
 *
 * บั๊กที่เทสต์ชุดนี้ตรึงไว้: ThaipromptController เคยตั้ง `$user->role = 'user'`
 * ให้ผู้ใช้ใหม่ ทั้งที่คอลัมน์เป็น `enum('admin','editor','member')`
 * (2026_05_07_000003_add_role_to_users.php) — MySQL โหมด strict ตีกลับตอน save()
 * ผู้ใช้ใหม่ทุกคนที่เข้ามาทาง SSO จึงสมัครไม่ผ่านเลย ส่วนคนเก่าไม่เจอเพราะ
 * query หาเจอก่อนแล้วไม่แตะ role — บั๊กจึงยิงเฉพาะกลุ่มที่ MLM ต้องการที่สุด
 * และไม่มีเทสต์ตัวใดครอบเส้นทางนี้มาก่อนเลย
 */
class ThaipromptSsoSignupTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tp.test';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_enabled', '1');
        Setting::put('thaiprompt_base_url', self::BASE);
        Setting::put('thaiprompt_client_id', 'test-client');
        Setting::put('thaiprompt_client_secret', 'test-secret');
    }

    /** ยิง callback ด้วย state ที่ตรงกับใน session */
    private function completeCallback(array $profile, array $session = [])
    {
        Http::fake([
            'tp.test/oauth/token' => Http::response([
                'access_token'  => 'access-token-123',
                'refresh_token' => 'refresh-token-123',
                'expires_in'    => 3600,
            ]),
            'tp.test/api/user' => Http::response($profile),
            '*'                => Http::response([], 200),
        ]);

        $state = Str::random(40);

        return $this->withSession(['thaiprompt_oauth_state' => $state] + $session)
            ->get('/auth/thaiprompt/callback?code=auth-code&state=' . $state);
    }

    /** ผู้ใช้ใหม่ล้วน — ต้องสร้างบัญชีได้ ไม่ใช่ระเบิดตอน save() */
    public function test_brand_new_user_can_sign_up_through_sso(): void
    {
        $res = $this->completeCallback([
            'id'    => '9001',
            'email' => 'NewSeeker@Example.com',
            'name'  => 'ผู้ใช้ใหม่',
        ]);

        $res->assertRedirect();
        $res->assertSessionHasNoErrors();

        $user = User::where('email', 'newseeker@example.com')->first();
        $this->assertNotNull($user, 'SSO ต้องสร้างผู้ใช้ใหม่ได้');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('9001', $user->thaiprompt_user_id);
    }

    /**
     * role ที่บันทึกต้องเป็นค่าที่มีอยู่จริงในเอนัมเท่านั้น
     *
     * ตรึงตรง ๆ เพราะค่านอกเอนัมจะผ่านบน SQLite บางรุ่นแต่ตายบน MySQL strict
     * — ถ้าใครเผลอใส่ 'user' กลับมาอีก เทสต์นี้ต้องแดงทันที
     */
    public function test_new_sso_user_gets_a_role_that_exists_in_the_enum(): void
    {
        $this->completeCallback([
            'id'    => '9002',
            'email' => 'rolecheck@example.com',
            'name'  => 'ตรวจ role',
        ]);

        $user = User::where('email', 'rolecheck@example.com')->firstOrFail();

        $this->assertContains(
            $user->role,
            ['admin', 'editor', 'member'],
            "role ต้องอยู่ในเอนัมของตาราง users — ได้ '{$user->role}'"
        );
        $this->assertSame('member', $user->role, 'ผู้ใช้ทั่วไปต้องได้ default = member');
    }

    /** ผู้ใช้เดิมที่มีอยู่แล้ว ต้องถูกผูกเข้ากับ Thaiprompt ไม่ใช่สร้างซ้ำ */
    public function test_existing_user_is_linked_not_duplicated(): void
    {
        $existing = User::factory()->create([
            'email' => 'oldseeker@example.com',
            'role'  => 'member',
        ]);

        $this->completeCallback([
            'id'    => '9003',
            'email' => 'oldseeker@example.com',
            'name'  => 'ลูกค้าเก่า',
        ]);

        $this->assertSame(1, User::where('email', 'oldseeker@example.com')->count());

        $existing->refresh();
        $this->assertSame('9003', $existing->thaiprompt_user_id);
        $this->assertTrue($existing->isThaipromptLinked());
        $this->assertSame('member', $existing->role, 'การลิงก์ต้องไม่ไปแตะ role ของคนเก่า');
    }

    /**
     * 🌙 (2026-09-21) ลูกค้าที่ซื้อบนจันทราก่อนผูก Thaiprompt มีบัญชีเงาในผังแม่หมอ —
     * ผูกเมื่อไรต้องบอกแม่หมอทันที (หลังตอบหน้าเว็บ) ให้รวมเข้าบัญชี Thaiprompt (เจ้าของสั่ง: Thaiprompt เป็นตัวหลัก)
     */
    public function test_first_link_tells_the_tree_so_the_shadow_account_is_merged(): void
    {
        User::factory()->create(['email' => 'buyer@example.com', 'role' => 'member']);

        $this->completeCallback(['id' => '9004', 'email' => 'buyer@example.com', 'name' => 'ลูกค้าซื้อก่อนผูก'])
            ->assertRedirect();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/affiliate/accounts')
            && (string) $r['thaiprompt_user_id'] === '9004');
    }

    /** ล็อกอินซ้ำโดยไม่มีอะไรเปลี่ยน = ไม่ต้องรบกวนแม่หมอทุกครั้ง */
    public function test_relogin_with_the_same_link_does_not_call_the_tree(): void
    {
        User::factory()->create(['email' => 'linked@example.com', 'role' => 'member', 'thaiprompt_user_id' => '9005']);

        $this->completeCallback(['id' => '9005', 'email' => 'linked@example.com', 'name' => 'ลูกค้าผูกแล้ว'])
            ->assertRedirect();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/affiliate/'));
    }

    /**
     * 🔗 (2026-09-23) ลูกค้าที่ล็อกอินอยู่กดเชื่อม Thaiprompt ต้องผูกเข้าบัญชีนี้ — ไม่ใช่ได้บัญชีใหม่
     *
     * บั๊กที่ตรึง: callback หาผู้ใช้จากอีเมลอย่างเดียว ลูกค้าที่สมัครด้วยเบอร์โทร (อีเมลคนละอันกับ Thaiprompt)
     *   ได้บัญชีจันทราใหม่อีกบัญชีแล้วถูกสลับไปล็อกอินบัญชีนั้น บัญชีเดิมไม่ถูกผูก → ผังแม่หมอไม่เคยรวม
     *   บัญชีเงาของเขาเข้าบัญชี Thaiprompt ค่าแนะนำค้างอยู่ในกระเป๋าที่ไม่มีใครเข้าได้
     */
    public function test_logged_in_customer_links_their_own_account_instead_of_getting_a_new_one(): void
    {
        $customer = User::factory()->create([
            'email' => '0812345678@phone.juntra.test',
            'name' => 'ลูกค้าเบอร์โทร',
            'role' => 'member',
            'email_verified_at' => null,
        ]);
        $this->actingAs($customer);

        $this->completeCallback(['id' => '9100', 'email' => 'real@thaiprompt.test', 'name' => 'somchai99'])
            ->assertRedirect();

        $this->assertSame(1, User::count(), 'ห้ามสร้างบัญชีจันทราใหม่');
        $this->assertAuthenticatedAs($customer);
        $customer->refresh();
        $this->assertSame('9100', (string) $customer->thaiprompt_user_id);
        $this->assertSame('ลูกค้าเบอร์โทร', $customer->name, 'ชื่อเป็นของลูกค้า — ไม่เอาชื่อ Thaiprompt มาทับ');
        $this->assertSame('0812345678@phone.juntra.test', $customer->email);
        $this->assertNull($customer->email_verified_at, 'อีเมลในบัญชีไม่ใช่อีเมลที่ Thaiprompt ยืนยัน');

        // ผูกครั้งแรก = บอกผังแม่หมอให้รวมบัญชีเงาของลูกค้าคนนี้เข้าบัญชี Thaiprompt
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/affiliate/accounts')
            && (int) $r['user_ref'] === $customer->id
            && (string) $r['thaiprompt_user_id'] === '9100');
    }

    /** เชื่อมจากแอพ (mobile-start ล็อกอินให้ก่อน) → หน้า "กลับสู่แอพ" ของลูกค้าคนเดิม */
    public function test_mobile_link_returns_to_the_app_for_the_same_customer(): void
    {
        $customer = User::factory()->create(['email' => '0822222222@phone.juntra.test', 'name' => 'ลูกค้าแอพ', 'role' => 'member']);
        $this->actingAs($customer);

        $this->completeCallback(['id' => '9150', 'email' => 'app@thaiprompt.test', 'name' => 'x'], ['thaiprompt_oauth_origin' => 'mobile'])
            ->assertOk()
            ->assertViewIs('pages.auth.oauth-mobile-success')
            ->assertSee('ลูกค้าแอพ');

        $this->assertSame('9150', (string) $customer->fresh()->thaiprompt_user_id);
        $this->assertSame(1, User::count());
    }

    /** บัญชี Thaiprompt นี้เป็นของบัญชีจันทราอีกคนแล้ว — ไม่ย้ายมา และไม่สลับไปล็อกอินคนนั้นเงียบ ๆ */
    public function test_linking_a_thaiprompt_account_that_belongs_to_another_customer_is_refused(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com', 'role' => 'member', 'thaiprompt_user_id' => '9200']);
        $customer = User::factory()->create(['email' => '0899999999@phone.juntra.test', 'role' => 'member']);
        $this->actingAs($customer);

        $this->completeCallback(['id' => '9200', 'email' => 'owner@example.com', 'name' => 'เจ้าของเดิม'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('thaiprompt');

        $this->assertAuthenticatedAs($customer);
        $this->assertNull($customer->fresh()->thaiprompt_user_id);
        $this->assertSame('9200', (string) $owner->fresh()->thaiprompt_user_id);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/affiliate/'));
    }

    /** ผูกไว้กับ Thaiprompt อีกบัญชีแล้ว — เปลี่ยนเองไม่ได้ (ผังแม่หมอผูกลูกค้ากับบัญชีแรกไว้ถาวร) */
    public function test_account_linked_to_another_thaiprompt_account_is_not_relinked(): void
    {
        $customer = User::factory()->create(['email' => 'c@example.com', 'role' => 'member', 'thaiprompt_user_id' => '9300']);
        $this->actingAs($customer);

        $this->completeCallback(['id' => '9301', 'email' => 'other@example.com', 'name' => 'อีกบัญชี'])
            ->assertSessionHasErrors('thaiprompt');

        $this->assertSame('9300', (string) $customer->fresh()->thaiprompt_user_id);
        $this->assertSame(1, User::count());
    }

    /**
     * กดยกเลิกที่หน้า Thaiprompt ระหว่างเชื่อมบัญชี → กลับแดชบอร์ดพร้อมเหตุผล บัญชีเดิมไม่เปลี่ยน
     *   (เดิมส่งไปหน้าเข้าสู่ระบบ ซึ่งไล่คนที่ล็อกอินอยู่ออกทันที ข้อความหายระหว่างทาง)
     */
    public function test_cancelling_at_thaiprompt_while_linking_keeps_the_customer_and_says_why(): void
    {
        $customer = User::factory()->create(['email' => '0833333333@phone.juntra.test', 'role' => 'member']);
        $this->actingAs($customer);
        $state = Str::random(40);

        $this->withSession(['thaiprompt_oauth_state' => $state, 'thaiprompt_oauth_origin' => 'mobile'])
            ->get('/auth/thaiprompt/callback?error=access_denied&state=' . $state)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('thaiprompt')
            ->assertSessionMissing('thaiprompt_oauth_origin');

        $this->assertAuthenticatedAs($customer);
        $this->assertNull($customer->fresh()->thaiprompt_user_id);
    }

    /** ยังไม่ล็อกอิน: บัญชีที่ผูก Thaiprompt คนนี้ไว้แล้วต้องมาก่อนบัญชีที่แค่อีเมลตรงกัน */
    public function test_sign_in_prefers_the_linked_account_over_an_email_match(): void
    {
        User::factory()->create(['email' => 'same@thaiprompt.test', 'role' => 'member']);
        $linked = User::factory()->create(['email' => '0811111111@phone.juntra.test', 'role' => 'member', 'thaiprompt_user_id' => '9400']);

        $this->completeCallback(['id' => '9400', 'email' => 'same@thaiprompt.test', 'name' => 'x'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($linked);
    }

    /** state ไม่ตรง = ต้องไม่สร้างบัญชีใด ๆ (กัน CSRF) */
    public function test_mismatched_state_creates_no_account(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->withSession(['thaiprompt_oauth_state' => Str::random(40)])
            ->get('/auth/thaiprompt/callback?code=auth-code&state=' . Str::random(40))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}
