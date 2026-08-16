<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Zodiac;
use App\Support\ThaiAstro;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * ปฏิทินโหรของวันนี้ — ดิถี · ราศีที่จันทร์/อาทิตย์เสวย · ยามปัจจุบัน
 *
 * มีไว้เพราะหน้าแรกของแอพเคยแสดงบรรทัด "พระจันทร์ขึ้นกุมราศีกรกฎ — ลูกจะ
 * รู้สึกอ่อนไหว…" เป็น **ข้อความคงที่ที่พิมพ์ไว้ในโค้ด** ทุกคนเห็นเหมือนกัน
 * ทุกวัน ทั้งที่จันทร์ย้ายราศีทุก ~2.3 วัน — เท่ากับผิดประมาณ 11 วันใน 12 วัน
 * ซึ่งขัดกฎเหล็กข้อ 1 ของโปรเจกต์ (ค่าดาราศาสตร์ต้องคำนวณ ห้ามแต่ง)
 *
 * ทุกตัวเลขที่นี่มาจาก {@see ThaiAstro} ตัวเดียวกับที่หน้าฤกษ์และดวงรายวัน
 * ของเว็บใช้ แอพกับเว็บจึงพูดตรงกันเสมอ ไม่มีการคำนวณซ้ำฝั่ง client
 *
 * เปิดสาธารณะ (ไม่ต้องล็อกอิน) เพราะหน้าแรกแสดงให้ผู้เยี่ยมชมเห็นด้วย
 */
class AlmanacController extends Controller
{
    /** GET /v1/almanac/today */
    public function today(): JsonResponse
    {
        $now   = Carbon::now(ThaiAstro::TZ);
        $noon  = Carbon::parse($now->format('Y-m-d') . ' 12:00:00', ThaiAstro::TZ);

        $tithi = ThaiAstro::tithi($now);

        // ราศี = นิรายนะ (sidereal) ตามหลักโหรไทย ไม่ใช่สายนะแบบตะวันตก
        $moonLon = ThaiAstro::moonSidereal($noon);
        $sunLon  = fmod(
            fmod(ThaiAstro::sunTropical($noon) - ThaiAstro::ayanamsa($noon), 360.0) + 360.0,
            360.0,
        );

        $signs = Zodiac::orderBy('order_index')->get();

        $yam = ThaiAstro::yamAt($now);

        return response()
            ->json([
                'data' => [
                    'date'       => $now->toDateString(),
                    'weekday_th' => self::WEEKDAYS_TH[(int) $now->dayOfWeek],
                    'tithi'      => [
                        'number'       => $tithi['tithi'],
                        'side'         => $tithi['side'],
                        'day'          => $tithi['day'],
                        'label'        => $tithi['label'],
                        'is_holy'      => $tithi['is_holy'],
                        'illumination' => $tithi['illumination'],
                    ],
                    'moon'     => $this->signAt($moonLon, $signs),
                    'sun'      => $this->signAt($sunLon, $signs),
                    'yam'      => [
                        'no'          => $yam['no'] ?? null,
                        'thai_no'     => $yam['thai_no'] ?? null,
                        'name'        => $yam['name'] ?? null,
                        'planet_name' => $yam['planet_name'] ?? null,
                        'side'        => $yam['side'] ?? null,
                        'from'        => $yam['from'] ?? null,
                        'to'          => $yam['to'] ?? null,
                    ],
                    'headline'    => $this->headline($moonLon, $signs, $tithi),
                    'note'        => $this->note($tithi),
                    'daily_card'  => $this->dailyCard($now),
                ],
            ])
            // ดิถีเปลี่ยนช้า แต่ยามเปลี่ยนทุก ~1.5 ชม. — cache สั้น ๆ พอ
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * ไพ่ประจำวัน — ใบเดียวกันทั้งวัน เท่ากันทุกคน เปลี่ยนเมื่อขึ้นวันใหม่
     *
     * ตรงนี้ใช้ crc32 ของวันที่ได้ เพราะ CLAUDE.md ข้อ 1 ระบุไว้ชัดว่าไพ่ประจำวัน
     * เป็น "ของประดับ" ที่ไม่มีใครอ้างว่าเป็นผลคำนวณทางดาราศาสตร์ (ต่างจากดิถี
     * หรือราศีที่จันทร์เสวย ซึ่งห้ามสุ่มเด็ดขาด) สิ่งที่ต้องรับประกันคือ
     * **คงที่ภายในวัน** ไม่ใช่สุ่มใหม่ทุกครั้งที่เรียก
     *
     * เลือกจากตาราง tarot_cards จริง แอพจึงได้ภาพหน้าไพ่ของ จันทรา.online
     * มาแสดงด้วย ไม่ต้องมีสำรับของตัวเองให้หลุดจากเว็บ
     *
     * @return array<string,mixed>|null
     */
    private function dailyCard(Carbon $day): ?array
    {
        $cards = \App\Models\TarotCard::query()
            ->where('active', true)
            ->orderBy('id')
            ->get();

        if ($cards->isEmpty()) {
            return null;
        }

        $card = $cards[crc32('juntra-daily-card:' . $day->toDateString()) % $cards->count()];

        return [
            'slug'      => $card->slug,
            'name_th'   => $card->name_th,
            'name_en'   => $card->name_en,
            'image_url' => $card->faceImageUrl(),
        ];
    }

    private const WEEKDAYS_TH = [
        'อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์',
    ];

    /**
     * แปลงลองจิจูดนิรายนะเป็นราศี — 30° ต่อราศี เริ่มที่เมษ (order_index = 1)
     *
     * @param  \Illuminate\Support\Collection<int,Zodiac>  $signs
     * @return array{slug:string|null,name_th:string|null,glyph:string|null,degree:float}
     */
    private function signAt(float $lon, $signs): array
    {
        $idx  = (int) floor($lon / 30.0) % 12;
        $sign = $signs->firstWhere('order_index', $idx + 1);

        return [
            'slug'    => $sign?->slug,
            'name_th' => $sign?->name_th,
            'glyph'   => $sign?->glyph,
            'degree'  => round($lon - $idx * 30, 2),
        ];
    }

    /** @param \Illuminate\Support\Collection<int,Zodiac> $signs */
    private function headline(float $moonLon, $signs, array $tithi): string
    {
        $moon = $this->signAt($moonLon, $signs);
        $name = $moon['name_th'] ?? '—';

        return "จันทร์เสวยราศี{$name} · {$tithi['label']}";
    }

    /**
     * คำอธิบายสั้น ๆ ของข้างขึ้น/ข้างแรม
     *
     * ใช้ถ้อยคำชุดเดียวกับ {@see \App\Services\AuspiciousScorer} ที่ตรึงไว้
     * แล้ว — ไม่แต่งความหมายใหม่ขึ้นมาเอง
     */
    private function note(array $tithi): string
    {
        if ($tithi['tithi'] === 15) {
            return 'จันทร์เพ็ญเต็มดวง — กำลังของดวงจันทร์สูงสุดในรอบเดือน';
        }
        if ($tithi['tithi'] === 30) {
            return 'จันทร์ดับ — เหมาะกับการเริ่มต้นเงียบ ๆ และวางแผนภายใน';
        }

        return $tithi['side'] === 'waxing'
            ? 'ข้างขึ้นหนุนสิ่งที่ต้องการให้เติบโตขึ้นเรื่อย ๆ'
            : 'ข้างแรมหนุนการตัดและลดสิ่งที่ไม่ต้องการ';
    }
}
