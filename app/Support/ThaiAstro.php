<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ดาราศาสตร์เท่าที่ "ฤกษ์ยาม" ไทยต้องใช้ — คำนวณตำแหน่งจริงของดวงจันทร์/ดวงอาทิตย์
 * แล้วแปลงเป็น นักษัตร 27 · ฤกษ์บน 9 · ดิถี (ข้างขึ้น-ข้างแรม) · เฟสดวงจันทร์
 *
 * ทำไมต้องคำนวณจริง (2026-07-26): ของเดิมให้คะแนนวันจาก "เป็นวันอังคาร/พฤหัส/เสาร์
 * ไหม + วันที่ 9/19/29 ไหม" ซึ่งทำให้ทุกวัน อ./พฤ./ส. ผ่านหมด ผลลัพธ์ซ้ำกันทุกคำถาม
 * และไม่ใช่หลักฤกษ์ไทยเลย — "ฤกษ์" แปลตรงตัวว่านักษัตร คือตำแหน่งดวงจันทร์บนจักรราศี
 * ถ้าไม่รู้ตำแหน่งดวงจันทร์ ก็ไม่ได้ดูฤกษ์
 *
 * ขอบเขตที่เลือกไว้โดยตั้งใจ: ระบบนี้ทำ "ฤกษ์บน" (นักษัตร/ดิถี/วาร) ซึ่งคำนวณจาก
 * ดาราศาสตร์ได้ตรงและตรวจสอบได้ — ไม่ทำ วันธงชัย/อธิบดี/อุบาทว์/โลกาวินาศ เพราะชุดนั้น
 * ผูกกับ "เดือนจันทรคติไทย" ตามปฏิทินหลวง (มีอธิกมาส/อธิกวารที่ประกาศเป็นปี ๆ ไป)
 * ถ้าเดาเดือนผิดไปเดือนเดียว ตารางทั้งชุดจะผิดหมด — ยอมทำน้อยแต่ถูก ดีกว่าทำครบแต่มั่ว
 *
 * ความแม่นยำ: สูตร Meeus (Astronomical Algorithms) เอาพจน์หลัก — ดวงจันทร์คลาดเคลื่อน
 * ~0.02° ดวงอาทิตย์ ~0.01° เทียบกับช่องนักษัตรที่กว้าง 13°20' ถือว่าเหลือเฟือ
 * (คลาดเคลื่อนราว 3 นาทีของเวลาเข้าฤกษ์เท่านั้น)
 */
final class ThaiAstro
{
    /** ช่องละ 13°20' — 360/27 */
    public const NAKSHATRA_ARC = 360.0 / 27.0;

    public const TZ = 'Asia/Bangkok';

    /** นักษัตร 27 ตามลำดับจากต้นราศีเมษ (สิริ) */
    public const NAKSHATRAS = [
        'อัศวินี', 'ภรณี', 'กฤติกา', 'โรหิณี', 'มฤคศิระ', 'อารทรา', 'ปุนัพสุ',
        'ปุษยะ', 'อาศเลษา', 'มฆา', 'บุรพผลคุนี', 'อุตรผลคุนี', 'หัสตะ', 'จิตรา',
        'สวาติ', 'วิศาขา', 'อนุราธา', 'เชษฐา', 'มูละ', 'บุรพาษาฒ', 'อุตราษาฒ',
        'ศรวณะ', 'ธนิษฐา', 'ศตภิษัช', 'บุรพภัทรบท', 'อุตรภัทรบท', 'เรวดี',
    ];

    /**
     * ฤกษ์บน 9 — นักษัตรลำดับที่ i ตกฤกษ์ที่ (i mod 9)
     * อัศวินี(0)=ทลิทโท · มฆา(9)=ทลิทโท · มูละ(18)=ทลิทโท ← วนรอบละ 9 ตามตำรา
     *
     * โทน: good = งานที่ฤกษ์นี้หนุน · bad = งานที่ตำราห้าม
     */
    public const RUEK = [
        [
            'key' => 'thalittho', 'name' => 'ทลิทโทฤกษ์', 'deity' => 'ฤกษ์ผู้มักน้อย',
            'tone' => 'neutral', 'color' => '#8d93a8',
            'summary' => 'ฤกษ์ของผู้ขอ ผู้อาศัยบารมีคนอื่น เหมาะกับการเริ่มจากศูนย์และงานสงเคราะห์',
            'good' => 'ขอความช่วยเหลือ ขอทุน ขอกู้ สมัครงาน งานการกุศล เริ่มต้นสิ่งเล็ก ๆ',
            'bad'  => 'มงคลสมรส เปิดกิจการใหญ่ ขึ้นบ้านใหม่',
        ],
        [
            'key' => 'mahattano', 'name' => 'มหัทธโนฤกษ์', 'deity' => 'ฤกษ์เศรษฐี',
            'tone' => 'excellent', 'color' => '#e0b642',
            'summary' => 'ฤกษ์แห่งทรัพย์ หนุนการสะสมและการค้าให้งอกเงย เป็นฤกษ์มงคลชั้นดี',
            'good' => 'เปิดร้าน ค้าขาย ลงทุน ขึ้นบ้านใหม่ มงคลสมรส เปิดบัญชี ทำสัญญาการเงิน',
            'bad'  => 'งานอวมงคล',
        ],
        [
            'key' => 'choro', 'name' => 'โจโรฤกษ์', 'deity' => 'ฤกษ์โจร',
            'tone' => 'bad', 'color' => '#b04a4a',
            'summary' => 'ฤกษ์ของนักเลง ใช้กับงานที่ต้องช่วงชิงหรือเอาชนะเท่านั้น',
            'good' => 'งานแข่งขัน ปราบปราม ฟ้องร้องคดี ทวงหนี้ งานที่ต้องรุก',
            'bad'  => 'งานมงคลทุกชนิด แต่งงาน ขึ้นบ้านใหม่ เปิดกิจการ',
        ],
        [
            'key' => 'phumipalo', 'name' => 'ภูมิปาโลฤกษ์', 'deity' => 'ฤกษ์ผู้รักษาแผ่นดิน',
            'tone' => 'excellent', 'color' => '#5f9e6e',
            'summary' => 'ฤกษ์แห่งความมั่นคงหนักแน่น ตำราถือเป็นฤกษ์ดีที่สุดสำหรับสิ่งปลูกสร้าง',
            'good' => 'ขึ้นบ้านใหม่ ลงเสาเอก ปลูกสร้าง ซื้อที่ดิน มงคลสมรส เปิดกิจการ',
            'bad'  => 'งานที่ต้องการความรวดเร็วผันผวน',
        ],
        [
            'key' => 'thesatri', 'name' => 'เทศาตรีฤกษ์', 'deity' => 'ฤกษ์พ่อค้าวาณิช',
            'tone' => 'good', 'color' => '#4a8bb0',
            'summary' => 'ฤกษ์ของคนเดินทางและการค้าข้ามถิ่น เด่นเรื่องการเคลื่อนย้าย',
            'good' => 'ออกรถ เดินทางไกล ย้ายบ้าน ย้ายร้าน ค้าขายทางไกล ติดต่อต่างประเทศ',
            'bad'  => 'งานที่ต้องการความอยู่กับที่ถาวร',
        ],
        [
            'key' => 'thewi', 'name' => 'เทวีฤกษ์', 'deity' => 'ฤกษ์นางพญา',
            'tone' => 'excellent', 'color' => '#c77fb0',
            'summary' => 'ฤกษ์แห่งเสน่ห์และความงาม หนุนเรื่องคู่และการเจรจาให้ราบรื่น',
            'good' => 'มงคลสมรส หมั้น สู่ขอ งานเปิดตัว งานความงาม เจรจาต่อรอง',
            'bad'  => 'งานที่ต้องใช้ความแข็งกร้าว',
        ],
        [
            'key' => 'phetchakhat', 'name' => 'เพชฌฆาตฤกษ์', 'deity' => 'ฤกษ์ประหาร',
            'tone' => 'bad', 'color' => '#8c2f2f',
            'summary' => 'ฤกษ์แรงที่สุดในทางทำลาย ตำราห้ามใช้กับงานมงคลทั้งปวง',
            'good' => 'งานปราบศัตรู ผ่าตัด รื้อถอน ตัดสิ่งไม่ดีออกจากชีวิต',
            'bad'  => 'งานมงคลทุกชนิด โดยเฉพาะแต่งงานและขึ้นบ้านใหม่',
        ],
        [
            'key' => 'racha', 'name' => 'ราชาฤกษ์', 'deity' => 'ฤกษ์กษัตริย์',
            'tone' => 'excellent', 'color' => '#d4a017',
            'summary' => 'ฤกษ์ของผู้เป็นใหญ่ หนุนงานพิธีการและการก้าวขึ้นสู่ตำแหน่ง',
            'good' => 'เปิดกิจการ รับตำแหน่ง เข้าพบผู้ใหญ่ งานพิธีใหญ่ ขึ้นบ้านใหม่ แต่งงาน',
            'bad'  => 'งานที่ต้องการความเงียบต่ำต้อย',
        ],
        [
            'key' => 'samano', 'name' => 'สมโณฤกษ์', 'deity' => 'ฤกษ์นักบวช',
            'tone' => 'good', 'color' => '#a08a5b',
            'summary' => 'ฤกษ์แห่งความสงบ หนุนงานทางธรรมและการวางมือจากทางโลก',
            'good' => 'บวช ทำบุญ ถวายสังฆทาน ปฏิบัติธรรม ทำพิธีสงฆ์ สะเดาะเคราะห์',
            'bad'  => 'ค้าขาย เปิดกิจการ งานที่หวังผลกำไร',
        ],
    ];

    /** ดาวประจำวาร (วันในสัปดาห์) — 0 = อาทิตย์ */
    public const WEEKDAY = [
        ['name' => 'อาทิตย์', 'planet' => 'อาทิตย์', 'glyph' => '☉', 'color' => '#d94f4f', 'note' => 'วารอำนาจ เหมาะงานที่ต้องประกาศตัว'],
        ['name' => 'จันทร์',  'planet' => 'จันทร์',  'glyph' => '☾', 'color' => '#e8e4d9', 'note' => 'วารอ่อนโยน เหมาะงานเจรจาและเรื่องคู่'],
        ['name' => 'อังคาร',  'planet' => 'อังคาร',  'glyph' => '♂', 'color' => '#c96a4a', 'note' => 'วารแข็ง คนไทยมักเลี่ยงงานมงคล'],
        ['name' => 'พุธ',     'planet' => 'พุธ',     'glyph' => '☿', 'color' => '#5f9e6e', 'note' => 'วารเจรจาค้าขาย เหมาะติดต่อสื่อสาร'],
        ['name' => 'พฤหัสบดี', 'planet' => 'พฤหัสบดี', 'glyph' => '♃', 'color' => '#e0b642', 'note' => 'วารครู เป็นวารมงคลที่นิยมที่สุด'],
        ['name' => 'ศุกร์',   'planet' => 'ศุกร์',   'glyph' => '♀', 'color' => '#c77fb0', 'note' => 'วารเสน่ห์ เหมาะงานแต่งและงานรื่นเริง'],
        ['name' => 'เสาร์',   'planet' => 'เสาร์',   'glyph' => '♄', 'color' => '#7a7f8c', 'note' => 'วารหนัก เหมาะงานตัดและงานที่ต้องความอึด'],
    ];

    /* ============================================================
       ฤกษ์ประจำวัน
       ============================================================ */

    /**
     * ช่วงฤกษ์ทั้งหมดที่ครองวันนั้น (ดวงจันทร์เดินราว 13.2°/วัน = 1-2 นักษัตรต่อวัน)
     *
     * คืนช่วงเวลาจริงที่แต่ละฤกษ์ครอง เพื่อให้หน้าเว็บบอกได้ว่า "ทำพิธีช่วงไหน"
     * ไม่ใช่บอกแค่ว่าวันนี้ดี ซึ่งเป็นสิ่งที่ลูกค้าจ่ายเงินมาเพื่ออยากรู้
     *
     * @return array<int, array{nakshatra:int,nakshatra_name:string,ruek:int,from:Carbon,to:Carbon,minutes:int}>
     */
    public static function ruekWindows(CarbonInterface $day): array
    {
        $start = Carbon::parse($day->format('Y-m-d').' 00:00:00', self::TZ);
        $end   = $start->copy()->addDay();

        $l0 = self::moonSidereal($start);
        $l1 = self::moonSidereal($end);
        if ($l1 < $l0) {
            $l1 += 360.0; // ข้ามศูนย์องศาราศีเมษ
        }

        $n0 = (int) floor($l0 / self::NAKSHATRA_ARC);
        $n1 = (int) floor($l1 / self::NAKSHATRA_ARC);

        $edges = [$start];
        for ($n = $n0 + 1; $n <= $n1; $n++) {
            $edges[] = self::crossingTime($start, $end, $n * self::NAKSHATRA_ARC, $l0);
        }
        $edges[] = $end;

        $windows = [];
        for ($i = 0; $i < count($edges) - 1; $i++) {
            $idx = ($n0 + $i) % 27;
            if ($idx < 0) {
                $idx += 27;
            }
            $from = $edges[$i];
            $to   = $edges[$i + 1];
            $windows[] = [
                'nakshatra'      => $idx,
                'nakshatra_name' => self::NAKSHATRAS[$idx],
                'ruek'           => $idx % 9,
                'from'           => $from,
                'to'             => $to,
                'minutes'        => (int) round($from->diffInMinutes($to)),
            ];
        }

        return $windows;
    }

    /**
     * ฤกษ์ "หลัก" ของวัน = ช่วงที่กินเวลามากที่สุดในกรอบ 06:00–18:00
     *
     * ทำไมนับเฉพาะกลางวัน: พิธีมงคลไทยแทบทั้งหมดทำกลางวัน ถ้านับ 24 ชม.
     * ฤกษ์ที่ครองแค่ตอนตีสองอาจชนะแล้วไปโชว์ฤกษ์ที่ลูกค้าใช้จริงไม่ได้
     *
     * @return array{nakshatra:int,nakshatra_name:string,ruek:int,from:Carbon,to:Carbon,minutes:int}
     */
    public static function primaryRuek(CarbonInterface $day): array
    {
        $windows  = self::ruekWindows($day);
        $dayStart = Carbon::parse($day->format('Y-m-d').' 06:00:00', self::TZ);
        $dayEnd   = Carbon::parse($day->format('Y-m-d').' 18:00:00', self::TZ);

        $best = null;
        $bestOverlap = -1;
        foreach ($windows as $w) {
            $from = $w['from']->greaterThan($dayStart) ? $w['from'] : $dayStart;
            $to   = $w['to']->lessThan($dayEnd) ? $w['to'] : $dayEnd;
            $overlap = $to->greaterThan($from) ? $from->diffInMinutes($to) : 0;
            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $best = $w;
            }
        }

        return $best ?? $windows[0];
    }

    /* ============================================================
       ดิถี / เฟสดวงจันทร์
       ============================================================ */

    /**
     * ดิถีไทย ณ เที่ยงวัน — ข้างขึ้น/ข้างแรม กี่ค่ำ + เป็นวันพระหรือไม่
     *
     * @return array{tithi:int,side:'waxing'|'waning',day:int,label:string,is_holy:bool,illumination:float}
     */
    public static function tithi(CarbonInterface $day): array
    {
        $noon = Carbon::parse($day->format('Y-m-d').' 12:00:00', self::TZ);

        $elong = self::norm360(self::moonTropical($noon) - self::sunTropical($noon));
        $tithi = (int) floor($elong / 12.0) + 1;      // 1..30

        $waxing = $tithi <= 15;
        $dayNo  = $waxing ? $tithi : $tithi - 15;

        return [
            'tithi'        => $tithi,
            'side'         => $waxing ? 'waxing' : 'waning',
            'day'          => $dayNo,
            'label'        => ($waxing ? 'ขึ้น' : 'แรม').' '.$dayNo.' ค่ำ',
            // วันพระ: ขึ้น/แรม 8 ค่ำ · ขึ้น 15 ค่ำ (เพ็ญ) · แรม 15 ค่ำ (ดับ)
            'is_holy'      => in_array($dayNo, [8, 15], true),
            'illumination' => round((1 - cos(deg2rad($elong))) / 2, 4),
        ];
    }

    /* ============================================================
       ตำแหน่งดาว (Meeus — พจน์หลัก)
       ============================================================ */

    /** ลองจิจูดดวงจันทร์แบบสายนะ (tropical) องศา 0-360 */
    public static function moonTropical(CarbonInterface $t): float
    {
        $T = self::julianCenturies($t);

        $Lp = 218.3164477 + 481267.88123421 * $T - 0.0015786 * $T * $T;
        $D  = 297.8501921 + 445267.1114034 * $T - 0.0018819 * $T * $T;
        $M  = 357.5291092 + 35999.0502909 * $T - 0.0001536 * $T * $T;
        $Mp = 134.9633964 + 477198.8675055 * $T + 0.0087414 * $T * $T;
        $F  = 93.2720950 + 483202.0175233 * $T - 0.0036539 * $T * $T;

        $d = deg2rad($D);
        $m = deg2rad($M);
        $mp = deg2rad($Mp);
        $f = deg2rad($F);

        $sum = 6.288774 * sin($mp)
            + 1.274027 * sin(2 * $d - $mp)
            + 0.658314 * sin(2 * $d)
            + 0.213618 * sin(2 * $mp)
            - 0.185116 * sin($m)
            - 0.114332 * sin(2 * $f)
            + 0.058793 * sin(2 * $d - 2 * $mp)
            + 0.057066 * sin(2 * $d - $m - $mp)
            + 0.053322 * sin(2 * $d + $mp)
            + 0.045758 * sin(2 * $d - $m)
            - 0.040923 * sin($m - $mp)
            - 0.034720 * sin($d)
            - 0.030383 * sin($m + $mp)
            + 0.015327 * sin(2 * $d - 2 * $f)
            - 0.012528 * sin($mp + 2 * $f)
            + 0.010980 * sin($mp - 2 * $f)
            + 0.010675 * sin(4 * $d - $mp)
            + 0.010034 * sin(3 * $mp)
            + 0.008548 * sin(4 * $d - 2 * $mp);

        return self::norm360($Lp + $sum);
    }

    /** ลองจิจูดดวงอาทิตย์แบบสายนะ (tropical) องศา 0-360 */
    public static function sunTropical(CarbonInterface $t): float
    {
        $T = self::julianCenturies($t);

        $L0 = 280.46646 + 36000.76983 * $T + 0.0003032 * $T * $T;
        $M  = 357.52911 + 35999.05029 * $T - 0.0001537 * $T * $T;
        $m  = deg2rad($M);

        $C = (1.914602 - 0.004817 * $T - 0.000014 * $T * $T) * sin($m)
            + (0.019993 - 0.000101 * $T) * sin(2 * $m)
            + 0.000289 * sin(3 * $m);

        return self::norm360($L0 + $C);
    }

    /**
     * ลองจิจูดดวงจันทร์แบบนิรายนะ (sidereal) = สายนะ − อายนางศ
     * ใช้ Lahiri ซึ่งต่างจากอายนางศไทยราว 0.1-0.8° — เล็กกว่าช่องนักษัตร 13°20' มาก
     */
    public static function moonSidereal(CarbonInterface $t): float
    {
        return self::norm360(self::moonTropical($t) - self::ayanamsa($t));
    }

    public static function ayanamsa(CarbonInterface $t): float
    {
        $years = ($t->getTimestamp() - 946684800) / 31556952.0; // วินาทีจาก 2000-01-01 UTC
        return 23.8523 + 0.013969 * $years;
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    /** หาเวลาที่ลองจิจูดดวงจันทร์ (แบบคลี่ไม่ให้วน) ตัดค่าเป้าหมาย — bisection */
    private static function crossingTime(Carbon $from, Carbon $to, float $target, float $base): Carbon
    {
        $lo = $from->copy();
        $hi = $to->copy();

        for ($i = 0; $i < 40; $i++) {
            $midTs = intdiv($lo->getTimestamp() + $hi->getTimestamp(), 2);
            $mid = Carbon::createFromTimestamp($midTs, self::TZ);
            $lon = self::moonSidereal($mid);
            if ($lon < $base) {
                $lon += 360.0;
            }
            if ($lon < $target) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
            if ($hi->getTimestamp() - $lo->getTimestamp() <= 30) {
                break;
            }
        }

        return $hi;
    }

    private static function julianCenturies(CarbonInterface $t): float
    {
        // JD จาก Unix epoch: 2440587.5 = 1970-01-01T00:00Z
        $jd = 2440587.5 + $t->getTimestamp() / 86400.0;

        return ($jd - 2451545.0) / 36525.0;
    }

    private static function norm360(float $deg): float
    {
        $d = fmod($deg, 360.0);

        return $d < 0 ? $d + 360.0 : $d;
    }
}
