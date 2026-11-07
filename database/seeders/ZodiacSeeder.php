<?php

namespace Database\Seeders;

use App\Models\ChineseZodiac;
use App\Models\Zodiac;
use Illuminate\Database\Seeder;

class ZodiacSeeder extends Seeder
{
    public function run(): void
    {
        // 🇹🇭 (2026-11-07) ราศีแบบไทย (นิรายนะ ลาหิรี) ชุดเดียวกับผังดวงของบอทแม่หมอ — ช่วงวันที่คือวันที่ดวงอาทิตย์
        //    ย้ายราศีจริงโดยประมาณ (เลื่อนได้ปีละ ±1 วัน · ราศีของลูกค้าคำนวณจากดวงอาทิตย์จริงที่ Astrology::thaiZodiacSlug)
        //    ดาวเจ้าเรือนแบบโหราศาสตร์ไทย: พิจิก = อังคาร · กุมภ์ = เสาร์ · มีน = พฤหัสบดี (ไม่ใช้ดาวนอกแบบสากล)
        $western = [
            ['aries',       'Aries',       'เมษ',     '♈', 'Fire',  'Mars',    '15 เม.ย. – 15 พ.ค.', 1,  'กล้าหาญ มุ่งมั่น ผู้นำโดยกำเนิด'],
            ['taurus',      'Taurus',      'พฤษภ',   '♉', 'Earth', 'Venus',   '16 พ.ค. – 15 มิ.ย.',   2,  'มั่นคง อดทน รักความสบาย'],
            ['gemini',      'Gemini',      'เมถุน',   '♊', 'Air',   'Mercury', '16 มิ.ย. – 17 ก.ค.',   3,  'ฉลาดเฉลียว สื่อสารดี ปรับตัวเก่ง'],
            ['cancer',      'Cancer',      'กรกฎ',    '♋', 'Water', 'Moon',    '18 ก.ค. – 17 ส.ค.',   4,  'อ่อนโยน ใส่ใจครอบครัว สัญชาตญาณแม่นยำ'],
            ['leo',         'Leo',         'สิงห์',     '♌', 'Fire',  'Sun',     '18 ส.ค. – 17 ก.ย.',     5,  'ภาคภูมิใจ มีเสน่ห์ ผู้นำธรรมชาติ'],
            ['virgo',       'Virgo',       'กันย์',    '♍', 'Earth', 'Mercury', '18 ก.ย. – 17 ต.ค.',     6,  'ละเอียดรอบคอบ ใส่ใจรายละเอียด รักความสมบูรณ์แบบ'],
            ['libra',       'Libra',       'ตุล',      '♎', 'Air',   'Venus',   '18 ต.ค. – 16 พ.ย.',     7,  'ยุติธรรม ชอบความสมดุล รักศิลปะและความงาม'],
            ['scorpio',     'Scorpio',     'พิจิก',    '♏', 'Water', 'Mars',   '17 พ.ย. – 16 ธ.ค.',    8,  'ลึกลับ มั่นคงในความรู้สึก ทรงพลัง'],
            ['sagittarius', 'Sagittarius', 'ธนู',      '♐', 'Fire',  'Jupiter', '17 ธ.ค. – 14 ม.ค.',    9,  'ผจญภัย รักอิสระ มองโลกในแง่ดี'],
            ['capricorn',   'Capricorn',   'มังกร',    '♑', 'Earth', 'Saturn',  '15 ม.ค. – 13 ก.พ.',    10, 'มุ่งมั่น มีวินัย ทะเยอทะยาน'],
            ['aquarius',    'Aquarius',    'กุมภ์',    '♒', 'Air',   'Saturn',  '14 ก.พ. – 15 มี.ค.',    11, 'อิสระทางความคิด นวัตกรรม มนุษยธรรม'],
            ['pisces',      'Pisces',      'มีน',      '♓', 'Water', 'Jupiter', '16 มี.ค. – 14 เม.ย.',   12, 'ใจดี อ่อนไหว เปี่ยมจินตนาการ'],
        ];

        foreach ($western as [$slug, $en, $th, $glyph, $elem, $ruler, $range, $idx, $traits]) {
            Zodiac::updateOrCreate(['slug' => $slug], [
                'name_en' => $en, 'name_th' => $th, 'glyph' => $glyph,
                'element' => $elem, 'ruler' => $ruler, 'date_range' => $range,
                'order_index' => $idx, 'traits_th' => $traits,
            ]);
        }

        $chinese = [
            ['rat',     'Rat',     'ชวด',   '🐀', 1,  'ฉลาด มีไหวพริบ เก็บออมเก่ง'],
            ['ox',      'Ox',      'ฉลู',   '🐂', 2,  'ขยัน อดทน ซื่อสัตย์ มั่นคง'],
            ['tiger',   'Tiger',   'ขาล',   '🐅', 3,  'กล้าหาญ ทรงพลัง มีบารมี'],
            ['rabbit',  'Rabbit',  'เถาะ',  '🐇', 4,  'อ่อนโยน สงบเสงี่ยม รักศิลปะ'],
            ['dragon',  'Dragon',  'มะโรง', '🐉', 5,  'มีอำนาจ มีเสน่ห์ ผู้นำธรรมชาติ'],
            ['snake',   'Snake',   'มะเส็ง','🐍', 6,  'ลึกลับ ฉลาด มีญาณทัศนะ'],
            ['horse',   'Horse',   'มะเมีย','🐎', 7,  'รักอิสระ มีพลัง กระตือรือร้น'],
            ['goat',    'Goat',    'มะแม',  '🐐', 8,  'อ่อนโยน รักสันติ มีศิลปะ'],
            ['monkey',  'Monkey',  'วอก',   '🐒', 9,  'เฉลียวฉลาด ขี้เล่น แก้ปัญหาเก่ง'],
            ['rooster', 'Rooster', 'ระกา',  '🐓', 10, 'ตรงไปตรงมา ขยัน รักษาคำพูด'],
            ['dog',     'Dog',     'จอ',    '🐕', 11, 'ซื่อสัตย์ จงรักภักดี เป็นมิตร'],
            ['pig',     'Pig',     'กุน',   '🐖', 12, 'ใจดี โอบอ้อมอารี ขยันสะสมทรัพย์'],
        ];

        foreach ($chinese as [$slug, $en, $th, $glyph, $idx, $traits]) {
            ChineseZodiac::updateOrCreate(['slug' => $slug], [
                'name_th' => $th, 'name_en' => $en, 'glyph' => $glyph,
                'order_index' => $idx, 'traits_th' => $traits,
            ]);
        }
    }
}
