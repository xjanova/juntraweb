<?php

namespace Tests\Unit;

use App\Support\Alerts\ThaiShaper;
use PHPUnit\Framework\TestCase;

/**
 * The PUA substitutions that let GD draw Thai correctly. Each case is a word GD visibly mangled
 * without them (ที่ drew as ที, สิ่ง as สีง, marks on ป/ฟ sat on the ascender) — and each code point
 * asserted here must exist in resources/fonts, built by build_thai_font.py with the same layout.
 */
class ThaiShaperTest extends TestCase
{
    private static function u(int ...$codes): string
    {
        return implode('', array_map(fn ($c) => mb_chr($c, 'UTF-8'), $codes));
    }

    public function test_tone_over_an_upper_vowel_uses_the_stacked_glyph(): void
    {
        // ท ี ่  → ท ี + [mai ek stacked over sara ii] = U+F710 + 0*8 + 2
        $this->assertSame(self::u(0x0E17, 0x0E35, 0xF712), ThaiShaper::shape('ที่'));
        // ช ื ่ อ
        $this->assertSame(self::u(0x0E0A, 0x0E37, 0xF714, 0x0E2D), ThaiShaper::shape('ชื่อ'));
    }

    public function test_marks_on_a_tall_consonant_move_off_the_ascender(): void
    {
        // ป ่ า → ป + [narrow mai ek] (U+F700 + 7 upper vowels + 0) + า
        $this->assertSame(self::u(0x0E1B, 0xF707, 0x0E32), ThaiShaper::shape('ป่า'));
        // ป ั ้ น → ป + [narrow mai han-akat] + [mai tho stacked, tall] (U+F740 + 1*8 + 0) + น
        $this->assertSame(self::u(0x0E1B, 0xF700, 0xF748, 0x0E19), ThaiShaper::shape('ปั้น'));
        // A lower vowel between the tall base and the tone does not change which base it is: ปู่
        $this->assertSame(self::u(0x0E1B, 0x0E39, 0xF707), ThaiShaper::shape('ปู่'));
    }

    public function test_sara_am_after_a_tone_is_split_so_the_tone_sits_on_the_nikhahit(): void
    {
        // น ้ ำ → น ํ [mai tho stacked over nikhahit] (U+F710 + 1*8 + 6) า
        $this->assertSame(self::u(0x0E19, 0x0E4D, 0xF71E, 0x0E32), ThaiShaper::shape('น้ำ'));
    }

    public function test_yo_ying_loses_its_descender_before_a_lower_vowel_only(): void
    {
        $this->assertSame(self::u(0xF770, 0x0E38), ThaiShaper::shape('ญุ'));
        $this->assertSame('ญา', ThaiShaper::shape('ญา'));
    }

    public function test_text_that_needs_nothing_is_returned_untouched(): void
    {
        $this->assertSame('hello 24hdx', ThaiShaper::shape('hello 24hdx'));
        $this->assertSame('กาแฟ เลย', ThaiShaper::shape('กาแฟ เลย'));
        $this->assertSame('ก่', ThaiShaper::shape('ก่'));   // a lone tone on a normal consonant is already right
    }

    public function test_font_safe_drops_what_the_font_cannot_draw(): void
    {
        $this->assertSame('แหล่งล่ม 24hdx', ThaiShaper::fontSafe('🚨 แหล่งล่ม 24hdx'));
        $this->assertSame("บรรทัด 1\nบรรทัด 2", ThaiShaper::fontSafe("บรรทัด 1\nบรรทัด 2"));
        $this->assertSame('• /api ×10 · ok…', ThaiShaper::fontSafe('• /api ×10 · ok…'));
        $this->assertSame('ab', ThaiShaper::fontSafe('a±²b'));   // Latin-1 symbols Noto Sans Thai lacks
    }
}
