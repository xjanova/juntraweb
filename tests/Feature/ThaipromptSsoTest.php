<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Rules\NotReservedEmailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ความปลอดภัย SSO Thaiprompt สำหรับเส้น FB → เว็บ (J1)
 *
 * ตรึงไว้ 3 เรื่อง:
 *  1. อีเมลสังเคราะห์ @thaiprompt.local ห้ามใช้จับคู่บัญชี (กัน pre-account hijack ด้วย PSID ที่อ่านได้จากลิงก์)
 *  2. สมัคร/เปลี่ยนอีเมลเป็นโดเมนสงวนไม่ได้ (เว็บ, API แอพ, หน้าโปรไฟล์)
 *  3. เส้น handoff (to=/chat/continue/...) ที่มีคนอื่นล็อกอินค้าง = สลับบัญชี ห้ามผูก ห้ามเด้งแดชบอร์ด ห้าม remember
 */
class ThaipromptSsoTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tp.test';

    private const HANDOFF = '/chat/continue/6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_enabled', '1');
        Setting::put('thaiprompt_base_url', self::BASE);
        Setting::put('thaiprompt_client_id', 'test-client');
        Setting::put('thaiprompt_client_secret', 'test-secret');
    }

    private function fakeUpstream(array $profile): void
    {
        // fake ซ้อนกัน = stub แรกชนะ → เริ่ม factory ใหม่ทุกครั้งให้โปรไฟล์ของรอบนี้มีผลจริง
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake([
            'tp.test/oauth/token' => Http::response([
                'access_token'  => 'access-token-123',
                'refresh_token' => 'refresh-token-123',
                'expires_in'    => 3600,
            ]),
            'tp.test/api/user' => Http::response($profile),
            '*'                => Http::response([], 200),
        ]);
    }

    /** ยิง callback ตรง ๆ ด้วย state ที่ตรงกับใน session */
    private function completeCallback(array $profile, array $session = [])
    {
        $this->fakeUpstream($profile);
        $state = Str::random(40);

        return $this->withSession(['thaiprompt_oauth_state' => $state] + $session)
            ->get('/auth/thaiprompt/callback?code=auth-code&state=' . $state);
    }

    /** เดินครบรอบจริง: /redirect?to=... แล้วตาม callback ด้วย state ที่ redirect เก็บไว้ */
    private function roundTrip(array $profile, string $to)
    {
        $this->fakeUpstream($profile);
        $this->get('/auth/thaiprompt/redirect?to=' . urlencode($to))->assertRedirect();
        $state = session('thaiprompt_oauth_state');
        $this->assertNotEmpty($state);

        return $this->get('/auth/thaiprompt/callback?code=auth-code&state=' . $state);
    }

    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    // ── 1. อีเมล .local ห้ามจับคู่ ───────────────────────────────────────

    public function test_thaiprompt_local_email_never_matches_an_existing_account(): void
    {
        // คนร้ายจองอีเมลสังเคราะห์ของลูกค้าไว้ก่อน (ก่อนมีด่านกันโดเมน)
        $squatter = User::factory()->create(['email' => 'fb_123456@thaiprompt.local']);

        $res = $this->completeCallback([
            'id'    => '9100',
            'email' => 'fb_123456@thaiprompt.local',
            'name'  => 'ลูกค้า FB',
        ]);

        $res->assertRedirect();
        $res->assertSessionHasNoErrors();

        $squatter->refresh();
        $this->assertNull($squatter->thaiprompt_user_id, 'บัญชีที่จองอีเมลไว้ต้องไม่ถูกผูก Thaiprompt');
        $this->assertNull($squatter->thaiprompt_token);

        $owner = User::where('thaiprompt_user_id', '9100')->first();
        $this->assertNotNull($owner, 'เจ้าของตัวจริงต้องได้บัญชีของตัวเอง');
        $this->assertNotSame($squatter->id, $owner->id);
        $this->assertStringEndsWith('@thaiprompt.local', $owner->email);
        $this->assertAuthenticatedAs($owner);
    }

    public function test_thaiprompt_local_email_still_logs_in_by_thaiprompt_user_id(): void
    {
        $linked = User::factory()->create([
            'email'              => 'fb_777@thaiprompt.local',
            'thaiprompt_user_id' => '9101',
        ]);

        $this->completeCallback(['id' => '9101', 'email' => 'fb_777@thaiprompt.local', 'name' => 'ลูกค้า']);

        $this->assertAuthenticatedAs($linked);
        $this->assertSame(1, User::where('thaiprompt_user_id', '9101')->count());
    }

    public function test_thaiprompt_local_email_without_thaiprompt_id_fails_closed(): void
    {
        $res = $this->completeCallback(['email' => 'fb_888@thaiprompt.local', 'name' => 'ไม่มี id']);

        $res->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'fb_888@thaiprompt.local')->count());
    }

    public function test_real_email_fallback_still_links_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'real@example.com']);

        $this->completeCallback(['id' => '9102', 'email' => 'real@example.com', 'name' => 'ลูกค้าจริง']);

        $this->assertSame('9102', $existing->refresh()->thaiprompt_user_id);
        $this->assertAuthenticatedAs($existing);
    }

    // ── 2. ปฏิเสธโดเมนสงวน ──────────────────────────────────────────────

    public function test_reserved_domain_detection(): void
    {
        $this->assertTrue(NotReservedEmailDomain::isReserved('fb_1@thaiprompt.local'));
        $this->assertTrue(NotReservedEmailDomain::isReserved('FB_1@Thaiprompt.Local'));
        $this->assertTrue(NotReservedEmailDomain::isReserved('x@sub.thaiprompt.local'));
        $this->assertTrue(NotReservedEmailDomain::isReserved('x@thaiprompt.local.'));
        $this->assertTrue(NotReservedEmailDomain::isReserved('p0812345678@phone.juntra.local'));
        $this->assertFalse(NotReservedEmailDomain::isReserved('x@thaiprompt.online'));
        $this->assertFalse(NotReservedEmailDomain::isReserved('x@notthaiprompt.local'));
        $this->assertFalse(NotReservedEmailDomain::isReserved('nobody'));
    }

    public function test_web_register_rejects_thaiprompt_local_email(): void
    {
        $res = $this->post('/register', [
            'name'                  => 'คนร้าย',
            'email'                 => 'fb_123456@thaiprompt.local',
            'password'              => 'password-123456',
            'password_confirmation' => 'password-123456',
        ]);

        $res->assertSessionHasErrors('email');
        $this->assertSame(0, User::where('email', 'fb_123456@thaiprompt.local')->count());
        $this->assertGuest();
    }

    public function test_api_register_rejects_thaiprompt_local_email(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'คนร้าย',
            'email'                 => 'fb_123456@thaiprompt.local',
            'password'              => 'password-123456',
            'password_confirmation' => 'password-123456',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0, User::where('email', 'fb_123456@thaiprompt.local')->count());
    }

    public function test_profile_cannot_change_email_to_thaiprompt_local(): void
    {
        $user = User::factory()->create(['email' => 'mine@example.com']);

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'ชื่อใหม่', 'email' => 'fb_123456@thaiprompt.local'])
            ->assertSessionHasErrors('email');

        $this->assertSame('mine@example.com', $user->refresh()->email);
    }

    public function test_profile_with_unchanged_system_email_still_saves(): void
    {
        // บัญชีที่ SSO สร้างอีเมลสังเคราะห์ให้ ต้องแก้ชื่อได้โดยไม่ถูกบังคับเปลี่ยนอีเมล
        $user = User::factory()->create(['email' => 'fb_42@thaiprompt.local', 'thaiprompt_user_id' => '42']);

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'ชื่อใหม่', 'email' => 'fb_42@thaiprompt.local'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ชื่อใหม่', $user->refresh()->name);
    }

    // ── 3. เส้น handoff ─────────────────────────────────────────────────

    public function test_redirect_marks_handoff_only_for_chat_continue(): void
    {
        $this->get('/auth/thaiprompt/redirect?to=' . urlencode(self::HANDOFF));
        $this->assertTrue((bool) session('thaiprompt_oauth_handoff'));

        $this->get('/auth/thaiprompt/redirect?to=' . urlencode('/mlm/commissions'));
        $this->assertNull(session('thaiprompt_oauth_handoff'));
    }

    public function test_handoff_with_other_linked_user_switches_account_instead_of_binding(): void
    {
        $other = User::factory()->create(['email' => 'other@example.com', 'thaiprompt_user_id' => '1']);
        $owner = User::factory()->create(['email' => 'owner@example.com', 'thaiprompt_user_id' => '2']);

        $this->actingAs($other);
        $res = $this->roundTrip(['id' => '2', 'email' => 'owner@example.com', 'name' => 'เจ้าของ'], self::HANDOFF);

        $res->assertRedirect(url(self::HANDOFF));
        $res->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('1', $other->refresh()->thaiprompt_user_id, 'บัญชีที่ค้างอยู่ต้องไม่ถูกผูกใหม่');
        $res->assertCookieMissing($this->recallerName());
    }

    public function test_handoff_with_unlinked_logged_in_user_switches_and_never_binds(): void
    {
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $this->actingAs($stranger);
        $res = $this->roundTrip(['id' => '3', 'email' => 'fb_3@thaiprompt.local', 'name' => 'ลูกค้า FB'], self::HANDOFF);

        // 🔮 (2026-11-09) บัญชีที่เพิ่งสมัครลงหน้าเลือกผู้พยากรณ์ตรง ๆ (ปลายทางเดียวกับ /chat/continue/{h})
        $res->assertRedirect(route('chat.index', ['pick' => 1]));
        $res->assertSessionHasNoErrors();
        $this->assertNull($stranger->refresh()->thaiprompt_user_id, 'ห้ามผูก Thaiprompt เข้าบัญชีที่ค้างอยู่');

        $owner = User::where('thaiprompt_user_id', '3')->first();
        $this->assertNotNull($owner);
        $this->assertNotSame($stranger->id, $owner->id);
        $this->assertAuthenticatedAs($owner);
    }

    public function test_handoff_with_same_user_keeps_session(): void
    {
        $owner = User::factory()->create(['email' => 'same@example.com', 'thaiprompt_user_id' => '4']);

        $this->actingAs($owner);
        $res = $this->roundTrip(['id' => '4', 'email' => 'same@example.com', 'name' => 'เจ้าของ'], self::HANDOFF);

        $res->assertRedirect(url(self::HANDOFF));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_handoff_guest_login_lands_on_oracle_picker_without_remember(): void
    {
        $res = $this->roundTrip(['id' => '5', 'email' => 'fb_5@thaiprompt.local', 'name' => 'ลูกค้าใหม่'], self::HANDOFF);

        // 🔮 (2026-11-09) ลูกค้าใหม่จากการ์ดในเพจ → หน้าเลือกผู้พยากรณ์ทันที
        $res->assertRedirect(route('chat.index', ['pick' => 1]));
        $this->assertAuthenticatedAs(User::where('thaiprompt_user_id', '5')->firstOrFail());
        $res->assertCookieMissing($this->recallerName());
    }

    public function test_non_handoff_guest_login_still_remembers(): void
    {
        $res = $this->roundTrip(['id' => '6', 'email' => 'six@example.com', 'name' => 'ปกติ'], '/mlm/commissions');

        $res->assertRedirect(url('/mlm/commissions'));
        $res->assertCookie($this->recallerName());
    }

    public function test_staff_accounts_are_never_logged_in_by_sso_or_handoff(): void
    {
        foreach (['admin', 'editor'] as $i => $role) {
            $staff = User::factory()->create([
                'email' => "staff{$i}@example.com", 'thaiprompt_user_id' => (string) (50 + $i), 'role' => $role,
            ]);

            foreach ([self::HANDOFF, '/mlm/commissions'] as $to) {
                $res = $this->roundTrip(['id' => (string) (50 + $i), 'email' => "staff{$i}@example.com", 'name' => 'ทีมงาน'], $to);

                $res->assertRedirect(route('login'));
                $res->assertSessionHasErrors('email');
                $this->assertGuest();
                $this->assertNotSame('access-token-123', $staff->refresh()->thaiprompt_token, 'ห้ามเขียน token ลงบัญชีทีมงาน');
            }
        }

        // สมาชิกทั่วไปยังเข้าได้ตามเดิม
        User::factory()->create(['email' => 'member@example.com', 'thaiprompt_user_id' => '60', 'role' => 'member']);
        $this->roundTrip(['id' => '60', 'email' => 'member@example.com', 'name' => 'สมาชิก'], self::HANDOFF)
            ->assertRedirect(url(self::HANDOFF));
        $this->assertAuthenticated();
    }

    public function test_non_handoff_with_other_linked_user_still_refuses(): void
    {
        // เส้นปกติ (กดเชื่อมจากหน้าเว็บ) ยังเป็นแบบเดิม: ไม่ย้าย ไม่สลับ แจ้งเหตุที่แดชบอร์ด
        $other = User::factory()->create(['email' => 'other2@example.com', 'thaiprompt_user_id' => '10']);

        $this->actingAs($other);
        $res = $this->roundTrip(['id' => '11', 'email' => 'eleven@example.com', 'name' => 'อีกคน'], '/mlm/commissions');

        $res->assertRedirect(route('dashboard'));
        $res->assertSessionHasErrors('thaiprompt');
        $this->assertAuthenticatedAs($other);
        $this->assertSame('10', $other->refresh()->thaiprompt_user_id);
        $this->assertSame(0, User::where('thaiprompt_user_id', '11')->count());
    }
}
