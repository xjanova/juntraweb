<?php

namespace Tests\Unit;

use App\Services\AuspiciousScorer;
use App\Support\AuspiciousOccasions;
use App\Support\ThaiAstro;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * ตรึงความถูกต้องของดาราศาสตร์ที่ฤกษ์ทั้งระบบตั้งอยู่
 *
 * ถ้าไฟล์นี้แดง แปลว่าวันที่เว็บขายให้ลูกค้าเป็นวันที่คำนวณผิด — ร้ายแรงกว่า
 * หน้าเว็บพัง เพราะหน้าเว็บพังลูกค้าเห็นเอง แต่ฤกษ์ผิดลูกค้าไม่มีทางรู้
 */
class ThaiAstroTest extends TestCase
{
    /**
     * เทียบกับเวลาจันทร์ดับ/จันทร์เพ็ญจริงของ NASA (Espenak) — ณ เวลานั้น
     * มุมห่างดวงจันทร์-ดวงอาทิตย์ต้องเป็น 0° (ดับ) หรือ 180° (เพ็ญ)
     *
     * @dataProvider syzygies
     */
    public function test_moon_sun_elongation_matches_nasa_syzygy_times(string $utc, float $expected): void
    {
        $t = Carbon::parse($utc, 'UTC');
        $elong = fmod(ThaiAstro::moonTropical($t) - ThaiAstro::sunTropical($t) + 360, 360);

        // ผิดได้ไม่เกิน 0.05° ≈ 6 นาทีของเวลา — ละเอียดพอสำหรับช่องนักษัตร 13°20'
        $diff = fmod($elong - $expected + 540, 360) - 180;
        $this->assertLessThan(0.05, abs($diff), sprintf('%s คลาดเคลื่อน %.4f°', $utc, $diff));
    }

    public static function syzygies(): array
    {
        return [
            'จันทร์ดับ 2000-01-06' => ['2000-01-06 18:14', 0.0],
            'จันทร์เพ็ญ 2000-01-21' => ['2000-01-21 04:40', 180.0],
            'จันทร์เพ็ญ 2025-03-14' => ['2025-03-14 06:55', 180.0],
            'จันทร์ดับ 2026-01-18'  => ['2026-01-18 19:52', 0.0],
        ];
    }

    /** ดวงจันทร์เดินราว 13.2°/วัน → หนึ่งวันต้องครองไม่เกิน 2 นักษัตร และต่อกันสนิท */
    public function test_ruek_windows_tile_the_whole_day_without_gaps(): void
    {
        $day = Carbon::parse('2026-08-20', ThaiAstro::TZ);
        $windows = ThaiAstro::ruekWindows($day);

        $this->assertGreaterThanOrEqual(1, count($windows));
        $this->assertLessThanOrEqual(2, count($windows));

        $this->assertSame('00:00', $windows[0]['from']->format('H:i'));
        $this->assertSame(
            $day->copy()->addDay()->timestamp,
            $windows[count($windows) - 1]['to']->timestamp,
            'ช่วงสุดท้ายต้องจบพอดีที่เที่ยงคืนของวันถัดไป',
        );

        for ($i = 0; $i < count($windows) - 1; $i++) {
            $this->assertSame(
                $windows[$i]['to']->timestamp,
                $windows[$i + 1]['from']->timestamp,
                'ช่วงฤกษ์ต้องต่อกันสนิท ไม่มีรูโหว่',
            );
            // นักษัตรถัดไปต้องเดินหน้าทีละหนึ่ง (วนกลับที่ 27 ได้)
            $this->assertSame(
                ($windows[$i]['nakshatra'] + 1) % 27,
                $windows[$i + 1]['nakshatra'],
            );
        }
    }

    /** ฤกษ์บน 9 ต้องเวียนตามตำรา: นักษัตรที่ 1, 10, 19 เป็นทลิทโทฤกษ์ */
    public function test_ruek_cycle_maps_nakshatras_by_nine(): void
    {
        $this->assertCount(27, ThaiAstro::NAKSHATRAS);
        $this->assertCount(9, ThaiAstro::RUEK);

        // index เป็นฐาน 0 — นักษัตรที่ 1/10/19 ในตำรา = index 0/9/18
        $expected = [
            0 => 'ทลิทโทฤกษ์', 9 => 'ทลิทโทฤกษ์', 18 => 'ทลิทโทฤกษ์',   // อัศวินี · มฆา · มูละ
            7 => 'ราชาฤกษ์', 16 => 'ราชาฤกษ์', 25 => 'ราชาฤกษ์',        // ปุษยะ · อนุราธา · อุตรภัทรบท
            8 => 'สมโณฤกษ์', 26 => 'สมโณฤกษ์',                          // อาศเลษา · เรวดี
        ];
        foreach ($expected as $nak => $name) {
            $this->assertSame($name, ThaiAstro::RUEK[$nak % 9]['name'], "นักษัตร index {$nak}");
        }
    }

    /** ดิถีต้องเดินจากขึ้น 1 ค่ำ ไปแรม 15 ค่ำ แล้ววนใหม่ ไม่กระโดดข้าม */
    public function test_tithi_advances_one_step_per_day(): void
    {
        $day = Carbon::parse('2026-08-01', ThaiAstro::TZ);
        $prev = null;

        for ($i = 0; $i < 30; $i++) {
            $t = ThaiAstro::tithi($day->copy()->addDays($i));
            $this->assertGreaterThanOrEqual(1, $t['tithi']);
            $this->assertLessThanOrEqual(30, $t['tithi']);
            $this->assertGreaterThanOrEqual(1, $t['day']);
            $this->assertLessThanOrEqual(15, $t['day']);

            if ($prev !== null) {
                $step = ($t['tithi'] - $prev + 30) % 30;
                $this->assertContains($step, [0, 1, 2], 'ดิถีต้องเดินทีละ 1 ขั้น (บางวันคงที่หรือข้าม 2 ได้เมื่อจันทร์เร็ว)');
            }
            $prev = $t['tithi'];
        }
    }

    /** ค่าส่องสว่างต้องสอดคล้องกับดิถี — เพ็ญเกือบเต็ม ดับเกือบมืด */
    public function test_illumination_tracks_the_lunar_phase(): void
    {
        $day = Carbon::parse('2026-08-01', ThaiAstro::TZ);
        for ($i = 0; $i < 30; $i++) {
            $t = ThaiAstro::tithi($day->copy()->addDays($i));
            if ($t['tithi'] === 15) {
                $this->assertGreaterThan(0.95, $t['illumination'], 'ขึ้น 15 ค่ำ ต้องสว่างเกือบเต็มดวง');
            }
            if ($t['tithi'] === 30) {
                $this->assertLessThan(0.05, $t['illumination'], 'แรม 15 ค่ำ ต้องเกือบมืดสนิท');
            }
        }
    }

    /**
     * แก่นของการแก้บั๊กรอบนี้: งานคนละหมวดต้องได้วันคนละชุด
     *
     * ของเดิมให้คะแนนจาก "วันอังคาร/พฤหัส/เสาร์ + วันที่ 9/19/29" เท่านั้น
     * ถามเรื่องแต่งงานกับเปิดร้านจึงได้วันชุดเดียวกันเป๊ะ
     */
    public function test_different_occasions_produce_different_days(): void
    {
        $scorer = new AuspiciousScorer();
        $from = Carbon::parse('2026-08-01', ThaiAstro::TZ);
        $to   = $from->copy()->addDays(45);

        $dates = [];
        foreach (['wedding', 'business', 'merit', 'treatment'] as $occ) {
            $picks = $scorer->candidateDays($from, $to, $occ, 5);
            $this->assertNotEmpty($picks, "หมวด {$occ} ต้องมีวันผ่านเกณฑ์อย่างน้อยหนึ่งวันใน 45 วัน");
            $dates[$occ] = array_map(fn ($d) => $d['date']->toDateString(), $picks);
        }

        $this->assertNotSame($dates['wedding'], $dates['business'], 'แต่งงานกับเปิดร้านต้องไม่ได้วันชุดเดียวกัน');
        $this->assertNotSame($dates['wedding'], $dates['treatment'], 'งานมงคลกับงานตัดออกต้องคนละชุด');
    }

    /** งานหมวด "ตัดออก" ต้องเอนไปข้างแรม ส่วนงานมงคลต้องเอนไปข้างขึ้น */
    public function test_treatment_prefers_waning_moon_while_wedding_prefers_waxing(): void
    {
        $scorer = new AuspiciousScorer();
        $from = Carbon::parse('2026-08-01', ThaiAstro::TZ);
        $to   = $from->copy()->addDays(59);

        $waningForTreatment = 0;
        foreach ($scorer->candidateDays($from, $to, 'treatment', 6) as $d) {
            $waningForTreatment += $d['tithi']['side'] === 'waning' ? 1 : 0;
        }
        $waxingForWedding = 0;
        foreach ($scorer->candidateDays($from, $to, 'wedding', 6) as $d) {
            $waxingForWedding += $d['tithi']['side'] === 'waxing' ? 1 : 0;
        }

        $this->assertGreaterThanOrEqual(4, $waningForTreatment, 'งานผ่าตัด/ตัดออกควรตกข้างแรมเป็นส่วนใหญ่');
        $this->assertGreaterThanOrEqual(4, $waxingForWedding, 'งานแต่งควรตกข้างขึ้นเป็นส่วนใหญ่');
    }

    /** ฤกษ์ที่ตำราห้ามต้องไม่โผล่มาเป็นอันดับ 1 ของงานมงคล */
    public function test_forbidden_ruek_never_tops_a_wedding_pick(): void
    {
        $scorer = new AuspiciousScorer();
        $from = Carbon::parse('2026-08-01', ThaiAstro::TZ);

        for ($month = 0; $month < 6; $month++) {
            $start = $from->copy()->addDays($month * 30);
            $picks = $scorer->candidateDays($start, $start->copy()->addDays(29), 'wedding', 3);
            foreach ($picks as $p) {
                $this->assertNotContains(
                    $p['ruek']['key'],
                    ['phetchakhat', 'choro'],
                    'เพชฌฆาตฤกษ์/โจโรฤกษ์ต้องไม่ถูกแนะนำสำหรับงานแต่ง',
                );
            }
        }
    }

    /* ============================================================
       ยามอัฐกาล — ตรึงตัวเลขที่ลูกค้าเอาไปเทียบกับตำราได้
       ============================================================ */

    /**
     * ลำดับเลขยามทั้ง 7 วาร — ตัวเลขชุดนี้คือ "ตำรา" ไม่ใช่ตัวเลือกของเรา
     *
     * ตั้งเลขดาวเจ้าวันไว้ช่องแรก กลางวันบวก 5 กลางคืนบวก 4 เกิน 7 เอา 7 ลบ
     * ถ้าไฟล์นี้แดง แปลว่าตารางที่โชว์ให้ลูกค้าตรวจกับตำราไม่ตรงแล้ว
     *
     * @dataProvider yamSequences
     */
    public function test_yam_sequences_match_the_classical_tables(int $lord, array $day, array $night): void
    {
        $this->assertSame($day, ThaiAstro::yamSequence($lord, false), "ยามกลางวันของวารเลข {$lord}");
        $this->assertSame($night, ThaiAstro::yamSequence($lord, true), "ยามกลางคืนของวารเลข {$lord}");
    }

    public static function yamSequences(): array
    {
        return [
            // วาร => [กลางวัน (+5), กลางคืน (+4)]
            'อาทิตย์'  => [1, [1, 6, 4, 2, 7, 5, 3, 1], [1, 5, 2, 6, 3, 7, 4, 1]],
            'จันทร์'   => [2, [2, 7, 5, 3, 1, 6, 4, 2], [2, 6, 3, 7, 4, 1, 5, 2]],
            'อังคาร'   => [3, [3, 1, 6, 4, 2, 7, 5, 3], [3, 7, 4, 1, 5, 2, 6, 3]],
            'พุธ'      => [4, [4, 2, 7, 5, 3, 1, 6, 4], [4, 1, 5, 2, 6, 3, 7, 4]],
            'พฤหัสบดี' => [5, [5, 3, 1, 6, 4, 2, 7, 5], [5, 2, 6, 3, 7, 4, 1, 5]],
            'ศุกร์'    => [6, [6, 4, 2, 7, 5, 3, 1, 6], [6, 3, 7, 4, 1, 5, 2, 6]],
            'เสาร์'    => [7, [7, 5, 3, 1, 6, 4, 2, 7], [7, 4, 1, 5, 2, 6, 3, 7]],
        ];
    }

    /** ทุกวารต้องมีครบทั้ง 7 ดาว และช่องที่ 8 ต้องวนกลับเป็นเลขเดียวกับช่องแรก */
    public function test_every_watch_cycle_covers_all_seven_planets_and_wraps(): void
    {
        for ($lord = 1; $lord <= 7; $lord++) {
            foreach ([false, true] as $night) {
                $seq = ThaiAstro::yamSequence($lord, $night);

                $this->assertCount(8, $seq);
                $this->assertSame($lord, $seq[0], 'ช่องแรกต้องเป็นดาวเจ้าวัน');
                $this->assertSame($seq[0], $seq[7], 'ช่องที่ 8 ต้องวนกลับเป็นเลขเดียวกับช่องแรก');

                $firstSeven = array_slice($seq, 0, 7);
                sort($firstSeven);
                $this->assertSame(range(1, 7), $firstSeven, 'เจ็ดช่องแรกต้องมีครบทุกดาว ไม่ซ้ำ');
            }
        }
    }

    /** ยามอัฐกาลต้องปูเต็ม 24 ชม. — 8 ยามกลางวัน 06:00–18:00 + 8 ยามกลางคืน 18:00–06:00 */
    public function test_yam_table_tiles_the_full_day_from_six_am(): void
    {
        $table = ThaiAstro::yamAtthakan(Carbon::parse('2026-08-13', ThaiAstro::TZ)); // พฤหัสบดี

        $this->assertSame(5, $table['lord'], 'วันพฤหัสบดีมีดาวเจ้าวันเลข 5');
        $this->assertCount(8, $table['day']);
        $this->assertCount(8, $table['night']);

        $this->assertSame('06:00', $table['day'][0]['starts_at']->format('H:i'));
        $this->assertSame('18:00', $table['day'][7]['ends_at']->format('H:i'));
        $this->assertSame('18:00', $table['night'][0]['starts_at']->format('H:i'));
        $this->assertSame(
            '2026-08-14 06:00',
            $table['night'][7]['ends_at']->format('Y-m-d H:i'),
            'ยามกลางคืนต้องจบที่ 06:00 ของวันถัดไป — วันของโหรไม่ได้เริ่มเที่ยงคืน',
        );

        $all = array_merge($table['day'], $table['night']);
        for ($i = 0; $i < count($all) - 1; $i++) {
            $this->assertSame(
                $all[$i]['ends_at']->timestamp,
                $all[$i + 1]['starts_at']->timestamp,
                'ยามต้องต่อกันสนิทไม่มีรูโหว่',
            );
            $this->assertSame(ThaiAstro::YAM_MINUTES, (int) round($all[$i]['starts_at']->diffInMinutes($all[$i]['ends_at'])));
        }
    }

    /** ชื่อยามผูกกับดาว ไม่ได้ผูกกับลำดับช่อง และเรียกคนละชื่อระหว่างกลางวัน-กลางคืน */
    public function test_watch_names_follow_the_planet_not_the_column(): void
    {
        $sunday   = ThaiAstro::yamRows(ThaiAstro::yamSequence(1, false), false);
        $thursday = ThaiAstro::yamRows(ThaiAstro::yamSequence(5, false), false);

        $this->assertSame('สุริชะ', $sunday[0]['name'], 'ช่องแรกวันอาทิตย์คือยามสุริชะ (อาทิตย์)');
        $this->assertSame('ครู', $thursday[0]['name'], 'ช่องแรกวันพฤหัสบดีคือยามครู (พฤหัสบดี)');
        $this->assertSame('สุริชะ', $thursday[2]['name'], 'ช่องที่ 3 วันพฤหัสบดีลงเลขได้ 1 = ยามสุริชะ');

        $sundayNight = ThaiAstro::yamRows(ThaiAstro::yamSequence(1, true), true);
        $this->assertSame('รวิ', $sundayNight[0]['name'], 'อาทิตย์ตอนกลางคืนเรียกยามรวิ ไม่ใช่สุริชะ');
        $this->assertSame('ชีโว', $sundayNight[1]['name'], 'พฤหัสบดีตอนกลางคืนเรียกยามชีโว');
    }

    /** ก่อน 06:00 ต้องยังนับเป็นยามกลางคืนของ "เมื่อวาน" ตามวันของโหร */
    public function test_yam_at_treats_the_day_as_starting_at_six_am(): void
    {
        // 2026-08-14 คือวันศุกร์ — ตี 2 ของวันศุกร์ยังเป็นยามกลางคืนของวันพฤหัสบดี
        $early = ThaiAstro::yamAt(Carbon::parse('2026-08-14 02:00', ThaiAstro::TZ));
        $this->assertSame('night', $early['side']);
        $this->assertSame(5, $early['lord'], 'ยังอยู่ในวันพฤหัสบดีของโหร');
        $this->assertSame('2026-08-13', $early['date']->toDateString());

        $morning = ThaiAstro::yamAt(Carbon::parse('2026-08-14 07:00', ThaiAstro::TZ));
        $this->assertSame('day', $morning['side']);
        $this->assertSame(6, $morning['lord'], 'หลัง 06:00 เข้าวันศุกร์แล้ว');
        $this->assertSame(1, $morning['no']);
        $this->assertSame('ศุกระ', $morning['name']);
    }

    /** เวลาที่ระบบแนะนำต้องเป็น "เต็มยาม" 90 นาที ไม่ใช่ช่วงกว้าง ๆ ที่เดาเอา */
    public function test_recommended_slot_is_always_a_whole_watch(): void
    {
        $scorer = new AuspiciousScorer();
        $from   = Carbon::parse('2026-08-01', ThaiAstro::TZ);

        foreach ($scorer->candidateDays($from, $from->copy()->addDays(45), 'wedding', 6) as $d) {
            $this->assertNotNull($d['best_from']);
            $this->assertSame(
                ThaiAstro::YAM_MINUTES,
                (int) round($d['best_from']->diffInMinutes($d['best_to'])),
                $d['label'].' ต้องแนะนำเป็นเต็มยาม',
            );
            $this->assertGreaterThanOrEqual(6, (int) $d['best_from']->format('H'), 'ยามกลางวันเริ่มไม่ก่อน 06:00');
            $this->assertLessThanOrEqual(18 * 60, (int) $d['best_to']->format('H') * 60 + (int) $d['best_to']->format('i'), 'ยามกลางวันจบไม่เกิน 18:00');

            // ยามที่แนะนำต้องเป็นช่องเดียวกับที่ลงเลขไว้จริง
            $rows = ThaiAstro::yamRows($d['yam']['day_seq'], false);
            $this->assertSame($rows[$d['yam']['picked'] - 1]['from'], $d['best_from']->format('H:i'));
            $this->assertSame($rows[$d['yam']['picked'] - 1]['name'], $d['yam']['name']);
        }
    }

    /** ข้อความอิสระของลูกค้าต้องเข้าหมวดได้เอง โดยคำที่ยาวกว่าชนะ */
    public function test_occasion_detection_prefers_the_longest_keyword(): void
    {
        $this->assertSame('business', AuspiciousOccasions::detect('เปิดร้านกาแฟ'));
        $this->assertSame('travel', AuspiciousOccasions::detect('ย้ายร้านไปตึกใหม่'));
        $this->assertSame('wedding', AuspiciousOccasions::detect('แต่งงานลูกสาว'));
        $this->assertSame('vehicle', AuspiciousOccasions::detect('ออกรถกระบะ'));
        $this->assertSame('general', AuspiciousOccasions::detect('อะไรก็ได้'));
        $this->assertSame('general', AuspiciousOccasions::detect(''));
    }
}
