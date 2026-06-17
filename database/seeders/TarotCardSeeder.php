<?php

namespace Database\Seeders;

use App\Models\TarotCard;
use Illuminate\Database\Seeder;

/**
 * Seeds all 78 Rider-Waite cards.
 *
 * Card STRUCTURE (slug, names, arcana, suit, number) is fixed and lives
 * here. The RICH Thai MEANINGS (keywords + upright/reversed + love/career/
 * money) live in database/data/tarot_meanings.php — "แม่หมอจันทรา"'s
 * knowledge base — so content can be improved without touching code.
 *
 * Idempotent: re-runnable on every deploy. Meaning text is refreshed each
 * run; image_path is only set on first insert so admin-uploaded / imported
 * art is preserved.
 *
 * Minor Arcana names follow the Thaiprompt convention (no "แห่ง"):
 * e.g. "ห้าดาบ", "ราชาเหรียญ".
 */
class TarotCardSeeder extends Seeder
{
    public function run(): void
    {
        $meanings = $this->loadMeanings();

        foreach ($this->scaffold() as $row) {
            $m = $meanings[$row['slug']] ?? [];

            $card = TarotCard::firstOrNew(['slug' => $row['slug']]);
            $card->fill([
                'name_en'             => $row['name_en'],
                'name_th'             => $row['name_th'],
                'arcana'              => $row['arcana'],
                'suit'                => $row['suit'],
                'number'              => $row['number'],
                'keywords_th'         => $m['keywords_th']         ?? $row['fallback_keywords'],
                'upright_meaning_th'  => $m['upright_meaning_th']  ?? $row['fallback_upright'],
                'reversed_meaning_th' => $m['reversed_meaning_th'] ?? $row['fallback_reversed'],
                'love_th'             => $m['love_th']             ?? null,
                'career_th'           => $m['career_th']           ?? null,
                'money_th'            => $m['money_th']             ?? null,
                'active'              => true,
            ]);

            // Only set image on FIRST insert — preserve imported/uploaded art on re-run.
            if (!$card->exists) {
                $card->image_path = $row['image_path'];
            }
            $card->save();
        }
    }

    /** slug => rich meanings, from the knowledge-base data file (if present). */
    private function loadMeanings(): array
    {
        $path = database_path('data/tarot_meanings.php');
        if (!is_file($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
    }

    /**
     * The fixed structure of all 78 cards + a deterministic fallback meaning
     * so the seeder is never blocked by a missing knowledge-base entry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scaffold(): array
    {
        $rows = [];

        /* ---------- Major Arcana (22) ---------- */
        $placeholders = [
            'magician' => 'images/card-magician.png',
            'sun'      => 'images/card-sun.png',
            'world'    => 'images/card-world.png',
        ];

        $major = [
            ['fool',             0,  'The Fool',           'คนโง่',         'การเริ่มต้น/อิสระ/ไร้เดียงสา'],
            ['magician',         1,  'The Magician',       'นักมายากล',     'พลัง/สร้างสรรค์/เริ่มต้น'],
            ['high-priestess',   2,  'The High Priestess', 'นักบวชหญิง',    'ปัญญาภายใน/ความลับ/สัญชาตญาณ'],
            ['empress',          3,  'The Empress',        'จักรพรรดินี',   'ความอุดมสมบูรณ์/แม่/ธรรมชาติ'],
            ['emperor',          4,  'The Emperor',        'จักรพรรดิ',     'อำนาจ/โครงสร้าง/บิดา'],
            ['hierophant',       5,  'The Hierophant',     'พระสงฆ์',       'ประเพณี/ศาสนา/การเรียน'],
            ['lovers',           6,  'The Lovers',         'คู่รัก',         'ความรัก/การเลือก/ความสัมพันธ์'],
            ['chariot',          7,  'The Chariot',        'รถศึก',         'ชัยชนะ/วินัย/ก้าวไป'],
            ['strength',         8,  'Strength',           'พลังใจ',        'ความกล้า/ความอ่อนโยน/พลังภายใน'],
            ['hermit',           9,  'The Hermit',         'ฤๅษี',          'การค้นหา/ความสันโดษ/ปัญญา'],
            ['wheel-of-fortune', 10, 'Wheel of Fortune',   'กงล้อแห่งโชค',  'โชคชะตา/วงล้อ/การเปลี่ยน'],
            ['justice',          11, 'Justice',            'ความยุติธรรม',  'ความเป็นธรรม/ความจริง/กรรม'],
            ['hanged-man',       12, 'The Hanged Man',     'คนถูกแขวน',     'มุมมอง/การเสียสละ/หยุดนิ่ง'],
            ['death',            13, 'Death',              'ความตาย',       'จุดจบ/การเปลี่ยน/เริ่มใหม่'],
            ['temperance',       14, 'Temperance',         'ความพอประมาณ',  'สมดุล/ความอดทน/ผสมผสาน'],
            ['devil',            15, 'The Devil',          'ปีศาจ',         'พันธะ/วัตถุ/ความหลง'],
            ['tower',            16, 'The Tower',          'หอคอย',         'การพังทลาย/การเปิดเผย/ฟ้าผ่า'],
            ['star',             17, 'The Star',           'ดวงดาว',        'ความหวัง/แรงบันดาลใจ/การเยียวยา'],
            ['moon',             18, 'The Moon',           'พระจันทร์',     'จินตนาการ/ความฝัน/สับสน'],
            ['sun',              19, 'The Sun',            'พระอาทิตย์',    'ความสุข/ความสำเร็จ/พลังบวก'],
            ['judgement',        20, 'Judgement',          'การตัดสิน',     'การตื่นรู้/การปลดปล่อย/การเรียก'],
            ['world',            21, 'The World',          'โลก',           'ความสำเร็จ/ปิดวงจร/ครบสมบูรณ์'],
        ];

        foreach ($major as [$slug, $num, $en, $th, $kw]) {
            $rows[] = [
                'slug' => $slug, 'name_en' => $en, 'name_th' => $th,
                'arcana' => 'major', 'suit' => 'major', 'number' => $num,
                'image_path' => $placeholders[$slug] ?? 'images/card-magician.png',
                'fallback_keywords' => $kw,
                'fallback_upright'  => "พลังของไพ่{$th}ในด้านบวก: {$kw}",
                'fallback_reversed' => "ไพ่{$th}กลับหัว: พลังด้านนี้ติดขัดหรือกลับด้าน ควรทบทวนและระวัง",
            ];
        }

        /* ---------- Minor Arcana (56) ---------- */
        $suits = [
            'wands'     => ['ไม้เท้า', 'Wands',     'พลังงาน ความคิดสร้างสรรค์ การเริ่มต้น การงาน'],
            'cups'      => ['ถ้วย',   'Cups',      'อารมณ์ ความรัก จิตใจ ความสัมพันธ์'],
            'swords'    => ['ดาบ',    'Swords',    'ความคิด สติปัญญา ความขัดแย้ง การตัดสินใจ'],
            'pentacles' => ['เหรียญ', 'Pentacles', 'การเงิน การงานที่จับต้องได้ ทรัพย์สิน สุขภาพ'],
        ];

        $ranks = [
            ['ace', 1, 'Ace', 'เอซ'], ['two', 2, 'Two', 'สอง'], ['three', 3, 'Three', 'สาม'],
            ['four', 4, 'Four', 'สี่'], ['five', 5, 'Five', 'ห้า'], ['six', 6, 'Six', 'หก'],
            ['seven', 7, 'Seven', 'เจ็ด'], ['eight', 8, 'Eight', 'แปด'], ['nine', 9, 'Nine', 'เก้า'],
            ['ten', 10, 'Ten', 'สิบ'], ['page', 11, 'Page', 'เพจ'], ['knight', 12, 'Knight', 'อัศวิน'],
            ['queen', 13, 'Queen', 'ราชินี'], ['king', 14, 'King', 'ราชา'],
        ];

        foreach ($suits as $suitKey => [$suitTh, $suitEn, $suitMeaning]) {
            foreach ($ranks as [$rankSlug, $rankNum, $rankEn, $rankTh]) {
                $slug   = "{$rankSlug}-of-{$suitKey}";
                $nameTh = $rankTh . $suitTh;   // no "แห่ง" — "ห้าดาบ", "ราชาเหรียญ"
                $rows[] = [
                    'slug' => $slug,
                    'name_en' => "{$rankEn} of {$suitEn}",
                    'name_th' => $nameTh,
                    'arcana' => 'minor', 'suit' => $suitKey, 'number' => $rankNum,
                    'image_path' => 'images/card-magician.png',
                    'fallback_keywords' => $suitMeaning,
                    'fallback_upright'  => "ไพ่{$nameTh}: พลังของเลข{$rankTh}ในด้าน{$suitMeaning}",
                    'fallback_reversed' => "ไพ่{$nameTh}กลับหัว: พลังด้านนี้ติดขัด ล่าช้า หรือยังไม่ลงตัว ควรปรับและระวัง",
                ];
            }
        }

        return $rows;
    }
}
