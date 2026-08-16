<?php

namespace Tests\Feature;

use App\Models\TarotCard;
use App\Support\ThaiAstro;
use Carbon\Carbon;
use Database\Seeders\ZodiacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /v1/almanac/today ต้องคำนวณจริง ไม่ใช่ข้อความคงที่
 *
 * เหตุที่มีเทสต์ชุดนี้: หน้าแรกของแอพเคยพิมพ์ "พระจันทร์ขึ้นกุมราศีกรกฎ"
 * ไว้ตรง ๆ ในโค้ด ทุกคนเห็นเหมือนกันทุกวัน ทั้งที่จันทร์ย้ายราศีทุก ~2.3 วัน
 * เทสต์นี้จึงตรึงสองอย่าง: (1) ค่าตรงกับ ThaiAstro แหล่งเดียวของระบบ
 * (2) ค่า **เปลี่ยนจริง** เมื่อวันเปลี่ยน — ถ้าใครเผลอ hardcode กลับมาจะแดงทันที
 *
 * ตามกฎข้อ 5 ของโปรเจกต์: ตรึงวันด้วย setTestNow() และคำนวณคุณสมบัติของวันนั้น
 * แทนการเดาว่าวันไหนข้างขึ้น/ข้างแรม
 */
class AlmanacApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ZodiacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ดิถีที่ API ตอบ ต้องเท่ากับที่ ThaiAstro คำนวณเป๊ะ ไม่คำนวณซ้ำคนละสูตร */
    public function test_tithi_matches_thai_astro_exactly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 09:00:00', ThaiAstro::TZ));

        $expected = ThaiAstro::tithi(Carbon::now(ThaiAstro::TZ));

        $r = $this->getJson('/api/v1/almanac/today');

        $r->assertOk();
        $this->assertSame($expected['tithi'], $r->json('data.tithi.number'));
        $this->assertSame($expected['side'], $r->json('data.tithi.side'));
        $this->assertSame($expected['label'], $r->json('data.tithi.label'));
        $this->assertSame($expected['is_holy'], $r->json('data.tithi.is_holy'));
    }

    /** ราศีที่จันทร์เสวย ต้องมาจากลองจิจูดนิรายนะจริง (30° ต่อราศี เริ่มที่เมษ) */
    public function test_moon_sign_matches_the_sidereal_longitude(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 09:00:00', ThaiAstro::TZ));

        $noon    = Carbon::parse('2026-08-16 12:00:00', ThaiAstro::TZ);
        $lon     = ThaiAstro::moonSidereal($noon);
        $idx     = (int) floor($lon / 30.0) % 12;
        $expected = ['เมษ', 'พฤษภ', 'เมถุน', 'กรกฎ', 'สิงห์', 'กันย์',
                     'ตุล', 'พิจิก', 'ธนู', 'มังกร', 'กุมภ์', 'มีน'][$idx];

        $r = $this->getJson('/api/v1/almanac/today');

        $r->assertOk();
        $this->assertSame($expected, $r->json('data.moon.name_th'));
        $this->assertEqualsWithDelta(
            $lon - $idx * 30, $r->json('data.moon.degree'), 0.01,
            'องศาในราศีต้องตรงกับที่คำนวณได้'
        );
        $this->assertStringContainsString($expected, $r->json('data.headline'));
    }

    /**
     * ค่าต้องเปลี่ยนตามวันจริง — นี่คือหมุดกันการ hardcode กลับมา
     *
     * จันทร์เดินราว 13°/วัน ครบ 12 ราศีในราว 27.3 วัน ดังนั้นภายใน 30 วัน
     * ต้องเห็นราศีอย่างน้อย 10 แบบ และดิถีต้องไม่ซ้ำค่าเดียวทั้งเดือน
     */
    public function test_moon_sign_and_tithi_actually_change_day_to_day(): void
    {
        $signs  = [];
        $tithis = [];

        for ($i = 0; $i < 30; $i++) {
            Carbon::setTestNow(
                Carbon::parse('2026-08-01 09:00:00', ThaiAstro::TZ)->addDays($i)
            );

            $r = $this->getJson('/api/v1/almanac/today');
            $r->assertOk();

            $signs[]  = $r->json('data.moon.name_th');
            $tithis[] = $r->json('data.tithi.label');
        }

        $this->assertGreaterThanOrEqual(
            10, count(array_unique($signs)),
            'จันทร์ต้องผ่านราศีเกือบครบใน 30 วัน — ถ้าเห็นค่าเดียวแปลว่ากลับไป hardcode แล้ว'
        );
        $this->assertGreaterThanOrEqual(
            25, count(array_unique($tithis)),
            'ดิถีต้องเดินไปเรื่อย ๆ ไม่ใช่ค้างค่าเดียว'
        );
    }

    /** ยามปัจจุบันต้องเป็นยามที่ครอบเวลานั้นจริง ตรงกับตารางอัฐกาล */
    public function test_current_yam_matches_the_atthakan_table(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 09:00:00', ThaiAstro::TZ));

        $expected = ThaiAstro::yamAt(Carbon::now(ThaiAstro::TZ));

        $r = $this->getJson('/api/v1/almanac/today');

        $r->assertOk();
        $this->assertSame($expected['name'], $r->json('data.yam.name'));
        $this->assertSame($expected['no'], $r->json('data.yam.no'));
        $this->assertSame($expected['side'], $r->json('data.yam.side'));
    }

    /** เปิดสาธารณะ — หน้าแรกแสดงให้ผู้ที่ยังไม่ล็อกอินเห็นด้วย */
    public function test_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/almanac/today')->assertOk();
    }

    /**
     * ไพ่ประจำวันต้องคงที่ภายในวัน แต่เปลี่ยนเมื่อขึ้นวันใหม่
     *
     * หน้าแรกของแอพเคย hardcode `tarotDeck[18]` (The Moon) — ทุกคนเห็นไพ่ใบ
     * เดียวกันตลอดกาล เทสต์นี้ตรึงทั้งสองด้าน: ยิงซ้ำวันเดิมต้องได้ใบเดิม
     * และภายในหนึ่งเดือนต้องเห็นหลายใบ ไม่ใช่ใบเดียว
     */
    public function test_daily_card_is_stable_within_a_day_and_changes_across_days(): void
    {
        foreach (['fool', 'magician', 'moon', 'sun', 'star', 'tower', 'world'] as $i => $slug) {
            TarotCard::create([
                'slug'                 => $slug,
                'name_en'              => ucfirst($slug),
                'name_th'              => 'ไพ่' . $slug,
                'arcana'               => 'major',
                'suit'                 => 'major',
                'number'               => $i,
                'upright_meaning_th'   => 'ความหมายหงาย ' . $slug,
                'reversed_meaning_th'  => 'ความหมายกลับ ' . $slug,
                'active'               => true,
            ]);
        }

        Carbon::setTestNow(Carbon::parse('2026-08-16 09:00:00', ThaiAstro::TZ));
        $first = $this->getJson('/api/v1/almanac/today')->json('data.daily_card.slug');

        Carbon::setTestNow(Carbon::parse('2026-08-16 21:30:00', ThaiAstro::TZ));
        $sameDay = $this->getJson('/api/v1/almanac/today')->json('data.daily_card.slug');

        $this->assertNotNull($first, 'ต้องมีไพ่ประจำวันเสมอเมื่อมีไพ่ในระบบ');
        $this->assertSame($first, $sameDay, 'วันเดียวกันต้องได้ไพ่ใบเดิมทั้งวัน');

        $seen = [];
        for ($i = 0; $i < 30; $i++) {
            Carbon::setTestNow(
                Carbon::parse('2026-08-01 09:00:00', ThaiAstro::TZ)->addDays($i)
            );
            $seen[] = $this->getJson('/api/v1/almanac/today')->json('data.daily_card.slug');
        }

        $this->assertGreaterThanOrEqual(
            4, count(array_unique($seen)),
            'ไพ่ประจำวันต้องเปลี่ยนตามวัน ไม่ใช่ค้างใบเดียวแบบที่หน้าแรกเคยเป็น'
        );
    }
}
