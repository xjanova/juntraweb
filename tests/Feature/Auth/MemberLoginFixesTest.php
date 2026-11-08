<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * ระบบเข้าสู่ระบบของสมาชิก (2026-11-09)
 *
 * เจ้าของแจ้ง "ระบบ login สมาชิกยังผิดพลาดอยู่เยอะ" — ที่เจอจริงบน prod:
 *  - หน้า /login ไม่แสดง error เลย (page-handles-flash ปิดแถบของเลย์เอาต์ แต่หน้าเองโชว์แค่ status)
 *    ใส่รหัสผิด / SSO ถูกปฏิเสธ = เด้งกลับหน้าเดิมเงียบ ๆ
 *  - ช่องกรอกเป็น type="email" เบราว์เซอร์ไม่ยอมส่งเบอร์ → คนสมัครด้วยเบอร์เข้าไม่ได้
 *  - ข้อความเป็นภาษาอังกฤษ (ไม่มี lang/th/auth.php, passwords.php)
 *  - ลืมรหัสผ่าน: รับแค่อีเมล + SMTP ยังเป็นค่าตัวอย่าง → ส่งไม่ได้และไม่บอกอะไร
 */
class MemberLoginFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_field_accepts_phone_numbers(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('อีเมล หรือ เบอร์โทรศัพท์', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="email"[^>]*type="email"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="email"[^>]*type="text"/', $html);
    }

    public function test_wrong_password_shows_a_thai_error_on_the_login_page(): void
    {
        $user = User::factory()->create();

        $this->from('/login')->followingRedirects()
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertOk()
            ->assertSee('อีเมล/เบอร์โทร หรือรหัสผ่านไม่ถูกต้อง')
            ->assertDontSee('These credentials do not match');

        $this->assertGuest();
    }

    public function test_sso_refusal_reason_is_visible_on_the_login_page(): void
    {
        // ข้อความที่ ThaipromptController::fail() ส่งกลับมา (เช่นบัญชีทีมงาน) ต้องอ่านได้
        $this->withSession(['errors' => $this->errorBag(['email' => 'บัญชีทีมงานต้องเข้าสู่ระบบด้วยอีเมลและรหัสผ่านเท่านั้น'])])
            ->get('/login')
            ->assertOk()
            ->assertSee('บัญชีทีมงานต้องเข้าสู่ระบบด้วยอีเมลและรหัสผ่านเท่านั้น');
    }

    public function test_member_can_log_in_with_a_formatted_phone_number(): void
    {
        User::factory()->create([
            'phone' => '0812345678',
            'email' => PhoneNumber::placeholderEmail('0812345678'),
        ]);

        $this->post('/login', ['email' => '081-234-5678', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_forgot_password_by_phone_sends_to_the_real_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['phone' => '0899998888']);

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => '089 999 8888'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_for_phone_only_account_points_to_line(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'phone' => '0877776666',
            'email' => PhoneNumber::placeholderEmail('0877776666'),
        ]);

        $this->from('/forgot-password')->followingRedirects()
            ->post('/forgot-password', ['email' => '0877776666'])
            ->assertSee('บัญชีนี้สมัครด้วยเบอร์โทร')
            ->assertSee('LINE');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_for_bot_account_points_to_thaiprompt_button(): void
    {
        Notification::fake();
        User::factory()->create([
            'email' => 'fb_123@thaiprompt.local',
            'thaiprompt_user_id' => '555',
        ]);

        $this->from('/forgot-password')->followingRedirects()
            ->post('/forgot-password', ['email' => 'fb_123@thaiprompt.local'])
            ->assertSee('เข้าสู่ระบบด้วย Thaiprompt');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_says_so_when_mail_is_not_configured(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);
        $user = User::factory()->create();

        $this->from('/forgot-password')->followingRedirects()
            ->post('/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertSee('ระบบส่งอีเมลยังไม่พร้อม');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_unknown_account_is_thai(): void
    {
        $this->from('/forgot-password')->followingRedirects()
            ->post('/forgot-password', ['email' => 'nobody@example.net'])
            ->assertSee('ไม่พบบัญชีที่ใช้อีเมล/เบอร์นี้')
            ->assertDontSee("We can't find a user");
    }

    public function test_account_security_page_shows_form_errors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['errors' => $this->errorBag(['password' => 'รหัสผ่านไม่ถูกต้อง'])])
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('รหัสผ่านไม่ถูกต้อง');
    }

    /** errors แบบที่ redirect()->withErrors() ฝากไว้ใน session (withViewErrors ถูก middleware ทับ) */
    private function errorBag(array $messages): ViewErrorBag
    {
        return (new ViewErrorBag())->put('default', new MessageBag($messages));
    }
}
