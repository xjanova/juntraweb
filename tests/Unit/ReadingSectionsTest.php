<?php

namespace Tests\Unit;

use App\Support\ReadingSections;
use PHPUnit\Framework\TestCase;

/**
 * หัวข้อคำทำนายเป็นสัญญากับ Thaiprompt (App\Services\Fortune\JuntraSpreadProfiles::HEADINGS)
 * — ตรึงรูปแบบที่แม่หมอส่งมาจริงไว้ที่นี่ แยกไม่ออก = หน้าเว็บตกไปแสดงข้อความธรรมดา (ไม่พัง)
 */
class ReadingSectionsTest extends TestCase
{
    public function test_a_love_reading_splits_into_verdict_hearts_cards_and_advice(): void
    {
        $p = ReadingSections::parse(<<<'MD'
## 🎯 ฟันธง
ผล: ใช่
ไพ่หนุนความรักของลูกค่ะ

## 🃏 ใบที่ 1 · ตัวคุณ
ใจลูกเปิดรับ **เต็มที่**

## 🃏 ใบที่ 2 · อีกฝ่าย
เขายังลังเล

## 💞 ใจเรา-ใจเขา
ใจเรา: รักจริง
ใจเขา: ยังกลัวผูกมัด

## 🧭 คำแนะนำ
- คุยกันตรง ๆ สัปดาห์นี้
- อย่าเร่งเขา
MD);

        $this->assertTrue($p['ok']);
        $v = $p['by_type']['verdict'][0];
        $this->assertSame('ใช่', $v['result']);
        $this->assertSame('ไพ่หนุนความรักของลูกค่ะ', $v['body']);
        $this->assertSame([1, 2], array_column($p['by_type']['card'], 'n'));
        $this->assertSame('อีกฝ่าย', $p['by_type']['card'][1]['label']);
        $this->assertSame(['ใจเรา' => 'รักจริง', 'ใจเขา' => 'ยังกลัวผูกมัด'], $p['by_type']['hearts'][0]['fields']);
        $this->assertSame(['คุยกันตรง ๆ สัปดาห์นี้', 'อย่าเร่งเขา'], $p['by_type']['advice'][0]['items']);
        $this->assertSame('green', ReadingSections::verdictTone($v['result']));
    }

    public function test_months_carry_their_tone_and_theme(): void
    {
        $p = ReadingSections::parse("## 🎯 ฟันธง\nผล: ปีแห่งการเติบโต\n\n## 📅 ต.ค. 2569 · ระวัง\nธีม: เงินรั่ว\nระวังค่าใช้จ่าย\n\n## 📅 พ.ย. 2569 · ดี\nธีม: งานรุ่ง\nมีข่าวดี\n\n## ⚠️ เดือนที่ต้องระวัง\n- ต.ค. 2569: เงินรั่ว");

        $m = $p['by_type']['month'];
        $this->assertSame(['ต.ค. 2569', 'พ.ย. 2569'], array_column($m, 'month'));
        $this->assertSame(['caution', 'good'], array_column($m, 'tone'));
        $this->assertSame('เงินรั่ว', $m[0]['theme']);
        $this->assertSame(['ต.ค. 2569: เงินรั่ว'], $p['by_type']['caution'][0]['items']);
    }

    public function test_decision_options_become_a_comparison_with_the_chosen_side(): void
    {
        $p = ReadingSections::parse("## 🎯 ฟันธง\nผล: เลือกทางเลือกที่ 2\n\n## 🔀 ทางเลือกที่ 1\nย้ายงาน\nข้อดี: เงินเดือนขึ้น\nข้อควรระวัง: หัวหน้าใหม่ดุ\nผลที่ตามมา: เหนื่อยช่วงแรก\n\n## 🔀 ทางเลือกที่ 2\nอยู่ที่เดิม\nข้อดี: มั่นคง\n\n## ✅ ไพ่เลือกทาง\nเลือก: ทางเลือกที่ 2\nเพราะไพ่ใบที่ 3 หนุน");

        $o = $p['by_type']['option'];
        $this->assertSame([1, 2], array_column($o, 'n'));
        $this->assertSame('เงินเดือนขึ้น', $o[0]['fields']['ข้อดี']);
        $this->assertSame('ย้ายงาน', $o[0]['body']);
        $this->assertSame(2, $p['by_type']['choice'][0]['chosen']);
    }

    public function test_kunsai_diagnosis_fields_become_a_table(): void
    {
        $p = ReadingSections::parse("## 🪬 ผลวินิจฉัย\nพบของ: ไม่ใช่\nชนิด: —\nความรุนแรง: —\nไพ่ไม่ได้บอกว่าโดนของนะคะ\n\n## 🙏 ทางแก้\n- สวดอิติปิโสก่อนนอน");

        $this->assertTrue($p['ok']);
        $d = $p['by_type']['diagnosis'][0];
        $this->assertSame('ไม่ใช่', $d['fields']['พบของ']);
        $this->assertSame('ไพ่ไม่ได้บอกว่าโดนของนะคะ', $d['body']);
        $this->assertSame(['สวดอิติปิโสก่อนนอน'], $p['by_type']['remedy'][0]['items']);
    }

    public function test_old_prose_readings_fall_back_to_plain_text(): void
    {
        $this->assertFalse(ReadingSections::parse('**ภาพรวม** ดวงดาวเปิดทางให้ค่ะ')['ok']);
        $this->assertFalse(ReadingSections::parse("## บทนำ\nข้อความ\n\n## สรุป\nจบ")['ok'], 'unknown headings are not cards');
        $this->assertFalse(ReadingSections::parse(null)['ok']);
    }

    public function test_bold_markers_and_hash_variants_the_model_likes_are_tolerated(): void
    {
        $p = ReadingSections::parse("### 🎯 ฟันธง\n**ผล: ไม่ใช่**\nยังไม่ถึงเวลา\n\n## 🃏 ใบที่ 1 - คำตอบของไพ่\nกลับหัว");

        $this->assertTrue($p['ok']);
        $this->assertSame('ไม่ใช่', $p['by_type']['verdict'][0]['result']);
        $this->assertSame('red', ReadingSections::verdictTone('ไม่ใช่'));
        $this->assertSame('amber', ReadingSections::verdictTone('ยังไม่ใช่ตอนนี้'));
        $this->assertSame(1, $p['by_type']['card'][0]['n']);
    }
}
