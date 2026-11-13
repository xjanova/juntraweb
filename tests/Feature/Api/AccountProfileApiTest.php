<?php

namespace Tests\Feature\Api;

use App\Models\ChineseZodiac;
use App\Models\Profile;
use App\Models\User;
use App\Models\Zodiac;
use App\Support\Astrology;
use App\Support\PhoneNumber;
use Database\Seeders\ZodiacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🪐 (2026-11-12) แอพ: ข้อมูลโหราศาสตร์ — กติกาเดียวกับหน้าเว็บ /account/astrology (ProfileAstrology)
 *
 * ล็อกไว้:
 *  - ยังไม่มีแถวโปรไฟล์ก็เปิดได้ · PATCH บางช่อง ช่องอื่นคงเดิม
 *  - ราศีคำนวณที่เซิร์ฟเวอร์จาก Astrology เท่านั้น (เทียบกับผลของ Astrology ตรง ๆ ไม่เดาราศีเอง)
 *  - แก้อีเมล/เบอร์/ราศีจากแอพไม่ได้ · ข้อความผิดพลาดเป็นภาษาไทย
 */
class AccountProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ZodiacSeeder::class);
    }

    private function member(array $attrs = []): User
    {
        $u = User::factory()->create(array_merge(['name' => 'สมหญิง ใจดี', 'email' => 'somying@example.test', 'phone' => '0812345678'], $attrs));
        Sanctum::actingAs($u);

        return $u;
    }

    private function thai(string $date, ?string $time = null): string
    {
        return Astrology::thaiZodiacSlug(Carbon::parse($date), $time);
    }

    public function test_both_endpoints_need_a_login(): void
    {
        $this->getJson('/api/v1/account/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/account/profile', ['line_id' => '@x'])->assertUnauthorized();
        $this->assertSame(0, Profile::count());
    }

    public function test_a_member_without_a_profile_row_gets_an_empty_profile(): void
    {
        $u = $this->member();

        $this->getJson('/api/v1/account/profile')->assertOk()
            ->assertExactJson(['data' => [
                'name' => 'สมหญิง ใจดี', 'email' => 'somying@example.test', 'phone' => '0812345678', 'contact_phone' => null,
                'birth_date' => null, 'birth_time' => null, 'birth_place' => null,
                'zodiac' => null, 'chinese_zodiac' => null, 'line_id' => null, 'subscribed' => false,
            ]]);
        $this->assertNotNull($u->fresh()->profile, 'สร้างแถวว่างให้ เหมือนหน้าเว็บ');
    }

    public function test_the_system_made_email_of_a_phone_signup_is_not_shown(): void
    {
        $this->member(['email' => '0812345678@'.PhoneNumber::PLACEHOLDER_EMAIL_DOMAIN]);

        $this->getJson('/api/v1/account/profile')->assertOk()
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.phone', '0812345678');
    }

    public function test_the_signs_are_derived_by_astrology_from_the_birth_date(): void
    {
        $u = $this->member();

        $res = $this->patchJson('/api/v1/account/profile', ['birth_date' => '1990-11-07', 'birth_time' => '06:15'])->assertOk();

        $slug = $this->thai('1990-11-07', '06:15');
        $res->assertJsonPath('data.birth_date', '1990-11-07')
            ->assertJsonPath('data.birth_time', '06:15')
            ->assertJsonPath('data.zodiac', ['slug' => $slug, 'name_th' => Zodiac::where('slug', $slug)->value('name_th')])
            ->assertJsonPath('data.chinese_zodiac.slug', Astrology::chineseZodiacSlug(1990))
            ->assertJsonPath('data.chinese_zodiac.name_th', ChineseZodiac::where('slug', Astrology::chineseZodiacSlug(1990))->value('name_th'));
        $this->assertSame($slug, $u->profile()->value('zodiac_slug'));

        $this->getJson('/api/v1/account/profile')->assertOk()->assertJsonPath('data.zodiac.slug', $slug);
    }

    public function test_a_new_birth_time_alone_recomputes_with_the_saved_birth_date(): void
    {
        $u = $this->member();
        // ราศีที่เลือกเองบนเว็บ (ไม่ตรงกับวันเกิด) — ต้องถูกคำนวณใหม่เมื่อข้อมูลเกิดเปลี่ยน
        $u->profile()->create(['birth_date' => '1990-11-07', 'zodiac_slug' => 'leo', 'chinese_zodiac_slug' => 'dog']);

        $this->patchJson('/api/v1/account/profile', ['birth_time' => '23:40'])->assertOk()
            ->assertJsonPath('data.birth_date', '1990-11-07')
            ->assertJsonPath('data.birth_time', '23:40')
            ->assertJsonPath('data.zodiac.slug', $this->thai('1990-11-07', '23:40'))
            ->assertJsonPath('data.chinese_zodiac.slug', Astrology::chineseZodiacSlug(1990));
    }

    public function test_a_patch_of_other_fields_leaves_the_rest_alone(): void
    {
        $u = $this->member();
        $u->profile()->create([
            'birth_date' => '1985-03-02', 'birth_time' => '10:00', 'birth_place' => 'เชียงใหม่',
            'zodiac_slug' => 'leo', 'chinese_zodiac_slug' => 'dog', 'phone' => '021234567', 'line_id' => '@old', 'subscribed' => true,
        ]);

        $this->patchJson('/api/v1/account/profile', ['line_id' => '@new'])->assertOk()
            ->assertJsonPath('data.line_id', '@new')
            ->assertJsonPath('data.birth_date', '1985-03-02')
            ->assertJsonPath('data.birth_time', '10:00')
            ->assertJsonPath('data.birth_place', 'เชียงใหม่')
            ->assertJsonPath('data.contact_phone', '021234567')
            ->assertJsonPath('data.subscribed', true)
            ->assertJsonPath('data.name', 'สมหญิง ใจดี')
            // ไม่ได้แตะข้อมูลเกิด = ราศีที่เลือกเองบนเว็บยังอยู่
            ->assertJsonPath('data.zodiac.slug', 'leo')
            ->assertJsonPath('data.chinese_zodiac.slug', 'dog');

        $this->patchJson('/api/v1/account/profile', ['subscribed' => false, 'birth_place' => null, 'name' => 'สมหญิง'])->assertOk()
            ->assertJsonPath('data.subscribed', false)
            ->assertJsonPath('data.birth_place', null)
            ->assertJsonPath('data.name', 'สมหญิง')
            ->assertJsonPath('data.line_id', '@new');
        $this->assertSame('สมหญิง', $u->fresh()->name);
    }

    public function test_clearing_the_birth_date_keeps_the_signs_like_the_web_form(): void
    {
        $u = $this->member();
        $u->profile()->create(['birth_date' => '1990-11-07', 'zodiac_slug' => 'libra', 'chinese_zodiac_slug' => 'horse']);

        $this->patchJson('/api/v1/account/profile', ['birth_date' => null])->assertOk()
            ->assertJsonPath('data.birth_date', null)
            ->assertJsonPath('data.zodiac.slug', 'libra')
            ->assertJsonPath('data.chinese_zodiac.slug', 'horse');
    }

    public function test_email_phone_and_signs_cannot_be_set_from_the_app(): void
    {
        $u = $this->member();
        $u->profile()->create(['phone' => '021234567']);

        $this->patchJson('/api/v1/account/profile', [
            'email' => 'hacker@example.test', 'phone' => '0899999999',
            'zodiac_slug' => 'leo', 'chinese_zodiac_slug' => 'dog', 'auto_zodiac' => false,
            'line_id' => '@me',
        ])->assertOk()
            ->assertJsonPath('data.email', 'somying@example.test')
            ->assertJsonPath('data.phone', '0812345678')
            ->assertJsonPath('data.contact_phone', '021234567')
            ->assertJsonPath('data.zodiac', null)
            ->assertJsonPath('data.chinese_zodiac', null)
            ->assertJsonPath('data.line_id', '@me');

        $fresh = $u->fresh();
        $this->assertSame('somying@example.test', $fresh->email);
        $this->assertSame('0812345678', $fresh->phone);
        $this->assertSame('021234567', $fresh->profile->phone);
        $this->assertNull($fresh->profile->zodiac_slug);
    }

    public function test_bad_input_answers_in_thai_and_saves_nothing(): void
    {
        $u = $this->member();
        $u->profile()->create(['birth_date' => '1990-11-07', 'line_id' => '@old']);

        $cases = [
            ['birth_date', Carbon::now('Asia/Bangkok')->addDays(3)->format('Y-m-d')],
            ['birth_date', '1899-12-31'],
            ['birth_date', 'ไม่ใช่วันที่'],
            ['birth_time', '25:00'],
            ['birth_place', str_repeat('ก', 192)],
            ['line_id', str_repeat('a', 65)],
            ['subscribed', 'maybe'],
            ['name', ''],
            ['name', str_repeat('ก', 256)],
        ];

        foreach ($cases as [$field, $value]) {
            $res = $this->patchJson('/api/v1/account/profile', [$field => $value] + ['line_id' => '@changed'])
                ->assertStatus(422)
                ->assertJsonPath('reason_code', 'validation_failed')
                ->assertJsonValidationErrors([$field]);
            $this->assertMatchesRegularExpression('/\p{Thai}/u', $res->json('message'), $field);
            $this->assertMatchesRegularExpression('/\p{Thai}|LINE/u', $res->json("errors.{$field}.0"), $field);
        }

        $this->assertSame('กรุณากรอกชื่อ', $this->patchJson('/api/v1/account/profile', ['name' => ''])->json('errors.name.0'));
        $this->assertSame('@old', $u->profile()->value('line_id'), 'ข้อมูลผิดหนึ่งช่อง = ไม่บันทึกอะไรเลย');
        $this->assertSame('สมหญิง ใจดี', $u->fresh()->name);
    }

    public function test_today_is_a_valid_birth_date(): void
    {
        $this->member();
        $today = Carbon::now(config('app.timezone'))->format('Y-m-d');

        $this->patchJson('/api/v1/account/profile', ['birth_date' => $today])->assertOk()
            ->assertJsonPath('data.birth_date', $today);
    }
}
