<?php

namespace App\Services;

use App\Support\AuspiciousOccasions;
use App\Support\ThaiAstro;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ให้คะแนนวันตามหลักฤกษ์ไทย — ฤกษ์บน (นักษัตร/ฤกษ์ 9) × วาร × ดิถี × ประเภทงาน
 *
 * 🔴 (2026-07-26 REWRITE) ของเดิมให้ฐาน 5 แล้ว +2 ถ้าเป็น อ./พฤ./ส. +2 ถ้าวันที่
 * 9/19/29 +2 ถ้าผลรวมเลขวันหาร 9 ลงตัว ผ่านที่ 7 — ผลคือ **ทุกวันอังคาร/พฤหัส/เสาร์
 * ผ่านหมด** (60 วันได้ ~26 วัน) ผลลัพธ์ซ้ำกันทุกคำถาม ช่อง "โอกาส" ไม่ถูกใช้เลย
 * และไม่มีอะไรเป็นหลักฤกษ์ไทยจริง ๆ สักอย่าง — ลูกค้าจ่าย ฿19 เพื่อดูปฏิทินวันอังคาร
 *
 * ตอนนี้คำนวณตำแหน่งดวงจันทร์จริง (ดู App\Support\ThaiAstro) แล้วอ่านฤกษ์จากนักษัตร
 * ที่ดวงจันทร์สถิต ซึ่งคือนิยามของคำว่า "ฤกษ์" — ผลจึงต่างกันทุกวันและต่างกันตามงาน
 *
 * ทุกคะแนนมี reasons[] กำกับเสมอ เพราะบริการนี้ขายความน่าเชื่อถือ ถ้าโชว์ตัวเลข
 * ลอย ๆ โดยบอกที่มาไม่ได้ ก็ไม่ต่างจากสุ่ม
 */
class AuspiciousScorer
{
    /** เพดานจำนวนวันที่ยอมสแกนต่อคำขอ — กัน CPU ระเบิดถ้ามีคนขอช่วง 10 ปี */
    public const MAX_SCAN_DAYS = 180;

    /** คะแนน (0-100) ขั้นต่ำที่ถือว่า "ใช้ได้" */
    public const PASS_MARK = 55;

    /**
     * ให้คะแนนทุกวันในช่วง — ใช้ทำแถบปฏิทินและเป็นวัตถุดิบของ candidateDays()
     *
     * @return array<int, array<string,mixed>>
     */
    public function scoreRange(CarbonInterface $from, CarbonInterface $to, string $occasionKey = AuspiciousOccasions::DEFAULT): array
    {
        $cursor = Carbon::parse($from->format('Y-m-d'), ThaiAstro::TZ)->startOfDay();
        $last   = Carbon::parse($to->format('Y-m-d'), ThaiAstro::TZ)->startOfDay();

        $days = [];
        $guard = 0;
        while ($cursor <= $last && $guard < self::MAX_SCAN_DAYS) {
            $days[] = $this->scoreDay($cursor, $occasionKey);
            $cursor = $cursor->copy()->addDay();
            $guard++;
        }

        return $days;
    }

    /**
     * วันที่ผ่านเกณฑ์ เรียงจากดีที่สุด — ตัวนี้คือชุดที่เอาไปคิดเงิน/ส่งให้ AI
     *
     * ถ้าไม่มีวันไหนถึงเกณฑ์เลย คืนอาเรย์ว่าง เพื่อให้ controller ยกเลิกก่อนหักเงิน
     * (อย่าคืน "วันที่ดีน้อยที่สุด" มาให้แทน — ลูกค้าจ่ายเงินหาฤกษ์ ไม่ได้จ่ายเพื่อ
     *  ให้เรายัดวันที่ตำราบอกว่าห้ามใช้)
     *
     * @return array<int, array<string,mixed>>
     */
    public function candidateDays(CarbonInterface $from, CarbonInterface $to, string $occasionKey = AuspiciousOccasions::DEFAULT, int $limit = 8): array
    {
        $days = array_values(array_filter(
            $this->scoreRange($from, $to, $occasionKey),
            fn ($d) => $d['score_pct'] >= self::PASS_MARK,
        ));

        usort($days, function ($a, $b) {
            return [$b['score_pct'], $a['date']->timestamp] <=> [$a['score_pct'], $b['date']->timestamp];
        });

        return array_slice($days, 0, $limit);
    }

    /**
     * คะแนนของวันเดียว พร้อมเหตุผลที่อ่านรู้เรื่อง
     *
     * @return array<string,mixed>
     */
    public function scoreDay(CarbonInterface $day, string $occasionKey = AuspiciousOccasions::DEFAULT): array
    {
        $date     = Carbon::parse($day->format('Y-m-d'), ThaiAstro::TZ)->startOfDay();
        $occasion = AuspiciousOccasions::get($occasionKey);

        $window  = ThaiAstro::primaryRuek($date);
        $ruek    = ThaiAstro::RUEK[$window['ruek']];
        $tithi   = ThaiAstro::tithi($date);
        $dow     = $date->dayOfWeek;
        $weekday = ThaiAstro::WEEKDAY[$dow];

        $reasons  = [];
        $warnings = [];

        // ── ฤกษ์บน: ตัวชี้ขาดหลัก (น้ำหนัก -9..+8 → ±36 คะแนน) ──────────────
        $ruekWeight = $occasion['ruek'][$ruek['key']] ?? 0;
        $score = 50 + $ruekWeight * 4;

        if ($ruekWeight >= 5) {
            $reasons[] = "ดวงจันทร์สถิตนักษัตร{$window['nakshatra_name']} ตกที่{$ruek['name']} — {$ruek['summary']}";
        } elseif ($ruekWeight > 0) {
            $reasons[] = "ตรงกับ{$ruek['name']} ซึ่งพอใช้ได้กับงานหมวดนี้";
        } elseif ($ruekWeight <= -6) {
            $warnings[] = "ตรงกับ{$ruek['name']} ซึ่งตำราห้ามใช้กับ{$occasion['label']}";
        } elseif ($ruekWeight < 0) {
            $warnings[] = "{$ruek['name']} ไม่หนุนงานหมวดนี้เท่าที่ควร";
        }

        // ── วาร (วันในสัปดาห์) ───────────────────────────────────────────
        $dowWeight = $occasion['weekday'][$dow] ?? 0;
        $score += $dowWeight * 3;
        if ($dowWeight >= 2) {
            $reasons[] = "วัน{$weekday['name']} — {$weekday['note']}";
        } elseif ($dowWeight <= -2) {
            $warnings[] = "วัน{$weekday['name']} — {$weekday['note']}";
        }

        // ── ดิถี: ข้างขึ้นหนุนงานเริ่มต้น ข้างแรมหนุนงานตัดออก ────────────
        $wantWaxing = (bool) $occasion['waxing'];
        $isWaxing   = $tithi['side'] === 'waxing';
        if ($wantWaxing === $isWaxing) {
            $score += 6;
            $reasons[] = $isWaxing
                ? "{$tithi['label']} — ข้างขึ้นหนุนสิ่งที่ต้องการให้เติบโตขึ้นเรื่อย ๆ"
                : "{$tithi['label']} — ข้างแรมหนุนการตัดและลดสิ่งที่ไม่ต้องการ";
        } else {
            $score -= 6;
            $warnings[] = $isWaxing
                ? "{$tithi['label']} — ข้างขึ้นสวนทางกับงานที่ต้องการตัดออก"
                : "{$tithi['label']} — ข้างแรมทำให้สิ่งที่เริ่มไว้โตช้า";
        }

        // ── เพ็ญ/ดับ + วันพระ ────────────────────────────────────────────
        if ($tithi['tithi'] === 15) {
            $score += 4;
            $reasons[] = 'ตรงวันเพ็ญ (ขึ้น 15 ค่ำ) พระจันทร์เต็มดวง เป็นวันที่พลังเต็มที่ตามคติไทย';
        } elseif ($tithi['tithi'] === 30) {
            $score -= 8;
            $warnings[] = 'ตรงวันดับ (แรม 15 ค่ำ) ตำราเลี่ยงการเริ่มงานมงคล';
        }
        if ($tithi['is_holy']) {
            if ($occasionKey === 'merit') {
                $score += 8;
                $reasons[] = 'เป็นวันพระ เหมาะกับงานบุญเป็นพิเศษ';
            } else {
                $warnings[] = 'เป็นวันพระ — วัดและสถานที่ราชการมักคนแน่น ควรเผื่อเวลา';
            }
        }

        $scorePct = max(0, min(100, (int) round($score)));

        // ── ช่วงเวลาแนะนำ: ตัดช่วงที่ฤกษ์ครอง ∩ เวลาทำพิธีปกติ ────────────
        $bestFrom = $window['from']->copy()->max($date->copy()->setTime(6, 9));
        $bestTo   = $window['to']->copy()->min($date->copy()->setTime(18, 0));
        $hasSlot  = $bestTo->greaterThan($bestFrom);

        return [
            'date'       => $date,
            'occasion'   => $occasionKey,
            'score_pct'  => $scorePct,
            // คงคีย์ + สเกล 0-10 เดิมไว้ให้แอพมือถือที่ deploy ไปแล้วอ่านต่อได้
            'score'      => (int) round($scorePct / 10),
            'grade'      => $this->grade($scorePct),
            'label'      => 'วัน'.$weekday['name'].' ที่ '.$date->format('d/m/Y'),
            'weekday'    => $weekday + ['index' => $dow],
            'nakshatra'  => $window['nakshatra_name'],
            'ruek'       => $ruek + ['index' => $window['ruek']],
            'ruek_from'  => $window['from'],
            'ruek_to'    => $window['to'],
            'best_from'  => $hasSlot ? $bestFrom : null,
            'best_to'    => $hasSlot ? $bestTo : null,
            'tithi'      => $tithi,
            'reasons'    => $reasons,
            'warnings'   => $warnings,
        ];
    }

    private function grade(int $pct): string
    {
        return match (true) {
            $pct >= 80 => 'excellent',
            $pct >= 68 => 'good',
            $pct >= self::PASS_MARK => 'fair',
            default    => 'avoid',
        };
    }

    /** ป้ายไทยของเกรด — ใช้ทั้งหน้าเว็บและ API */
    public static function gradeLabel(string $grade): string
    {
        return match ($grade) {
            'excellent' => 'ฤกษ์ดีเยี่ยม',
            'good'      => 'ฤกษ์ดี',
            'fair'      => 'พอใช้ได้',
            default     => 'ควรเลี่ยง',
        };
    }
}
