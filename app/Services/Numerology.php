<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Pythagorean numerology with Thai-friendly narratives.
 */
class Numerology
{
    private array $meanings = [
        1 => ['name' => 'เลข 1 — ผู้นำ',         'narrative' => 'คุณเกิดมาเพื่อเป็นผู้บุกเบิก มีความเป็นตัวของตัวเอง กล้าตัดสินใจ และมีพลังในการเริ่มต้นสิ่งใหม่อยู่เสมอ'],
        2 => ['name' => 'เลข 2 — ผู้ประสาน',    'narrative' => 'คุณมีพรสวรรค์ในการเข้าใจผู้อื่น เป็นผู้ฟังที่ดี ทำงานเป็นทีมได้ยอดเยี่ยม ใจอ่อนโยนเป็นเสน่ห์'],
        3 => ['name' => 'เลข 3 — ศิลปิน',       'narrative' => 'คุณเปี่ยมจินตนาการ มีพลังในการสื่อสาร และเสน่ห์ในการทำให้ผู้คนยิ้มได้เสมอ ความสุขเป็นอาวุธของคุณ'],
        4 => ['name' => 'เลข 4 — นักสร้าง',      'narrative' => 'คุณเป็นคนน่าเชื่อถือ มีวินัย ทำงานเป็นระบบ — โครงสร้างที่คุณวางจะเป็นรากฐานของคนรอบตัว'],
        5 => ['name' => 'เลข 5 — นักเดินทาง',    'narrative' => 'คุณรักอิสระ ชอบการผจญภัย ปรับตัวเก่ง ชีวิตของคุณจะมีโอกาสไม่ซ้ำเดิม จงคว้าโอกาสนั้น'],
        6 => ['name' => 'เลข 6 — ผู้ดูแล',       'narrative' => 'หัวใจของคุณเปี่ยมด้วยความรักและความรับผิดชอบ คุณเป็นที่พึ่งของครอบครัวและคนใกล้ชิด'],
        7 => ['name' => 'เลข 7 — นักปราชญ์',     'narrative' => 'คุณรักการเรียนรู้สิ่งลึกซึ้ง สัญชาตญาณแม่นยำ — เส้นทางจิตวิญญาณคือทางที่จะทำให้คุณเปล่งประกายที่สุด'],
        8 => ['name' => 'เลข 8 — ผู้บริหาร',     'narrative' => 'คุณเกิดมาเพื่อบริหารจัดการ มีสายตามองธุรกิจ มีพลังนำเงินทองมา — แต่ต้องระวังการใช้อำนาจให้ยุติธรรม'],
        9 => ['name' => 'เลข 9 — ผู้ให้',        'narrative' => 'คุณคือมนุษยธรรมที่เดินดิน มีหัวใจกว้าง ให้โดยไม่หวังผล — โลกใบนี้ดีขึ้นเพราะคนอย่างคุณ'],
    ];

    private array $letterMap = [
        // English alphabet — Pythagorean (1-9)
        'a' => 1, 'j' => 1, 's' => 1,
        'b' => 2, 'k' => 2, 't' => 2,
        'c' => 3, 'l' => 3, 'u' => 3,
        'd' => 4, 'm' => 4, 'v' => 4,
        'e' => 5, 'n' => 5, 'w' => 5,
        'f' => 6, 'o' => 6, 'x' => 6,
        'g' => 7, 'p' => 7, 'y' => 7,
        'h' => 8, 'q' => 8, 'z' => 8,
        'i' => 9, 'r' => 9,
    ];

    public function analyze(string $name, string $birthDate): array
    {
        $life = $this->lifePathNumber($birthDate);
        $expression = $this->expressionNumber($name);
        $birthDay = (int) Carbon::parse($birthDate)->day;
        $birthDayReduced = $this->reduceToDigit($birthDay);

        return [
            'life_path' => $life,
            'life_path_meaning' => $this->meanings[$life] ?? $this->meanings[1],
            'expression' => $expression,
            'expression_meaning' => $this->meanings[$expression] ?? $this->meanings[1],
            'birth_day' => $birthDay,
            'birth_day_reduced' => $birthDayReduced,
            'birth_day_meaning' => $this->meanings[$birthDayReduced] ?? $this->meanings[1],
            'narrative' => $this->buildNarrative($name, $life, $expression, $birthDayReduced),
        ];
    }

    private function lifePathNumber(string $date): int
    {
        $digits = preg_replace('/\D/', '', $date);
        return $this->reduceToDigit(array_sum(str_split($digits)));
    }

    private function expressionNumber(string $name): int
    {
        $sum = 0;
        $lc = mb_strtolower($name);
        // sum of mapped Latin chars
        for ($i = 0, $len = mb_strlen($lc); $i < $len; $i++) {
            $ch = mb_substr($lc, $i, 1);
            if (isset($this->letterMap[$ch])) {
                $sum += $this->letterMap[$ch];
            }
        }

        // For Thai names: count vowels-like characters and consonant strokes — approximation:
        // each Thai consonant counts as 2, each vowel as 1
        if ($sum === 0) {
            for ($i = 0, $len = mb_strlen($name); $i < $len; $i++) {
                $ch = mb_substr($name, $i, 1);
                $code = mb_ord($ch, 'UTF-8');
                if ($code >= 0x0E01 && $code <= 0x0E2E) $sum += 2; // consonants
                elseif ($code >= 0x0E30 && $code <= 0x0E4E) $sum += 1; // vowels
            }
        }

        return $sum > 0 ? $this->reduceToDigit($sum) : 1;
    }

    private function reduceToDigit(int $num): int
    {
        while ($num > 9) {
            $num = array_sum(str_split((string) $num));
        }
        return $num ?: 1;
    }

    private function buildNarrative(string $name, int $life, int $expression, int $day): string
    {
        $lp = $this->meanings[$life] ?? $this->meanings[1];
        $ex = $this->meanings[$expression] ?? $this->meanings[1];
        $dy = $this->meanings[$day] ?? $this->meanings[1];

        return "**คุณ{$name}** ตามหลักเลขศาสตร์ ผลรวมจากวัน-เดือน-ปีเกิดของคุณคือ **เลข {$life}** — {$lp['name']}: {$lp['narrative']}\n\n"
             . "ส่วนเลขนาม (Expression) คำนวณจากชื่อของคุณได้ **เลข {$expression}** — {$ex['name']}: {$ex['narrative']}\n\n"
             . "และเลขวันเกิดของคุณคือ **เลข {$day}** — {$dy['name']}: {$dy['narrative']}\n\n"
             . 'คำแนะนำ: เลขทั้งสามตัวจะทำงานร่วมกันในชีวิตของคุณ — เลข life path บอกเส้นทาง, เลข expression บอกพรสวรรค์ที่คุณนำไปใช้, และเลขวันเกิดบอกบุคลิกที่คนรอบข้างมองเห็น เปิดใจรับทั้งสามและเลือกทางที่สอดคล้องกับใจคุณ';
    }
}
