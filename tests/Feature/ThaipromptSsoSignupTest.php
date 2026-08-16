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
    private function completeCallback(array $profile)
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

        return $this->withSession(['thaiprompt_oauth_state' => $state])
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
