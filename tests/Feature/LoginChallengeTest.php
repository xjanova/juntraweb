<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LoginChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ด่าน Turnstile แบบโผล่เมื่อกรอกผิดซ้ำ ที่ /login ของลูกค้า
 *
 * ทำไมหน้าลูกค้าต้องมีด้วย: /login กับ /admin/login ใช้ guard `web` ตัวเดียวกัน
 * ล็อกอินสำเร็จที่หน้าลูกค้าแล้วเดินเข้า /admin ได้เลยถ้า role ผ่าน
 * canAccessPanel() — ถ้าใส่ Turnstile แค่หลังบ้าน คนที่ตั้งใจเดารหัสแอดมิน
 * ก็ย้ายมายิงหน้านี้แทน ด่านหลังบ้านเหลือแค่ของประดับ
 *
 * แต่บังคับทุกครั้งไม่ได้ เพราะเจ้าของสั่งให้ลดแรงเสียดทานก่อนจ่ายเงิน
 * จึงท้าทายเฉพาะคนที่กรอกผิดซ้ำ ๆ — เทสต์ชุดนี้ตรึงเส้นแบ่งนั้นไว้
 */
class LoginChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function configureTurnstile(bool $accepted = true): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret' => 'secret']);

        Http::fake(['*challenges.cloudflare.com*' => Http::response(['success' => $accepted], 200)]);
    }

    private function customer(): User
    {
        return User::factory()->create(['email' => 'somsri@example.com', 'password' => 'Password!234']);
    }

    /** ยิงรหัสผิดซ้ำ ๆ ให้ครบจำนวนที่ต้องการ (ไม่เช็คผลลัพธ์ระหว่างทาง) */
    private function failLogin(string $email, int $times, string $ip = '203.0.113.9'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->post('/login', ['email' => $email, 'password' => 'nope'], ['REMOTE_ADDR' => $ip]);
        }
    }

    /** คนที่กรอกถูกตั้งแต่ครั้งแรกต้องไม่เจออะไรเลย */
    public function test_a_first_time_visitor_sees_no_challenge(): void
    {
        $this->configureTurnstile();

        $this->get('/login')->assertOk()->assertDontSee('cf-turnstile', escape: false);

        $customer = $this->customer();

        $this->post('/login', ['email' => $customer->email, 'password' => 'Password!234'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($customer);
        Http::assertNothingSent();
    }

    /** กรอกผิดครบเพดานต่อ IP แล้ว widget ต้องโผล่บนหน้าล็อกอินจริง */
    public function test_the_widget_appears_after_repeated_failures_from_one_ip(): void
    {
        $this->configureTurnstile();
        $this->customer();

        // ผิด 2 ครั้ง (น้อยกว่าเพดาน 3) — คนพิมพ์พลาดต้องยังไม่เจอด่าน
        $this->failLogin('somsri@example.com', LoginChallenge::IP_THRESHOLD - 1);
        $this->get('/login')->assertDontSee('cf-turnstile', escape: false);

        // ครั้งที่ 3 = ครบเพดาน
        $this->failLogin('somsri@example.com', 1);
        $this->get('/login')
            ->assertSee('cf-turnstile', escape: false)
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', escape: false);
    }

    /**
     * พอด่านขึ้นแล้ว รหัสถูกอย่างเดียวไม่พอ — ต้องมี token ด้วย
     * นี่คือจุดที่หยุดสคริปต์เดารหัสจริง ๆ
     */
    public function test_a_challenged_login_needs_a_token_even_with_the_right_password(): void
    {
        $this->configureTurnstile();
        $customer = $this->customer();

        $this->failLogin('somsri@example.com', LoginChallenge::IP_THRESHOLD);

        $this->post('/login', ['email' => $customer->email, 'password' => 'Password!234'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** เจ้าของบัญชีตัวจริงที่ผ่าน captcha ต้องเข้าได้ และตัวนับต้องถูกล้าง */
    public function test_a_valid_token_lets_the_real_owner_in_and_clears_the_counters(): void
    {
        $this->configureTurnstile();
        $customer = $this->customer();

        $this->failLogin('somsri@example.com', LoginChallenge::IP_THRESHOLD);

        $this->post('/login', [
            'email' => $customer->email,
            'password' => 'Password!234',
            'cf-turnstile-response' => 'good-token',
        ], ['REMOTE_ADDR' => '203.0.113.9'])->assertRedirect();

        $this->assertAuthenticatedAs($customer);

        // ล้างแล้วจริง — ออกจากระบบแล้วล็อกอินใหม่ต้องไม่ต้องทำ captcha อีก
        $this->post('/logout');
        $this->get('/login')->assertDontSee('cf-turnstile', escape: false);
    }

    /**
     * บ็อตเน็ตรุมบัญชีเดียวจากหลาย IP — แกน "ต่อ IP" นับไม่ติดเลยเพราะ
     * แต่ละ IP ยิงครั้งเดียว แกน "ต่อบัญชี" คือแกนที่กันท่านี้ได้
     *
     * ท่านี้คือท่าที่ใช้เดารหัสแอดมินจริง ถ้าไม่มีแกนนี้ การใส่ Turnstile
     * ที่ /admin/login ก็ไร้ความหมาย เพราะย้ายมายิง /login ได้
     */
    public function test_a_targeted_account_is_challenged_even_from_a_fresh_ip(): void
    {
        $this->configureTurnstile();
        $customer = $this->customer();

        for ($i = 0; $i < LoginChallenge::IDENTITY_THRESHOLD; $i++) {
            $this->post('/login',
                ['email' => 'somsri@example.com', 'password' => 'nope'],
                ['REMOTE_ADDR' => '198.51.100.'.$i],   // IP ไม่ซ้ำกันเลย
            );
        }

        // IP ใหม่ที่สะอาด — แกน IP ยังเป็น 0 แต่ต้องถูกท้าทายเพราะแกนบัญชี
        $this->post('/login',
            ['email' => $customer->email, 'password' => 'Password!234'],
            ['REMOTE_ADDR' => '198.51.100.250'],
        )->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * ต่อจากเคสบน: รอบที่ถูกปฏิเสธเพราะไม่มี token ต้องปักธงให้ widget โผล่
     * รอบถัดไป ไม่งั้นเจ้าของบัญชีติดวน — หน้า GET ไม่รู้ว่าจะกรอกบัญชีไหน
     * จึงเช็คได้แค่แกน IP ซึ่งยังเป็น 0 แล้วจะไม่มี widget ให้กรอกตลอดไป
     */
    public function test_the_widget_appears_after_a_missing_token_on_the_identity_axis(): void
    {
        $this->configureTurnstile();
        $this->customer();

        for ($i = 0; $i < LoginChallenge::IDENTITY_THRESHOLD; $i++) {
            $this->post('/login',
                ['email' => 'somsri@example.com', 'password' => 'nope'],
                ['REMOTE_ADDR' => '198.51.100.'.$i],
            );
        }

        $this->post('/login',
            ['email' => 'somsri@example.com', 'password' => 'Password!234'],
            ['REMOTE_ADDR' => '198.51.100.250'],
        )->assertSessionHasErrors('email');

        $this->get('/login')->assertSee('cf-turnstile', escape: false);
    }

    /**
     * captcha หมดอายุ/ไม่ผ่าน ต้องไม่ถูกนับเป็น "เดารหัสผิด"
     * ไม่งั้นคนที่นั่งกรอกนาน ๆ จะถูกลงโทษเหมือนคนเดารหัส
     */
    public function test_a_failed_captcha_does_not_count_as_a_wrong_password(): void
    {
        $this->configureTurnstile(accepted: false);
        $this->customer();

        $this->failLogin('somsri@example.com', LoginChallenge::IP_THRESHOLD);

        $before = LoginChallenge::required('203.0.113.9', 'somsri@example.com');
        $this->assertTrue($before, 'ครบเพดานแล้วต้องถูกท้าทาย');

        // ยิงอีก 5 รอบที่ captcha ไม่ผ่าน — ตัวนับ "รหัสผิด" ต้องไม่ขยับ
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'somsri@example.com',
                'password' => 'Password!234',
                'cf-turnstile-response' => 'bad-token',
            ], ['REMOTE_ADDR' => '203.0.113.9'])->assertSessionHasErrors('email');
        }

        // ต้องล้าง session ก่อน = จำลองว่าเป็นเบราว์เซอร์อีกเครื่อง ไม่งั้นจะติด
        // ธง sticky ของเครื่องที่เพิ่งกรอกผิด (ซึ่งตั้งไว้ถูกต้องตามดีไซน์)
        // แล้ววัดแกนบัญชีไม่ได้เลย
        $this->flushSession();

        // ถ้าเคยถูกนับรวม แกนบัญชีจะทะลุ IDENTITY_THRESHOLD ไปแล้ว
        // และ IP อื่นที่ไม่เกี่ยวข้องจะถูกท้าทายด้วยโดยไม่มีเหตุ
        $this->assertFalse(
            LoginChallenge::required('203.0.113.77', 'somsri@example.com'),
            'captcha ไม่ผ่านต้องไม่ดันแกนบัญชีให้ IP อื่นโดนท้าทายไปด้วย',
        );
    }

    /**
     * เบอร์เดียวกันที่พิมพ์คนละรูปแบบต้องนับเป็นบัญชีเดียว
     * ไม่งั้นคนเดารหัสแค่สลับ 081-234-5678 / +66812345678 ก็รีเซ็ตตัวนับได้
     */
    public function test_the_same_phone_typed_differently_counts_as_one_account(): void
    {
        $this->configureTurnstile();
        User::factory()->create(['phone' => '0812345678', 'password' => 'Password!234']);

        $forms = ['081-234-5678', '+66812345678', '0812345678', '081 234 5678', '66812345678'];

        foreach (array_slice($forms, 0, LoginChallenge::IDENTITY_THRESHOLD) as $i => $typed) {
            $this->post('/login',
                ['email' => $typed, 'password' => 'nope'],
                ['REMOTE_ADDR' => '198.51.100.'.$i],
            );
        }

        $this->assertTrue(
            LoginChallenge::required('198.51.100.250', '0812345678'),
            'ทุกรูปแบบต้องนับลงคีย์เดียวกัน ไม่งั้นสลับรูปแบบก็หนีตัวนับได้',
        );
    }

    /**
     * ยังไม่ได้ตั้งคีย์ = ด่านหลับสนิท กรอกผิดกี่ครั้งก็ไม่ขอ token
     *
     * ถ้าพลาดข้อนี้ หน้าล็อกอินจะขอสิ่งที่ผู้ใช้ให้ไม่ได้ ลูกค้าเข้าระบบไม่ได้
     * ทั้งเว็บ และเครื่อง dev/CI ที่ไม่มีคีย์ก็ทดสอบอะไรไม่ได้เลย
     */
    public function test_nothing_is_challenged_when_turnstile_is_not_configured(): void
    {
        config(['services.turnstile.site_key' => '', 'services.turnstile.secret' => '']);
        $customer = $this->customer();

        $this->failLogin('somsri@example.com', LoginChallenge::IP_THRESHOLD + 3);

        $this->get('/login')->assertDontSee('cf-turnstile', escape: false);

        // ยังเหลือโควตา rate limit ของ LoginRequest (5/นาที) อยู่พอดีไหม —
        // ผิดไป 6 ครั้งแล้วจึงต้องรอ ตรวจแค่ว่าไม่ได้ถูกขอ token คือพอ
        $this->assertFalse(LoginChallenge::required('203.0.113.9', $customer->email));
    }
}
