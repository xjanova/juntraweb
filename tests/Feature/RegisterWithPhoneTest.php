<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * สมัครสมาชิกด้วยเบอร์โทร + Cloudflare Turnstile
 *
 * เจ้าของกำหนด (2026-07-25): ลูกค้าหลักเป็นผู้สูงอายุ หลายคนไม่มีอีเมลหรือ
 * จำไม่ได้ ให้กรอกเบอร์ไว้ก่อนแล้วสุ่มอีเมลให้ เพื่อลดแรงเสียดทานก่อนจ่ายเงิน
 * แต่ต้องมีตัวกันบอท เพราะไม่มีอีเมลให้ยืนยันตัวตนอีกแล้ว
 */
class RegisterWithPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $override = []): array
    {
        return array_merge([
            'name'                  => 'คุณยายสมทรง',
            'phone'                 => '081-234-5678',
            'password'              => 'Password!234',
            'password_confirmation' => 'Password!234',
        ], $override);
    }

    public function test_registers_with_phone_only_and_generates_an_email(): void
    {
        $this->post('/register', $this->form())->assertRedirect();

        $user = User::firstOrFail();
        $this->assertSame('0812345678', $user->phone, 'เบอร์ต้องถูก normalise เหลือตัวเลขล้วน');
        $this->assertStringEndsWith('@phone.juntra.local', $user->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registers_with_email_only(): void
    {
        $this->post('/register', $this->form(['phone' => null, 'email' => 'somsri@example.com']))
            ->assertRedirect();

        $user = User::firstOrFail();
        $this->assertSame('somsri@example.com', $user->email);
        $this->assertNull($user->phone);
    }

    public function test_requires_phone_or_email(): void
    {
        $this->post('/register', $this->form(['phone' => null]))
            ->assertSessionHasErrors(['email', 'phone']);

        $this->assertSame(0, User::count());
    }

    /** เบอร์คือตัวตน — ซ้ำไม่ได้ ไม่งั้นสองคนใช้บัญชีทับกัน */
    public function test_phone_must_be_unique_even_when_typed_differently(): void
    {
        $this->post('/register', $this->form())->assertRedirect();
        $this->post('/logout');

        // พิมพ์คนละรูปแบบแต่เป็นเบอร์เดียวกัน (+66 / มีขีด)
        $this->post('/register', $this->form(['name' => 'คนอื่น', 'phone' => '+66812345678']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(1, User::count());
    }

    /** ตั้งคีย์ Turnstile แล้ว = ต้องผ่านด่านก่อน ไม่งั้นบอทสมัครรัวได้ */
    public function test_turnstile_blocks_when_token_is_missing_or_rejected(): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret' => 'secret']);
        Http::fake(['*challenges.cloudflare.com*' => Http::response(['success' => false], 200)]);

        $this->post('/register', $this->form(['cf-turnstile-response' => 'bad-token']))
            ->assertSessionHasErrors();

        $this->assertSame(0, User::count());
    }

    public function test_turnstile_passes_with_a_valid_token(): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret' => 'secret']);
        Http::fake(['*challenges.cloudflare.com*' => Http::response(['success' => true], 200)]);

        $this->post('/register', $this->form(['cf-turnstile-response' => 'good-token']))
            ->assertRedirect();

        $this->assertSame(1, User::count());
    }

    /**
     * สมัครด้วยเบอร์แล้วต้องเข้าระบบด้วยเบอร์ได้จริง
     * ถ้าลืมข้อนี้ ลูกค้าจะสมัครได้แต่ล็อกอินไม่ได้ตลอดไป เพราะอีเมลที่ระบบ
     * สร้างให้เขาไม่มีทางรู้
     */
    public function test_can_log_in_with_the_phone_number(): void
    {
        $this->post('/register', $this->form())->assertRedirect();
        $this->post('/logout');
        $this->assertGuest();

        // พิมพ์คนละรูปแบบกับตอนสมัครก็ต้องเข้าได้
        $this->post('/login', ['email' => '0812345678', 'password' => 'Password!234'])
            ->assertRedirect();
        $this->assertAuthenticated();

        $this->post('/logout');
        $this->post('/login', ['email' => '+66812345678', 'password' => 'Password!234'])
            ->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_wrong_password_still_fails_for_phone_login(): void
    {
        $this->post('/register', $this->form())->assertRedirect();
        $this->post('/logout');

        $this->post('/login', ['email' => '0812345678', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** ยังไม่ตั้งคีย์ = ระบบสมัครต้องไม่ตาย (แต่ก็แปลว่ายังไม่มีการป้องกัน) */
    public function test_registration_works_when_turnstile_is_not_configured(): void
    {
        config(['services.turnstile.site_key' => '', 'services.turnstile.secret' => '']);

        $this->post('/register', $this->form())->assertRedirect();
        $this->assertSame(1, User::count());
    }
}
