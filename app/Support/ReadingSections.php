<?php

namespace App\Support;

/**
 * แยกคำทำนายไพ่ของแม่หมอออกเป็นชิ้น เพื่อจัดเป็นการ์ด/ตารางบนหน้าผล (เจ้าของสั่ง 2026-09-15)
 *
 * แม่หมอตอบด้วย "หัวข้อตายตัว" ที่โปรไฟล์ของแพ็กเกจกำหนด (Thaiprompt
 * App\Services\Fortune\JuntraSpreadProfiles::HEADINGS) เช่น "## 🎯 ฟันธง" · "## 🃏 ใบที่ 2 · อีกฝ่าย"
 * · "## 📅 ต.ค. 2569 · ดี" — ข้อความเดียวกันยังอ่านเป็น markdown ปกติได้ในแอพและประวัติ
 *
 * ⚠️ emoji ของหัวข้อคือสัญญากับฝั่ง Thaiprompt — แก้ที่หนึ่งต้องแก้อีกที่ (มีเทสต์ตรึงทั้งสองฝั่ง)
 * แยกไม่ออก (คำทำนายเก่า / แม่หมอตอบผิดรูปแบบ) → ok=false แล้วหน้าเว็บแสดงเป็นข้อความสวยแบบเดิม
 */
final class ReadingSections
{
    /** emoji นำหน้าหัวข้อ → ชนิดของชิ้น */
    private const TYPES = [
        '🎯' => 'verdict',
        '🃏' => 'card',
        '📅' => 'month',
        '📖' => 'story',
        '💞' => 'hearts',
        '⏳' => 'timing',
        '🔀' => 'option',
        '✅' => 'choice',
        '🌟' => 'golden',
        '⚠' => 'caution',
        '🌠' => 'birth',
        '🪬' => 'diagnosis',
        '🔍' => 'detail',
        '🙏' => 'remedy',
        '🧿' => 'direction',
        '☎' => 'safety',
        '🧭' => 'advice',
    ];

    /** บรรทัด "คีย์: ค่า" ที่แยกเป็นช่องตาราง */
    private const FIELD_KEYS = [
        'hearts' => ['ใจเรา', 'ใจเขา'],
        'option' => ['ข้อดี', 'ข้อควรระวัง', 'ผลที่ตามมา'],
        'diagnosis' => ['พบของ', 'ชนิด', 'ความรุนแรง', 'ลักษณะผู้ทำ', 'ช่วงเวลา', 'มาทางทิศ', 'มูลเหตุ', 'เกราะคุ้มครอง'],
    ];

    /** ชิ้นที่เป็นรายการ "- ..." */
    private const LIST_TYPES = ['golden', 'caution', 'remedy', 'advice'];

    /**
     * @return array{ok:bool, sections:list<array<string,mixed>>, by_type:array<string,list<array<string,mixed>>>}
     */
    public static function parse(?string $text): array
    {
        $empty = ['ok' => false, 'sections' => [], 'by_type' => []];
        $text = str_replace("\r\n", "\n", trim((string) $text));
        if ($text === '' || ! str_contains($text, '#')) {
            return $empty;
        }

        $sections = [];
        $current = null;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*#{2,4}\s*(.+?)\s*#*\s*$/u', $line, $m)) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = ['heading' => self::clean($m[1]), 'lines' => []];

                continue;
            }
            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }

        $out = [];
        foreach ($sections as $s) {
            $type = self::typeOf($s['heading']);
            if ($type === null) {
                continue;
            }
            $out[] = self::build($type, $s['heading'], $s['lines']);
        }

        $byType = [];
        foreach ($out as $s) {
            $byType[$s['type']][] = $s;
        }

        // ใช้การ์ดได้เมื่อมีหัวใจของคำตอบ (ฟันธง/วินิจฉัย) และมีชิ้นอื่นอีกอย่างน้อยหนึ่งชิ้น
        $ok = (isset($byType['verdict']) || isset($byType['diagnosis'])) && count($out) >= 2;

        return ['ok' => $ok, 'sections' => $out, 'by_type' => $byType];
    }

    private static function typeOf(string $heading): ?string
    {
        foreach (self::TYPES as $emoji => $type) {
            if (str_starts_with($heading, $emoji)) {
                return $type;
            }
        }

        return null;
    }

    /** @param  array<int,string>  $lines */
    private static function build(string $type, string $heading, array $lines): array
    {
        // ตัด emoji (+ variation selector) ออกจากหัวข้อ เหลือชื่อหัวข้อ
        $title = trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $heading));
        $s = ['type' => $type, 'title' => $title];

        $body = [];
        $fields = [];
        $items = [];
        $keys = self::FIELD_KEYS[$type] ?? [];

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') {
                $body[] = '';

                continue;
            }
            if ($type === 'verdict' && ! isset($s['result']) && preg_match('/^\**\s*ผล\s*[:：]\s*(.+?)\**$/u', $line, $m)) {
                $s['result'] = self::clean($m[1]);

                continue;
            }
            if ($type === 'choice' && ! isset($s['chosen']) && preg_match('/^\**\s*เลือก\s*[:：].*?(\d)/u', $line, $m)) {
                $s['chosen'] = (int) $m[1];

                continue;
            }
            if ($type === 'month' && ! isset($s['theme']) && preg_match('/^\**\s*ธีม\s*[:：]\s*(.+?)\**$/u', $line, $m)) {
                $s['theme'] = self::clean($m[1]);

                continue;
            }
            if ($keys !== [] && preg_match('/^[-•*\s]*\**\s*('.implode('|', array_map('preg_quote', $keys)).')\s*\**\s*[:：]\s*(.*)$/u', $line, $m)) {
                $fields[$m[1]] = self::clean($m[2]);

                continue;
            }
            if (in_array($type, self::LIST_TYPES, true) && preg_match('/^[-•*]\s+(.+)$/u', $line, $m)) {
                $items[] = self::clean($m[1]);

                continue;
            }
            $body[] = $raw;
        }

        if ($type === 'card' && preg_match('/(\d+)\s*[·\-–:]?\s*(.*)$/u', $title, $m)) {
            $s['n'] = (int) $m[1];
            $s['label'] = trim($m[2], " ·-–:");
        }
        if ($type === 'option' && preg_match('/(\d+)/u', $title, $m)) {
            $s['n'] = (int) $m[1];
        }
        if ($type === 'month') {
            // "ต.ค. 2569 · ดี" → เดือน + โทน (โทนที่ไม่รู้จัก = กลาง)
            $parts = preg_split('/\s*[·|]\s*/u', $title);
            $s['month'] = trim($parts[0] ?? $title);
            $toneWord = trim($parts[1] ?? '');
            $s['tone'] = match (true) {
                str_contains($toneWord, 'ดี') => 'good',
                str_contains($toneWord, 'ระวัง') => 'caution',
                default => 'neutral',
            };
            $s['tone_label'] = $toneWord !== '' ? $toneWord : 'กลาง';
        }

        $s['fields'] = $fields;
        $s['items'] = $items;
        $s['body'] = trim(implode("\n", $body));

        return $s;
    }

    /** ตัด ** และช่องว่างส่วนเกินที่โมเดลชอบแถมรอบค่า */
    private static function clean(string $v): string
    {
        return trim(str_replace('**', '', $v), " \t*_");
    }

    /**
     * สีของผลฟันธง — ใช่ = เขียว · ไม่ใช่ = แดง · ยังไม่ใช่ตอนนี้ = อำพัน · อื่น ๆ = ทอง
     */
    public static function verdictTone(?string $result): string
    {
        $r = (string) $result;

        return match (true) {
            $r === '' => 'gold',
            str_starts_with($r, 'ยังไม่') => 'amber',
            str_starts_with($r, 'ไม่') => 'red',
            str_starts_with($r, 'ใช่'), str_contains($r, 'เลือกทางเลือก') => 'green',
            default => 'gold',
        };
    }
}
