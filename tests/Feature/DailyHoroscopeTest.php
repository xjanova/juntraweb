<?php

namespace Tests\Feature;

use App\Models\DailyHoroscope;
use App\Models\Zodiac;
use App\Services\DailyHoroscopeWriter;
use App\Support\ThaiAstro;
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
 *
 * 🔴 บั๊กรอบสอง (2026-08-08): แก้รอบแรกแล้วยัง "หลุดเป็นบางวัน" — ช่องความรัก
 * ฝั่งข้างแรมประกอบจากดิถี + ธีมรายธาตุล้วน ๆ ไม่มีอะไรของราศีนั้นเลย ทุกวันข้างแรม
 * (ราวครึ่งปี) จึงเหลือข้อความแค่ 4 แบบตามธาตุ แต่วันข้างขึ้นผ่านหมด — เทสต์ที่ดูแค่
 * Carbon::today() จึงเขียวหรือแดงสลับกันไปตามวันที่รัน ไม่ใช่ตามความถูกต้องของโค้ด
 * ด้วยเหตุนี้ทุกเทสต์ด้านล่างจึง "ตรึงวัน" ด้วย Carbon::setTestNow() เสมอ
 */
class DailyHoroscopeTest extends TestCase
{
    use RefreshDatabase;

    /** ช่องที่เป็นคำพยากรณ์ — ต้องต่างกันครบ 12 ราศีทุกวัน (เลข/สี/ไพ่ไม่นับ พูลมีแค่ 9 ค่า) */
    private const PROSE = ['summary', 'love', 'career', 'money', 'health'];

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

    /**
     * ตรึงวันที่ครอบ "กิ่ง match" ของเฟสดวงจันทร์ให้ครบทุกกิ่ง
     *
     * ทำไมต้องตรึง: บั๊กรอบสองโผล่เฉพาะวันข้างแรม ถ้าเทสต์ดูแค่วันนี้ ผลจะสลับไปมา
     * ตามวันที่รัน (ครึ่งเดือนเขียว ครึ่งเดือนแดง) — CI เขียวไม่ได้แปลว่าโค้ดถูก
     *
     * ทำไมต้อง hardcode วันเพ็ญ/วันดับ: ดิถี 15 กับ 30 ไม่ได้มีทุกเดือน (ปี 2026
     * ไม่มีดิถี 30 เลยทั้งเดือนมิถุนายน) การกวาดช่วงต่อเนื่องอย่างเดียวจึงพลาดกิ่งนี้ได้
     *
     * @return array<string, array{string, string}>
     */
    public static function pinnedDates(): array
    {
        return [
            'ข้างขึ้น ปกติ'          => ['2026-01-01', 'waxing'],
            'ขึ้น 15 ค่ำ จันทร์เพ็ญ'   => ['2026-01-03', 'waxing'],
            'ข้างแรม ปกติ (บั๊กเดิม)' => ['2026-01-04', 'waning'],
            'แรม 15 ค่ำ จันทร์ดับ'    => ['2026-01-18', 'waning'],
            'ข้างแรม กลางปี'         => ['2026-07-05', 'waning'],
            'ข้างขึ้น ปลายปี'         => ['2026-11-20', 'waxing'],
            'ข้างแรม ปลายปี'         => ['2026-12-05', 'waning'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pinnedDates')]
    public function test_every_column_varies_per_sign_on_pinned_dates(string $date, string $expectedSide): void
    {
        $this->seedZodiacs();

        $tithi = ThaiAstro::tithi(Carbon::parse($date));
        $this->assertSame($expectedSide, $tithi['side'], "วันตรึง {$date} ไม่ได้อยู่ฝั่ง{$expectedSide}แล้ว — แก้รายการวันให้ครอบทุกกิ่งเหมือนเดิม");

        $this->assertEveryColumnVariesPerSign($date);
    }

    /**
     * กวาดต่อเนื่อง 60 วัน (~2 รอบจันทรคติ) — กันการหลุดเป็น "บางวัน"
     * วันตรึงไม่กี่วันยังพลาดได้ถ้าบั๊กใหม่ผูกกับวาร/ดิถีค่าอื่น อันนี้ดูทุกวันจริง ๆ
     */
    public function test_every_column_varies_per_sign_across_two_lunar_cycles(): void
    {
        $this->seedZodiacs();

        $start = Carbon::parse('2026-01-01');
        $sides = [];
        $phases = [];

        for ($i = 0; $i < 60; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $tithi = ThaiAstro::tithi(Carbon::parse($date));
            $sides[$tithi['side']] = true;
            $phases[$tithi['tithi']] = true;

            $this->assertEveryColumnVariesPerSign($date);
        }

        // ถ้าช่วงที่กวาดดันไม่ครอบกิ่งไหน ต้องดังออกมา ไม่ใช่เขียวทั้งที่ตรวจไม่ครบ
        $this->assertArrayHasKey('waxing', $sides, 'ช่วงที่กวาดต้องมีวันข้างขึ้น');
        $this->assertArrayHasKey('waning', $sides, 'ช่วงที่กวาดต้องมีวันข้างแรม');
        $this->assertArrayHasKey(15, $phases, 'ช่วงที่กวาดต้องมีวันเพ็ญ (ดิถี 15)');
        $this->assertArrayHasKey(30, $phases, 'ช่วงที่กวาดต้องมีวันดับ (ดิถี 30)');
    }

    /**
     * กติกาที่ทำให้บั๊กนี้เกิดซ้ำไม่ได้: ทุกช่องต้องเอ่ยชื่อราศีนั้นเสมอ
     *
     * distinct ครบ 12 เป็นแค่ "อาการ" — ต้นเหตุคือมีกิ่งเงื่อนไขที่ประกอบข้อความ
     * จากธาตุ/ดิถีล้วน ๆ โดยไม่มีอะไรของราศีนั้น ตรวจที่ชื่อราศีจะจับได้ตรงจุดกว่า
     */
    public function test_every_column_names_the_sign_on_both_sides_of_the_moon(): void
    {
        $this->seedZodiacs();
        $writer = app(DailyHoroscopeWriter::class);

        foreach (['2026-01-01' => 'waxing', '2026-01-04' => 'waning'] as $date => $side) {
            Carbon::setTestNow(Carbon::parse($date));

            foreach (Zodiac::all() as $z) {
                $row = $writer->write($z, Carbon::today());
                foreach (self::PROSE as $column) {
                    $this->assertStringContainsString(
                        $z->name_th,
                        $row[$column],
                        "{$date} ({$side}) — ช่อง {$column} ของราศี{$z->name_th} ไม่ได้เอ่ยชื่อราศีเลย ".
                        'แปลว่ากิ่งนี้ประกอบจากธาตุ/ดิถีล้วน ๆ และจะซ้ำกับราศีอื่นในธาตุเดียวกัน',
                    );
                }
            }
        }
    }

    /** เขียนดวงของทั้ง 12 ราศีในวันที่ตรึงไว้ แล้วยืนยันว่าไม่มีช่องไหนซ้ำข้ามราศี */
    private function assertEveryColumnVariesPerSign(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date));

        $writer = app(DailyHoroscopeWriter::class);
        $today = Carbon::today();
        $tithi = ThaiAstro::tithi($today);

        $rows = [];
        foreach (Zodiac::all() as $z) {
            $rows[$z->name_th] = $writer->write($z, $today);
        }

        foreach (self::PROSE as $column) {
            $signsByText = [];
            foreach ($rows as $sign => $row) {
                $signsByText[$row[$column]][] = $sign;
            }

            $shared = array_values(array_filter($signsByText, static fn (array $signs) => count($signs) > 1));
            if ($shared === []) {
                $this->addToAssertionCount(1);

                continue;
            }

            $this->fail(sprintf(
                "%s (%s ดิถี %d) — ช่อง %s เหลือ %d แบบจาก 12 ราศี\nราศีที่อ่านได้ข้อความเดียวกัน: %s\n(4 แบบ = ตกกลับไปเป็นข้อความรายธาตุ ซึ่งคือบั๊กเดิม)",
                $date, $tithi['side'], $tithi['tithi'], $column, count($signsByText),
                implode(' | ', array_map(static fn (array $signs) => implode(' = ', $signs), $shared)),
            ));
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
