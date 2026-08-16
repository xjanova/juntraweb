<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * บัญชีเดียวต้องเข้าได้ทั้งเว็บและแอพ
 *
 * เว็บยอมให้สมัครด้วย "เบอร์อย่างเดียว" แล้วสร้างอีเมลภายในให้เงียบ ๆ
 * (`p08xxxxxxxx@phone.juntra.local`) ซึ่งเจ้าตัวไม่มีทางรู้ ก่อนหน้านี้ API
 * ของแอพบังคับ `email` อย่างเดียว ลูกค้ากลุ่มนี้จึง **สมัครทางเว็บแล้ว
 * ล็อกอินแอพไม่ได้เลย** ทั้งที่เป็นบัญชีเดียวกัน — เทสต์ชุดนี้ตรึงไว้ไม่ให้
 * ถอยกลับไปเป็นแบบนั้นอีก
 *
 * ตรึงเพิ่ม: ประตูหน้าที่ไม่ต้องล็อกอิน (login/register) ต้องมี throttle
 * เพราะ Laravel 11 ไม่ได้ใส่ `throttle:api` ให้อัตโนมัติ
 */
class MobileAuthParityTest extends TestCase
{
    use RefreshDatabase;

    /** คนที่สมัครทางเว็บด้วยเบอร์ ต้องล็อกอินแอพด้วยเบอร์เดียวกันได้ */
    public function test_web_phone_signup_can_sign_in_on_mobile_with_the_phone(): void
    {
        $this->post('/register', [
            'name'                  => 'ยายสมศรี',
            'phone'                 => '081-234-5678',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect();

        $user = User::where('phone', '0812345678')->firstOrFail();
        $this->assertTrue(
            PhoneNumber::isPlaceholderEmail($user->email),
            'เว็บต้องสร้างอีเมลภายในให้เมื่อสมัครด้วยเบอร์'
        );

        // เจ้าตัวรู้แค่เบอร์ตัวเอง — ต้องพอสำหรับเข้าแอพ
        $r = $this->postJson('/api/v1/auth/login', [
            'login'    => '081-234-5678',
            'password' => 'password123',
            'device'   => 'mobile',
        ]);

        $r->assertOk();
        $this->assertNotEmpty($r->json('data.token'));
        $this->assertSame($user->id, $r->json('data.user.id'));
    }

    /** ฟอร์แมตเบอร์แบบไหนก็ต้องเข้าได้ — +66 / เว้นวรรค / ขีด */
    public function test_mobile_login_accepts_every_thai_phone_format(): void
    {
        $user = User::factory()->create([
            'phone'    => '0812345678',
            'password' => Hash::make('password123'),
        ]);

        foreach (['0812345678', '081-234-5678', '081 234 5678', '+66812345678', '66812345678'] as $typed) {
            $r = $this->postJson('/api/v1/auth/login', [
                'login'    => $typed,
                'password' => 'password123',
            ]);

            $r->assertOk("ล็อกอินด้วย \"$typed\" ต้องผ่าน");
            $this->assertSame($user->id, $r->json('data.user.id'));
        }
    }

    /** APK รุ่นเก่าส่งคีย์ `email` — ต้องใช้งานได้เหมือนเดิม ห้ามพังคนที่ลงแอพไว้แล้ว */
    public function test_legacy_email_key_still_works(): void
    {
        User::factory()->create([
            'email'    => 'somchai@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'somchai@example.com',
            'password' => 'password123',
        ])->assertOk();
    }

    /** สมัครจากแอพด้วยเบอร์อย่างเดียว แล้วต้องกลับไปล็อกอินฝั่งเว็บได้ด้วย */
    public function test_mobile_register_with_phone_only_then_logs_in_on_web(): void
    {
        $r = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'ลุงมานพ',
            'phone'                 => '0898765432',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'device'                => 'mobile',
        ]);

        $r->assertCreated();
        $user = User::where('phone', '0898765432')->firstOrFail();
        $this->assertSame('mobile', $user->signup_via);
        $this->assertTrue(PhoneNumber::isPlaceholderEmail($user->email));

        // อีเมลภายในต้องไม่ถูกส่งกลับไปให้แอพโชว์เป็น "อีเมลของคุณ"
        $this->assertNull($r->json('data.user.email'));
        $this->assertSame('0898765432', $r->json('data.user.phone'));

        // บัญชีเดียวกันต้องเข้าเว็บได้ด้วยเบอร์
        $this->post('/login', [
            'email'    => '0898765432',
            'password' => 'password123',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    /** สมัครจากแอพต้องกรอกอีเมลหรือเบอร์อย่างน้อยหนึ่งอย่าง */
    public function test_mobile_register_requires_email_or_phone(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'ไม่มีอะไรเลย',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);
    }

    /** เบอร์ห้ามซ้ำ ไม่งั้นสองคนใช้บัญชีทับกัน — และต้องไม่ 500 */
    public function test_mobile_register_rejects_duplicate_phone(): void
    {
        User::factory()->create(['phone' => '0811112222']);

        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'คนที่สอง',
            'phone'                 => '+66811112222',   // เบอร์เดียวกัน คนละรูปแบบ
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    /**
     * เบอร์ที่หลักไม่พอต้องถูกปฏิเสธตั้งแต่สมัคร
     *
     * ของเดิม normalise ฝั่งสมัครรับตัวเลขกี่หลักก็ได้ แต่ฝั่งล็อกอินต้อง ≥ 9
     * หลัก คนที่พิมพ์ "123" จึงได้บัญชีที่ล็อกอินกลับเข้ามาไม่ได้ตลอดกาล
     */
    public function test_short_phone_is_rejected_instead_of_creating_an_unusable_account(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'เบอร์สั้น',
            'phone'                 => '123',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->post('/register', [
            'name'                  => 'เบอร์สั้นบนเว็บ',
            'phone'                 => '123',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('phone');

        $this->assertSame(0, User::count());
    }

    /**
     * บัญชีที่เกิดจาก SSO ต้องเดารหัสผ่านไม่ได้
     *
     * ThaipromptController ตั้งรหัสสุ่ม 40 ตัวให้ (users.password เป็น NOT NULL
     * จึงไม่มีทางเป็น null จริง) — ที่ต้องตรึงคือ "เดาไม่ได้" และตอบ 401
     * ไม่ใช่หลุดเป็น 500 หรือเผลอปล่อยผ่านเพราะรหัสผ่านว่าง
     */
    public function test_sso_created_account_cannot_be_signed_into_by_guessing(): void
    {
        User::factory()->create([
            'email'    => 'sso@example.com',
            'password' => Hash::make(\Illuminate\Support\Str::random(40)),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login'    => 'sso@example.com',
            'password' => '',
        ])->assertStatus(422);   // รหัสผ่านว่าง = ไม่ผ่าน validation ตั้งแต่ต้น

        $this->postJson('/api/v1/auth/login', [
            'login'    => 'sso@example.com',
            'password' => 'password123',
        ])->assertStatus(401);
    }

    /** เดารหัสผ่านรัว ๆ ต้องโดนจำกัด — ก่อนหน้านี้ /api ไม่มี throttle เลย */
    public function test_login_is_rate_limited(): void
    {
        User::factory()->create([
            'email'    => 'target@example.com',
            'password' => Hash::make('password123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'login'    => 'target@example.com',
                'password' => 'wrong-guess-' . $i,
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'login'    => 'target@example.com',
            'password' => 'password123',   // ถูกก็ต้องโดนกั้น
        ])->assertStatus(429);
    }

    /** สมัครรัว ๆ จาก IP เดียวต้องโดนจำกัด (แอพไม่มี Turnstile) */
    public function test_register_is_rate_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'name'                  => "บอท $i",
                'email'                 => "bot$i@example.com",
                'password'              => 'password123',
                'password_confirmation' => 'password123',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'บอทตัวที่สี่',
            'email'                 => 'bot4@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(429);
    }

    /** /auth/me ต้องไม่หลุดอีเมลภายในออกไป และต้องมีเบอร์ให้แอพโชว์ */
    public function test_me_hides_the_placeholder_email_and_exposes_the_phone(): void
    {
        $user = User::factory()->create([
            'phone'    => '0800000000',
            'email'    => PhoneNumber::placeholderEmail('0800000000'),
            'password' => Hash::make('password123'),
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $r = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $r->assertOk();
        $this->assertNull($r->json('data.email'));
        $this->assertSame('0800000000', $r->json('data.phone'));
        $this->assertSame('0800000000', $r->json('data.login_hint'));
    }
}
