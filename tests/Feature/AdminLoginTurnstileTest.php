<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\AdminLogin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ล็อกอินหลังบ้าน (/admin/login) ต้องผ่าน Cloudflare Turnstile ทุกครั้ง
 *
 * ของเดิมที่ Filament ให้มามีแค่ rateLimit(5)/นาที/IP + canAccessPanel()
 * ซึ่งไม่แยกคนจากสคริปต์เลย — บ็อตเน็ตที่ IP ไม่ซ้ำกันจึงไล่เดารหัสได้เรื่อย ๆ
 * เพราะเพดานผูกกับ IP ของผู้ยิง ไม่ใช่บัญชีที่ถูกยิง
 */
class AdminLoginTurnstileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Livewire::test() ไม่ได้วิ่งผ่าน middleware ของ panel จึงต้องบอก
        // Filament เองว่า panel ไหนกำลังใช้งาน ไม่งั้น Filament::auth() ไม่รู้ guard
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function configureTurnstile(bool $accepted = true): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret' => 'secret']);

        Http::fake(['*challenges.cloudflare.com*' => Http::response(['success' => $accepted], 200)]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'password' => 'Password!234']);
    }

    /**
     * ยังไม่ได้ตั้งคีย์ = ด่านหลับ ล็อกอินได้ตามปกติ
     *
     * ข้อนี้สำคัญกว่าที่คิด: เครื่อง dev และ CI ไม่มีคีย์ ถ้าด่านไม่หลับ
     * จะไม่มีใครเข้าหลังบ้านได้เลยทั้งเครื่อง แล้วจะไม่มีใครรู้จนขึ้น prod
     */
    public function test_admin_logs_in_normally_when_turnstile_is_not_configured(): void
    {
        config(['services.turnstile.site_key' => '', 'services.turnstile.secret' => '']);

        $admin = $this->admin();

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'Password!234'])
            ->call('authenticate');

        $this->assertAuthenticatedAs($admin);
    }

    /** ตั้งคีย์แล้วแต่ไม่ส่ง token มา = บ็อตทั่วไป ต้องไม่ได้แม้แต่ลองรหัส */
    public function test_login_is_rejected_when_no_token_is_submitted(): void
    {
        $this->configureTurnstile();

        $admin = $this->admin();

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'Password!234'])
            ->call('authenticate');

        $this->assertGuest();

        // token ว่างต้องถูกปฏิเสธในเครื่อง ไม่ยิงออกเน็ตให้ Cloudflare เลย
        // (ไม่งั้นบ็อตยิงรัว ๆ จะกลายเป็น outbound flood ของเราเอง)
        Http::assertNothingSent();
    }

    /** Cloudflare ตอบ success:false (token ปลอม/หมดอายุ) ก็ต้องไม่ผ่าน */
    public function test_login_is_rejected_when_cloudflare_rejects_the_token(): void
    {
        $this->configureTurnstile(accepted: false);

        $admin = $this->admin();

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'Password!234'])
            ->set('turnstileToken', 'forged-token')
            ->call('authenticate');

        $this->assertGuest();
    }

    public function test_login_succeeds_with_a_valid_token(): void
    {
        $this->configureTurnstile();

        $admin = $this->admin();

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'Password!234'])
            ->set('turnstileToken', 'good-token')
            ->call('authenticate');

        $this->assertAuthenticatedAs($admin);
    }

    /**
     * token ของ Cloudflare ใช้ได้ครั้งเดียว — พอรหัสผิด หน้าไม่ได้โหลดใหม่
     * ถ้าไม่ล้าง token ที่ใช้แล้ว รอบถัดไปจะขึ้นว่า "ไม่ใช่บอทไม่ผ่าน"
     * ทั้งที่ความจริงคือรหัสผิด แล้วแอดมินจะติดวนจนกด refresh เอง
     */
    public function test_a_spent_token_is_cleared_after_a_wrong_password(): void
    {
        $this->configureTurnstile();

        $admin = $this->admin();

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'wrong-password'])
            ->set('turnstileToken', 'good-token')
            ->call('authenticate')
            ->assertHasErrors('data.email')
            ->assertSet('turnstileToken', null)
            ->assertDispatched('turnstile-reset');

        $this->assertGuest();
    }

    /** ลูกค้าธรรมดาที่รหัสถูกต้องก็ต้องไม่หลุดเข้าหลังบ้าน แม้ผ่าน captcha */
    public function test_a_customer_cannot_enter_the_panel_even_with_a_valid_token(): void
    {
        $this->configureTurnstile();

        // role ของลูกค้าคือ 'member' (enum: admin/editor/member — ดู
        // 2026_05_07_000003_add_role_to_users) ซึ่ง canAccessPanel() ไม่ปล่อย
        $customer = User::factory()->create(['role' => 'member', 'password' => 'Password!234']);

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $customer->email, 'password' => 'Password!234'])
            ->set('turnstileToken', 'good-token')
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
    }

    /**
     * การเช็ค Turnstile ยิง HTTP ออกนอก (timeout 8 วินาที) จากหน้าที่ไม่ต้อง
     * ล็อกอิน — ต้องมีเพดานกันคนยิงรัว ๆ จับ worker ค้าง ช่องนี้เกิดจากการ
     * เพิ่ม captcha เอง ก่อนหน้านี้หน้านี้ไม่ยิงเน็ตออกเลย
     */
    public function test_a_flood_of_empty_tokens_is_capped_before_reaching_cloudflare(): void
    {
        $this->configureTurnstile();

        $admin = $this->admin();

        $page = Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'Password!234']);

        // token ว่างถูกปฏิเสธในเครื่อง จึงไม่ไปแตะ rateLimit(5) ของ parent เลย
        // (ถ้าลำดับสลับ เทสต์นี้จะพังที่ครั้งที่ 6)
        for ($i = 0; $i < 30; $i++) {
            $page->call('authenticate');
        }

        // ถังเต็มแล้ว — ต่อจากนี้แม้ token ถูกและรหัสถูก ก็ต้องไม่ได้เข้า
        $page->set('turnstileToken', 'good-token')->call('authenticate');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    /** widget ต้องโผล่บนหน้าจอจริง ไม่ใช่ผ่านแค่ในเทสต์ฝั่ง server */
    public function test_the_widget_is_rendered_only_once_keys_are_configured(): void
    {
        config(['services.turnstile.site_key' => '', 'services.turnstile.secret' => '']);
        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('challenges.cloudflare.com');

        config(['services.turnstile.site_key' => 'site-key-abc', 'services.turnstile.secret' => 'secret']);
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', escape: false)
            ->assertSee('site-key-abc', escape: false);
    }
}
