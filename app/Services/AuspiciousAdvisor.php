<?php

namespace App\Services;

use App\Models\User;
use App\Services\FortuneBot\FortuneBotClient;
use App\Support\AuspiciousOccasions;
use App\Support\ThaiAstro;

/**
 * แปลงผลคำนวณฤกษ์เป็นคำแนะนำที่อ่านรู้เรื่อง
 *
 * ลำดับ: คีย์พูลของ Thaiprompt (คุณภาพเท่าบอท FB/LINE) → เขียนเองจากผลคำนวณ
 *
 * 🔑 จุดสำคัญ: fallback ต้องเป็น "คำแนะนำจริง" ที่อ้างฤกษ์ ดิถี เวลา และประเภทงาน
 * ของลูกค้าคนนั้น ไม่ใช่ข้อความตายตัว — เพราะนี่คือเส้นทาง DEFAULT เมื่อ upstream
 * ล่มหรือลูกค้ายังไม่ได้เชื่อมบัญชี ถ้า fallback กลวง เท่ากับเก็บ ฿19 ฟรี
 * (ของเดิมคืนประโยคเดียวเหมือนกันทุกคน ทุกโอกาส ทุกช่วงวัน — ดู AiOracle เก่า)
 */
class AuspiciousAdvisor
{
    public function __construct(private FortuneBotClient $bot) {}

    /**
     * @param  array<int, array<string,mixed>>  $days  ผลจาก AuspiciousScorer::candidateDays()
     * @return array{text:string,source:'maemor'|'local',ai_provider:string,ai_model:string}
     */
    public function advise(?User $user, string $occasionKey, string $occasionText, array $days): array
    {
        $remote = $this->bot->auspiciousAdvice($user, [
            'prompt'   => $this->prompt($occasionKey, $occasionText, $days),
            'occasion' => $occasionText,
            'category' => $occasionKey,
        ]);

        if ($remote && ! empty($remote['advice'])) {
            return [
                'text'        => (string) $remote['advice'],
                'source'      => 'maemor',
                'ai_provider' => (string) ($remote['ai_provider'] ?? 'thaiprompt'),
                'ai_model'    => (string) ($remote['ai_model'] ?? 'pool'),
            ];
        }

        return [
            'text'        => $this->localAdvice($occasionKey, $occasionText, $days),
            'source'      => 'local',
            'ai_provider' => 'chantra-ruek',
            'ai_model'    => 'thai-astro-v2',
        ];
    }

    /* ============================================================ */

    /** พรอมป์ที่ส่งให้แม่หมอ — ยัดผลคำนวณให้ครบ เพื่อไม่ให้ AI มโนฤกษ์ขึ้นมาเอง */
    private function prompt(string $occasionKey, string $occasionText, array $days): string
    {
        $occasion = AuspiciousOccasions::get($occasionKey);

        $lines = [
            'ลูกค้าขอฤกษ์สำหรับ: '.$occasionText,
            'หมวดงาน: '.$occasion['label'],
            '',
            'ระบบโหราศาสตร์ได้คำนวณตำแหน่งดวงจันทร์จริงและคัดวันมาให้แล้วดังนี้',
            '(ห้ามเปลี่ยนวัน ห้ามเพิ่มวันใหม่ ห้ามแก้ชื่อฤกษ์ — ใช้ข้อมูลชุดนี้เท่านั้น):',
            '',
        ];

        foreach ($days as $i => $d) {
            $lines[] = sprintf(
                '%d. %s | ฤกษ์: %s (นักษัตร%s) | ดิถี: %s | คะแนน %d/100',
                $i + 1,
                $d['label'],
                $d['ruek']['name'],
                $d['nakshatra'],
                $d['tithi']['label'],
                $d['score_pct'],
            );
            $lines[] = sprintf('   ช่วงที่ฤกษ์บนครอง: %s–%s น.', $d['ruek_from']->format('H:i'), $d['ruek_to']->format('H:i'));
            if (! empty($d['yam'])) {
                $lines[] = sprintf(
                    '   ยามที่ควรตั้งพิธี: ยาม%s (ยามที่ %d ของฟากกลางวัน · ดาวเจ้ายาม %s) %s–%s น.',
                    $d['yam']['name'],
                    $d['yam']['picked'],
                    ThaiAstro::PLANETS[$d['yam']['planet']]['name'],
                    $d['yam']['from'],
                    $d['yam']['to'],
                );
            }
            if ($d['reasons']) {
                $lines[] = '   เหตุผล: '.implode(' / ', $d['reasons']);
            }
            if ($d['warnings']) {
                $lines[] = '   ข้อควรระวัง: '.implode(' / ', $d['warnings']);
            }
        }

        $lines[] = '';
        $lines[] = 'หลักที่ใช้กับงานหมวดนี้: '.$occasion['note'];
        $lines[] = '';
        $lines[] = 'ช่วยเขียนคำแนะนำให้ลูกค้าเป็นภาษาไทย 4-6 ย่อหน้า โดย:';
        $lines[] = '1) ฟันธงว่าควรเลือกวันไหนเป็นอันดับ 1 และเพราะอะไร (อ้างชื่อฤกษ์กับดิถีจริง)';
        $lines[] = '2) เสนอวันสำรองอีก 2 วันพร้อมข้อดีที่ต่างกัน';
        $lines[] = '3) บอกเวลาตั้งพิธีเป็น "ยาม" ตามที่ระบุไว้ด้านบน — ต้องอ้างชื่อยามและช่วงเวลาให้ตรงเป๊ะ ห้ามเปลี่ยนเวลาเอง';
        $lines[] = '4) การเตรียมตัวที่เหมาะกับงานหมวดนี้โดยเฉพาะ';
        $lines[] = 'น้ำเสียงอบอุ่นเป็นกันเอง ไม่ขู่ ไม่ต้องขึ้นต้นด้วยคำทักทาย';

        return implode("\n", $lines);
    }

    /**
     * คำแนะนำที่เขียนจากผลคำนวณล้วน ๆ — ใช้เมื่อพูล AI ใช้ไม่ได้
     * ต้องมีเนื้อจริงพอที่จะคุ้ม ฿19 ด้วยตัวมันเอง
     */
    private function localAdvice(string $occasionKey, string $occasionText, array $days): string
    {
        $occasion = AuspiciousOccasions::get($occasionKey);
        $top = $days[0] ?? null;
        if (! $top) {
            return 'ไม่พบวันที่ผ่านเกณฑ์ฤกษ์ในช่วงที่เลือก';
        }

        $out = [];

        $out[] = "**วันที่แม่หมอแนะนำที่สุดสำหรับ \"{$occasionText}\" คือ {$top['label']}**";
        $out[] = sprintf(
            'วันนั้นดวงจันทร์สถิตนักษัตร%s ซึ่งตกใน%s — %s เมื่อประกอบกับ%s และเป็นวัน%s ทำให้ได้คะแนนฤกษ์ %d เต็ม 100 สำหรับงานหมวด%s',
            $top['nakshatra'],
            $top['ruek']['name'],
            $top['ruek']['summary'],
            $top['tithi']['label'],
            $top['weekday']['name'],
            $top['score_pct'],
            $occasion['label'],
        );

        if (! empty($top['yam'])) {
            $out[] = sprintf(
                '**เวลาตั้งพิธี: ยาม%s %s–%s น.** ลงเลขยามอัฐกาลของวัน%sได้ดาวเจ้าวันเลข %d ตั้งต้น '
                .'เดินระบบ +5 ไปทีละยาม ตกยามที่ %d เป็นยามของ%s ซึ่งเป็นยามที่หนุนงานหมวด%s '
                .'และอยู่ในช่วงที่%sครองจริง (%s–%s น.) — พ้นช่วงนี้ดวงจันทร์เคลื่อนเข้านักษัตรถัดไป ฤกษ์เปลี่ยนทันที',
                $top['yam']['name'],
                $top['yam']['from'],
                $top['yam']['to'],
                $top['weekday']['name'],
                $top['yam']['lord'],
                $top['yam']['picked'],
                ThaiAstro::PLANETS[$top['yam']['planet']]['name'],
                $occasion['label'],
                $top['ruek']['name'],
                $top['ruek_from']->format('H:i'),
                $top['ruek_to']->format('H:i'),
            );
        }

        $alts = array_slice($days, 1, 2);
        if ($alts) {
            $out[] = '**วันสำรอง**';
            foreach ($alts as $a) {
                $time = ! empty($a['yam'])
                    ? sprintf(' ตั้งพิธียาม%s %s–%s น.', $a['yam']['name'], $a['yam']['from'], $a['yam']['to'])
                    : '';
                $out[] = sprintf(
                    '- %s — %s (นักษัตร%s) %s คะแนน %d/100%s',
                    $a['label'],
                    $a['ruek']['name'],
                    $a['nakshatra'],
                    $a['tithi']['label'],
                    $a['score_pct'],
                    $time,
                );
            }
        }

        $warnings = array_values(array_unique(array_merge(...array_map(fn ($d) => $d['warnings'], $days))));
        if ($warnings) {
            $out[] = '**ข้อควรระวังในชุดวันนี้**';
            foreach (array_slice($warnings, 0, 3) as $w) {
                $out[] = '- '.$w;
            }
        }

        $out[] = '**หลักที่ใช้ตัดสิน**';
        $out[] = $occasion['note'];
        $out[] = sprintf(
            '_ระบบอ่านฤกษ์จากตำแหน่งจริงของดวงจันทร์บนจักรราศี (นักษัตร %d ฤกษ์บน 9) ประกอบกับดิถีข้างขึ้น-ข้างแรมและวารประจำวัน '
            .'ส่วนเวลาตั้งพิธีมาจากการลงเลขยามอัฐกาล ๘ ยามกลางวัน ยามละ ๑ ชั่วโมง ๓๐ นาที ระบบ +5 จากดาวเจ้าวัน '
            .'— ตรวจย้อนกลับกับตำราได้ทีละช่อง ไม่ได้สุ่มและไม่ได้ดูแค่วันในสัปดาห์_',
            count(ThaiAstro::NAKSHATRAS),
        );

        return implode("\n\n", $out);
    }
}
