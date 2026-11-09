<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔮 (2026-11-09 เจ้าของสั่ง) ลูกค้าที่มาจากลิงก์ในแชทเพจ Facebook ต้องถูกสมัครให้อัตโนมัติ
 * (ไม่ต้องไปสมัครเองที่เว็บ Thaiprompt) และ "หลังจากสมัครเสร็จ ให้ส่งไปหน้าเลือกผู้พยากรณ์ทันที"
 *
 * เส้นจริง: บอทเพจ → Thaiprompt /web-fortune/go (ล็อกอินบัญชีบอทให้เอง) →
 *   จันทรา /auth/thaiprompt/redirect?to=/chat/continue/{h} → Thaiprompt /oauth/authorize (อนุมัติเอง)
 *   → จันทรา /auth/thaiprompt/callback → สร้างบัญชี + ล็อกอิน → หน้าเลือกผู้พยากรณ์ (/chat?pick=1)
 *
 * เทสต์ชุดนี้ไล่ฝั่งจันทราทั้งเส้น (redirect → callback → หน้าเลือก) และตรึง edge case:
 *   ลูกค้าเก่า · callback ถูกยิงซ้ำ (replay) · code หมดอายุ · โปรไฟล์ไม่มีอีเมล · ลูกค้าบอทที่ไม่มีอีเมล/เบอร์จริง
 * ส่วนลิงก์ FB หมดอายุ/ใช้ซ้ำ ตรวจที่ Thaiprompt (WebFortuneGatewayTest ใน Thaiprompt-Affiliate)
 */
class FbSignupLandsOnPickerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tp.test';

    private const HANDOFF = '41af3cea-65e0-4135-b95f-00ec7aa4556a';

    /** โปรไฟล์ที่ Thaiprompt ส่งมาให้ลูกค้าบอท FB — อีเมลสังเคราะห์ ไม่มีเบอร์โทร */
    private const FB_PROFILE = [
        'id'               => '1294',
        'email'            => 'fb_36344432358477389@thaiprompt.local',
        'name'             => 'เสือ จำศีล',
        'facebook_user_id' => '36344432358477389',
        'signup_via'       => 'facebook',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Setting::put('thaiprompt_enabled', '1');
        Setting::put('thaiprompt_base_url', self::BASE);
        Setting::put('thaiprompt_client_id', 'test-client');
        Setting::put('thaiprompt_client_secret', 'test-secret');
    }

    private function fakeThaiprompt(?array $profile = self::FB_PROFILE, int $tokenStatus = 200): void
    {
        Http::fake([
            'tp.test/oauth/token' => $tokenStatus === 200
                ? Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600])
                : Http::response(['error' => 'invalid_grant'], $tokenStatus),
            'tp.test/api/user' => Http::response($profile ?? []),
            '*'                => Http::response([], 200),
        ]);
    }

    /**
     * เดินเส้นจริงฝั่งจันทรา: /auth/thaiprompt/redirect?to=… → (Thaiprompt อนุมัติ) → callback
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: string} [response ของ callback, URL callback ที่ใช้]
     */
    private function signInFromLink(?string $to): array
    {
        $start = $this->get('/auth/thaiprompt/redirect' . ($to !== null ? '?to=' . urlencode($to) : ''));
        $start->assertRedirect();
        $this->assertStringStartsWith(self::BASE . '/oauth/authorize', (string) $start->headers->get('Location'));

        $state = (string) session('thaiprompt_oauth_state');
        $this->assertNotSame('', $state, 'redirect ต้องฝาก state ไว้ใน session');

        $callbackUrl = '/auth/thaiprompt/callback?code=auth-code&state=' . $state;

        return [$this->get($callbackUrl), $callbackUrl];
    }

    private function picker(): string
    {
        return route('chat.index', ['pick' => 1]);
    }

    // ------------------------------------------------------------------
    // สมัครใหม่ → หน้าเลือกผู้พยากรณ์
    // ------------------------------------------------------------------

    public function test_new_fb_customer_from_page_card_is_signed_up_and_lands_on_oracle_picker(): void
    {
        $this->fakeThaiprompt();

        [$res] = $this->signInFromLink('/chat/continue/' . self::HANDOFF);

        $res->assertRedirect($this->picker());
        $res->assertSessionHasNoErrors();

        $user = User::where('thaiprompt_user_id', '1294')->first();
        $this->assertNotNull($user, 'ต้องสมัครบัญชีให้อัตโนมัติ — ลูกค้าไม่ต้องกรอกอะไร');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('facebook', $user->signup_via);
        $this->assertSame('36344432358477389', $user->facebook_user_id);
        $this->assertSame('member', $user->role);

        // 🔐 ลิงก์จากแชทเปิดใน in-app browser/เครื่องที่ใช้ร่วมกันได้ → ห้ามฝาก remember cookie
        $this->assertEmpty($user->fresh()->remember_token, 'เส้น handoff ห้าม remember');

        $this->get($this->picker())->assertOk()->assertViewIs('pages.chat.rooms');
    }

    public function test_new_customer_from_plain_chat_link_lands_on_oracle_picker(): void
    {
        $this->fakeThaiprompt();

        [$res] = $this->signInFromLink('/chat');

        $res->assertRedirect($this->picker());
        $this->assertAuthenticated();
    }

    public function test_new_customer_without_any_destination_lands_on_oracle_picker_not_dashboard(): void
    {
        $this->fakeThaiprompt();

        [$res] = $this->signInFromLink(null);

        $res->assertRedirect($this->picker());
    }

    public function test_new_customer_with_a_specific_deep_link_still_goes_there(): void
    {
        $this->fakeThaiprompt();

        [$free] = $this->signInFromLink('/tarot/free');
        $free->assertRedirect(url('/tarot/free'));

        Auth::logout();
        User::query()->forceDelete();

        [$room] = $this->signInFromLink('/chat?room=busaba');
        $room->assertRedirect(url('/chat?room=busaba'));
    }

    public function test_customer_whose_old_account_was_soft_deleted_signs_up_again_instead_of_crashing(): void
    {
        // แอดมินลบบัญชีเดิม (soft delete) โดยไม่ล้างอีเมล — อีเมลสังเคราะห์ยังติด unique อยู่
        User::factory()->create(['email' => self::FB_PROFILE['email'], 'role' => 'member'])->delete();
        $this->fakeThaiprompt();

        [$res] = $this->signInFromLink('/chat/continue/' . self::HANDOFF);

        $res->assertRedirect($this->picker());
        $user = User::where('thaiprompt_user_id', '1294')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame(self::FB_PROFILE['email'], $user->email, 'ได้อีเมลภายในใหม่ ไม่ชนแถวที่ถูกลบ');
    }

    // ------------------------------------------------------------------
    // ลูกค้าเก่า
    // ------------------------------------------------------------------

    public function test_returning_customer_from_page_card_reaches_picker_without_a_duplicate_account(): void
    {
        $existing = User::factory()->create([
            'email'              => self::FB_PROFILE['email'],
            'thaiprompt_user_id' => '1294',
            'role'               => 'member',
        ]);
        $this->fakeThaiprompt();

        [$res] = $this->signInFromLink('/chat/continue/' . self::HANDOFF);

        // บัญชีเก่าไปปลายทางเดิมของลิงก์ (/chat/continue/{h}) ซึ่งพาต่อไปหน้าเลือกผู้พยากรณ์
        $res->assertRedirect(url('/chat/continue/' . self::HANDOFF));
        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('thaiprompt_user_id', '1294')->count(), 'ห้ามสร้างบัญชีซ้ำ');

        $this->get('/chat/continue/' . self::HANDOFF)->assertRedirect($this->picker());
    }

    public function test_returning_customer_without_destination_keeps_the_old_dashboard_landing(): void
    {
        User::factory()->create(['thaiprompt_user_id' => '1294', 'role' => 'member']);
        $this->fakeThaiprompt();

        [$res] = $this->signInFromLink(null);

        $res->assertRedirect(route('dashboard'));
    }

    // ------------------------------------------------------------------
    // replay / หมดอายุ / ข้อมูลไม่ครบ
    // ------------------------------------------------------------------

    public function test_replayed_callback_url_does_not_log_anyone_in_again(): void
    {
        $this->fakeThaiprompt();

        [$first, $callbackUrl] = $this->signInFromLink('/chat/continue/' . self::HANDOFF);
        $first->assertRedirect($this->picker());

        // คนที่ได้ URL callback ไป (ประวัติเบราว์เซอร์/log) ยิงซ้ำจากเบราว์เซอร์อื่น — state ใช้ได้ครั้งเดียว
        Auth::logout();
        $this->flushSession();

        $replay = $this->get($callbackUrl);

        $replay->assertRedirect(route('login'));
        $replay->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(1, User::count(), 'replay ต้องไม่สร้างบัญชีเพิ่ม');
    }

    public function test_expired_or_used_authorization_code_creates_no_account(): void
    {
        $this->fakeThaiprompt(self::FB_PROFILE, 400);

        [$res] = $this->signInFromLink('/chat/continue/' . self::HANDOFF);

        $res->assertRedirect(route('login'));
        $res->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_profile_without_email_creates_no_account(): void
    {
        $this->fakeThaiprompt(['id' => '1294', 'name' => 'ไม่มีอีเมล']);

        [$res] = $this->signInFromLink('/chat/continue/' . self::HANDOFF);

        $res->assertRedirect(route('login'));
        $res->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_bot_customer_with_no_real_email_or_phone_is_matched_by_thaiprompt_id_only(): void
    {
        // คนที่จองอีเมลสังเคราะห์ของลูกค้าไว้ก่อน ต้องไม่ได้บัญชีของลูกค้าตัวจริงไป
        $squatter = User::factory()->create(['email' => self::FB_PROFILE['email'], 'role' => 'member']);
        $this->fakeThaiprompt();

        [$res] = $this->signInFromLink('/chat/continue/' . self::HANDOFF);

        $res->assertRedirect($this->picker());
        $owner = User::where('thaiprompt_user_id', '1294')->firstOrFail();
        $this->assertNotSame($squatter->id, $owner->id, 'ห้ามจับคู่ด้วยอีเมล @thaiprompt.local');
        $this->assertAuthenticatedAs($owner);
        $this->assertNull($squatter->fresh()->thaiprompt_user_id);
        $this->assertNull($owner->phone ?? null, 'ไม่มีเบอร์โทรก็สมัครได้');
    }

    public function test_landing_rule_only_overrides_generic_destinations(): void
    {
        $c = \App\Http\Controllers\Auth\ThaipromptController::class;

        $this->assertTrue($c::landsOnPicker(null));
        $this->assertTrue($c::landsOnPicker(''));
        $this->assertTrue($c::landsOnPicker(url('/chat')));
        $this->assertTrue($c::landsOnPicker(url('/chat/continue/' . self::HANDOFF)));
        $this->assertTrue($c::landsOnPicker(url('/dashboard')));

        $this->assertFalse($c::landsOnPicker(url('/chat?room=busaba')));
        $this->assertFalse($c::landsOnPicker(url('/tarot/free')));
        $this->assertFalse($c::landsOnPicker(url('/mlm')));
        $this->assertFalse($c::landsOnPicker(url('/chat/conversation/5')));
    }
}
