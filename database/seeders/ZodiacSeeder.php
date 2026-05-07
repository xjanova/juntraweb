<?php

namespace Database\Seeders;

use App\Models\ChineseZodiac;
use App\Models\Zodiac;
use Illuminate\Database\Seeder;

class ZodiacSeeder extends Seeder
{
    public function run(): void
    {
        $western = [
            ['aries',       'Aries',       'เมษ',     '♈', 'Fire',  'Mars',    '21 มี.ค. – 19 เม.ย.', 1,  'กล้าหาญ มุ่งมั่น ผู้นำโดยกำเนิด'],
            ['taurus',      'Taurus',      'พฤษภ',   '♉', 'Earth', 'Venus',   '20 เม.ย. – 20 พ.ค.',   2,  'มั่นคง อดทน รักความสบาย'],
            ['gemini',      'Gemini',      'เมถุน',   '♊', 'Air',   'Mercury', '21 พ.ค. – 20 มิ.ย.',   3,  'ฉลาดเฉลียว สื่อสารดี ปรับตัวเก่ง'],
            ['cancer',      'Cancer',      'กรกฎ',    '♋', 'Water', 'Moon',    '21 มิ.ย. – 22 ก.ค.',   4,  'อ่อนโยน ใส่ใจครอบครัว สัญชาตญาณแม่นยำ'],
            ['leo',         'Leo',         'สิงห์',     '♌', 'Fire',  'Sun',     '23 ก.ค. – 22 ส.ค.',     5,  'ภาคภูมิใจ มีเสน่ห์ ผู้นำธรรมชาติ'],
            ['virgo',       'Virgo',       'กันย์',    '♍', 'Earth', 'Mercury', '23 ส.ค. – 22 ก.ย.',     6,  'ละเอียดรอบคอบ ใส่ใจรายละเอียด รักความสมบูรณ์แบบ'],
            ['libra',       'Libra',       'ตุล',      '♎', 'Air',   'Venus',   '23 ก.ย. – 22 ต.ค.',     7,  'ยุติธรรม ชอบความสมดุล รักศิลปะและความงาม'],
            ['scorpio',     'Scorpio',     'พิจิก',    '♏', 'Water', 'Pluto',   '23 ต.ค. – 21 พ.ย.',    8,  'ลึกลับ มั่นคงในความรู้สึก ทรงพลัง'],
            ['sagittarius', 'Sagittarius', 'ธนู',      '♐', 'Fire',  'Jupiter', '22 พ.ย. – 21 ธ.ค.',    9,  'ผจญภัย รักอิสระ มองโลกในแง่ดี'],
            ['capricorn',   'Capricorn',   'มังกร',    '♑', 'Earth', 'Saturn',  '22 ธ.ค. – 19 ม.ค.',    10, 'มุ่งมั่น มีวินัย ทะเยอทะยาน'],
            ['aquarius',    'Aquarius',    'กุมภ์',    '♒', 'Air',   'Uranus',  '20 ม.ค. – 18 ก.พ.',    11, 'อิสระทางความคิด นวัตกรรม มนุษยธรรม'],
            ['pisces',      'Pisces',      'มีน',      '♓', 'Water', 'Neptune', '19 ก.พ. – 20 มี.ค.',   12, 'ใจดี อ่อนไหว เปี่ยมจินตนาการ'],
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
