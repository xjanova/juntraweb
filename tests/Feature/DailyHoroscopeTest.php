<?php

namespace Tests\Feature;

use App\Models\DailyHoroscope;
use App\Models\Zodiac;
use App\Services\DailyHoroscopeWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ดวงรายวันต้องต่างกันจริงทั้ง 12 ราศี
 *
 * 🔴 บั๊กที่กันไว้ (ยืนยันบน prod 2026-07-26): AiOracle::generateDailyHoroscope()
 * ตกไป fallback ที่ hardcode ไว้เสมอ (เพราะ ai_api_key บนโปรดักชันเป็นค่าว่าง)
 * ทั้ง 12 ราศีจึงได้ 4 ย่อหน้าชุดเดียวกันเป๊ะ ต่างกันแค่เลขนำโชค/สี/ไพ่ ที่สุ่มจาก crc32
 * — เปิด /horoscope/aries กับ /horoscope/leo แล้วอ่านได้ข้อความเดียวกันคำต่อคำ
 */
class DailyHoroscopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    /**
     * ต้อง seed ครบ 12 ราศี ไม่ใช่ 4
     *
     * รอบแรก seed แค่ 4 ราศี (ธาตุละหนึ่ง) เทสต์เลยผ่านทั้งที่ของจริงบนโปรดักชัน
     * ยังพัง — เมษ/สิงห์/ธนู เป็นธาตุไฟเหมือนกัน ถ้าข้อความอิงแค่ธาตุ สามราศีนั้น
     * จะอ่านได้เหมือนกัน 3 ใน 5 ช่อง (ยืนยันบน prod: career/money/health distinct 2/3)
     */
    private function seedZodiacs(): void
    {
        $signs = [
            ['aries', 'Aries', 'เมษ', '♈', 'Fire', 'Mars', 'กล้าหาญ มุ่งมั่น'],
            ['taurus', 'Taurus', 'พฤษภ', '♉', 'Earth', 'Venus', 'มั่นคง รักความสบาย'],
            ['gemini', 'Gemini', 'เมถุน', '♊', 'Air', 'Mercury', 'ช่างพูด ปรับตัวเก่ง'],
            ['cancer', 'Cancer', 'กรกฎ', '♋', 'Water', 'Moon', 'อ่อนโยน รักครอบครัว'],
            ['leo', 'Leo', 'สิงห์', '♌', 'Fire', 'Sun', 'สง่างาม มีภาวะผู้นำ'],
            ['virgo', 'Virgo', 'กันย์', '♍', 'Earth', 'Mercury', 'ละเอียด ช่างสังเกต'],
            ['libra', 'Libra', 'ตุล', '♎', 'Air', 'Venus', 'ประนีประนอม รักความงาม'],
            ['scorpio', 'Scorpio', 'พิจิก', '♏', 'Water', 'Mars', 'ลึกซึ้ง มุ่งมั่นเงียบ ๆ'],
            ['sagittarius', 'Sagittarius', 'ธนู', '♐', 'Fire', 'Jupiter', 'รักอิสระ มองไกล'],
            ['capricorn', 'Capricorn', 'มังกร', '♑', 'Earth', 'Saturn', 'อดทน มีวินัย'],
            ['aquarius', 'Aquarius', 'กุมภ์', '♒', 'Air', 'Saturn', 'คิดต่าง รักเพื่อน'],
            ['pisces', 'Pisces', 'มีน', '♓', 'Water', 'Jupiter', 'อ่อนไหว มีจินตนาการ'],
        ];
        foreach ($signs as $i => [$slug, $en, $th, $glyph, $element, $ruler, $traits]) {
            Zodiac::create([
                'slug' => $slug, 'name_en' => $en, 'name_th' => $th, 'glyph' => $glyph,
                'element' => $element, 'ruler' => $ruler, 'date_range' => '1 ม.ค. - 31 ม.ค.',
                'order_index' => $i + 1, 'traits_th' => $traits,
            ]);
        }
    }

    public function test_every_sign_gets_a_different_reading(): void
    {
        $this->seedZodiacs();
        $writer = app(DailyHoroscopeWriter::class);
        $today = Carbon::today();

        // ทุกช่องต้องต่างกันข้ามราศี — ของเดิมเหมือนกันหมดยกเว้นเลข/สี/ไพ่
        $columns = ['summary' => [], 'love' => [], 'career' => [], 'money' => [], 'health' => []];
        foreach (Zodiac::all() as $z) {
            $row = $writer->write($z, $today);
            foreach (array_keys($columns) as $c) {
                $columns[$c][] = $row[$c];
            }

            $this->assertStringContainsString($z->name_th, $row['summary'], 'คำโปรยต้องเอ่ยชื่อราศีนั้น');
            $this->assertFalse($row['ai_generated'], 'ยังไม่มีคีย์พูล → ต้องเป็นเส้นทางที่เขียนเอง');
        }

        foreach ($columns as $name => $values) {
            $this->assertCount(
                count($values),
                array_unique($values),
                "ช่อง {$name} ต้องไม่ซ้ำกันข้ามราศี — นี่คือบั๊กเดิมที่ทั้ง 12 ราศีอ่านได้ข้อความเดียวกัน",
            );
        }
    }

    public function test_reading_changes_from_day_to_day(): void
    {
        $this->seedZodiacs();
        $writer = app(DailyHoroscopeWriter::class);
        $aries = Zodiac::where('slug', 'aries')->firstOrFail();

        $seen = [];
        for ($i = 0; $i < 10; $i++) {
            $seen[] = $writer->write($aries, Carbon::today()->addDays($i))['summary'];
        }

        // ดิถีเดินทุกวัน + วารเปลี่ยนทุกวัน → คำโปรยต้องหลากหลาย ไม่ใช่ค่าคงที่
        $this->assertGreaterThanOrEqual(7, count(array_unique($seen)), 'ดวงต้องเปลี่ยนไปตามวัน');
    }

    /** คำโปรยต้องอ้างดิถีจริงของวันนั้น ไม่ใช่ข้อความลอย ๆ */
    public function test_reading_cites_the_real_lunar_day(): void
    {
        $this->seedZodiacs();
        $aries = Zodiac::where('slug', 'aries')->firstOrFail();
        $today = Carbon::today();

        $row = app(DailyHoroscopeWriter::class)->write($aries, $today);
        $tithi = \App\Support\ThaiAstro::tithi($today);

        $this->assertStringContainsString($tithi['label'], $row['summary']);
    }

    /** หน้าเว็บต้องแสดงผลและเก็บลง DB ครั้งเดียวต่อราศีต่อวัน */
    public function test_page_renders_and_caches_one_row_per_sign_per_day(): void
    {
        $this->seedZodiacs();

        $this->get('/horoscope/aries')->assertOk()->assertSee('เมษ');
        $this->get('/horoscope/aries')->assertOk();

        $this->assertSame(1, DailyHoroscope::count(), 'เปิดซ้ำต้องไม่สร้างแถวใหม่');

        $this->get('/horoscope/taurus')->assertOk();
        $this->assertSame(2, DailyHoroscope::count());

        // เนื้อหาของสองราศีต้องไม่เหมือนกัน
        $rows = DailyHoroscope::pluck('summary')->all();
        $this->assertNotSame($rows[0], $rows[1]);
    }

    /** ค่าที่ยาวเกินความกว้างคอลัมน์เคยทำหน้าราศี 500 มาแล้ว — ต้องถูกตัดเสมอ */
    public function test_lucky_fields_stay_within_column_widths(): void
    {
        $this->seedZodiacs();
        $writer = app(DailyHoroscopeWriter::class);

        foreach (Zodiac::all() as $z) {
            $row = $writer->write($z, Carbon::today());
            $this->assertLessThanOrEqual(8, mb_strlen($row['lucky_number']));
            $this->assertLessThanOrEqual(32, mb_strlen($row['lucky_color']));
            $this->assertLessThanOrEqual(64, mb_strlen($row['lucky_card']));
        }
    }
}
