<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Models\Zodiac;
use App\Support\Astrology;
use Database\Seeders\ZodiacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 🇹🇭 (2026-11-07) ราศีบนเว็บเป็นแบบไทย (นิรายนะ ลาหิรี) ชุดเดียวกับผังดวงของบอทแม่หมอบน FB
 *
 * บั๊กที่กันไว้: เว็บใช้ช่วงวันที่สากล ⇒ คนเกิด 7 พ.ย. เว็บบอก "พิจิก" แต่ผังดวงในบิล FB บอก "ตุลย์"
 */
class ThaiZodiacTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, ?string, string}> */
    public static function births(): array
    {
        return [
            '7 พ.ย. — สากลพิจิก ไทยตุลย์' => ['1990-11-07', null, 'libra'],
            '20 พ.ย. — สากลพิจิก ไทยพิจิก' => ['1995-11-20', null, 'scorpio'],
            '1 ม.ค. — สากลมังกร ไทยธนู' => ['1990-01-01', null, 'sagittarius'],
            '13 เม.ย. — สงกรานต์ ยังเป็นมีน' => ['2000-04-13', null, 'pisces'],
            '20 เม.ย. — เมษ' => ['2000-04-20', null, 'aries'],
            '20 มี.ค. — สากลมีน ไทยมีน' => ['1988-03-20', null, 'pisces'],
            // วันที่ดวงอาทิตย์ย้ายราศี: เวลาเกิดตัดสิน (2026-11-17 ย้ายเข้าพิจิกระหว่างวัน)
            'วันย้ายราศี ตี 1' => ['2026-11-16', '01:00', 'libra'],
            'วันย้ายราศี วันถัดไปเย็น' => ['2026-11-17', '20:00', 'scorpio'],
        ];
    }

    #[DataProvider('births')]
    public function test_thai_sign_follows_the_real_sidereal_sun(string $date, ?string $time, string $slug): void
    {
        $this->assertSame($slug, Astrology::thaiZodiacSlug(Carbon::parse($date), $time));
    }

    public function test_seeded_date_ranges_agree_with_the_computed_sign(): void
    {
        $this->seed(ZodiacSeeder::class);
        $months = ['ม.ค.' => 1, 'ก.พ.' => 2, 'มี.ค.' => 3, 'เม.ย.' => 4, 'พ.ค.' => 5, 'มิ.ย.' => 6,
            'ก.ค.' => 7, 'ส.ค.' => 8, 'ก.ย.' => 9, 'ต.ค.' => 10, 'พ.ย.' => 11, 'ธ.ค.' => 12];

        foreach (Zodiac::all() as $z) {
            $this->assertMatchesRegularExpression('/^(\d+) (\S+) – (\d+) (\S+)$/u', $z->date_range);
            preg_match('/^(\d+) (\S+) – (\d+) (\S+)$/u', $z->date_range, $m);
            // วันกลางช่วง (ห่างวันย้ายราศี) ต้องได้ราศีนั้นเสมอ — ช่วงที่โชว์ต้องไม่ขัดกับที่ระบบคำนวณ
            $from = Carbon::create(2026, $months[$m[2]], (int) $m[1]);
            $mid = $from->copy()->addDays(14);
            $this->assertSame($z->slug, Astrology::thaiZodiacSlug($mid), "{$z->name_th}: {$z->date_range}");
        }
        $this->assertSame('Mars', Zodiac::where('slug', 'scorpio')->value('ruler'), 'ดาวเจ้าเรือนแบบไทย ไม่ใช่พลูโต');
    }

    public function test_account_form_derives_the_thai_sign(): void
    {
        $this->seed(ZodiacSeeder::class);
        $u = User::factory()->create();

        $this->actingAs($u)->patch(route('account.astrology.update'), [
            'birth_date' => '1990-11-07',
            'auto_zodiac' => '1',
        ])->assertRedirect();

        $this->assertSame('libra', $u->fresh()->profile->zodiac_slug);
    }

    public function test_migration_moves_auto_signs_and_keeps_hand_picked_ones(): void
    {
        $auto = Profile::create(['user_id' => User::factory()->create()->id, 'birth_date' => '1990-11-07', 'zodiac_slug' => 'scorpio']);
        $blank = Profile::create(['user_id' => User::factory()->create()->id, 'birth_date' => '1990-01-01', 'zodiac_slug' => null]);
        $picked = Profile::create(['user_id' => User::factory()->create()->id, 'birth_date' => '1990-11-07', 'zodiac_slug' => 'leo']);

        $migration = require database_path('migrations/2026_11_07_100001_move_profile_zodiacs_to_thai_signs.php');
        $migration->up();
        $migration->up(); // รันซ้ำได้

        $this->assertSame('libra', $auto->fresh()->zodiac_slug);
        $this->assertSame('sagittarius', $blank->fresh()->zodiac_slug);
        $this->assertSame('leo', $picked->fresh()->zodiac_slug, 'เลือกเองไม่แตะ');
    }
}
