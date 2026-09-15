<?php

namespace App\Support\Alerts;

/**
 * Makes Thai text safe to hand to GD, which draws glyphs with FreeType alone and no shaping engine.
 *
 * Without shaping every Thai mark sits at its one default spot, so a tone mark over an upper vowel
 * (ที่ ชื่อ น้ำ) collides with the vowel, and marks on a tall consonant (ป่า ฟ้า ฝั่ง) land on its
 * ascender. The alert fonts in resources/fonts carry ready-positioned copies of those marks in the
 * Private Use Area (see build_thai_font.py); this swaps them in by context — the same trick Thai
 * fonts used for renderers that could not shape.
 *
 * The PUA layout here and in resources/fonts/build_thai_font.py MUST match. The output is only for
 * drawing: wrap and measure-for-layout on the original string, then shape each finished line.
 */
final class ThaiShaper
{
    /** Order matters: it is the index used in the PUA layout. */
    private const UPPER = ["\u{0E31}", "\u{0E34}", "\u{0E35}", "\u{0E36}", "\u{0E37}", "\u{0E47}", "\u{0E4D}"];

    private const TONES = ["\u{0E48}", "\u{0E49}", "\u{0E4A}", "\u{0E4B}", "\u{0E4C}"];

    private const LOWER = ["\u{0E38}", "\u{0E39}", "\u{0E3A}"];

    /** ป ฝ ฟ ฬ — the ascender sits where an upper mark would go. */
    private const TALL = ["\u{0E1B}", "\u{0E1D}", "\u{0E1F}", "\u{0E2C}"];

    private const SARA_AM = "\u{0E33}";

    private const NIKHAHIT = "\u{0E4D}";

    private const SARA_AA = "\u{0E32}";

    private const NARROW_BASE = 0xF700;

    private const STACK_BASE = 0xF710;

    private const STACK_TALL_BASE = 0xF740;

    /** ญ ฐ lose their descender when a lower vowel hangs under them. */
    private const DESCLESS = ["\u{0E0D}" => 0xF770, "\u{0E10}" => 0xF771];

    public static function shape(string $text): string
    {
        if (! preg_match('/[\x{0E00}-\x{0E7F}]/u', $text)) {
            return $text;
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $tall = false;      // is the current cluster's base a tall consonant?
        $upper = null;      // index of the upper vowel already placed in this cluster

        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];

            $u = array_search($c, self::UPPER, true);
            if ($u !== false) {
                $upper = $u;
                $out .= $tall ? self::chr(self::NARROW_BASE + $u) : $c;

                continue;
            }

            $t = array_search($c, self::TONES, true);
            if ($t !== false) {
                // Tone + ำ: ำ is really ํ + า, and the tone belongs ON the ํ (น้ำ, ค่ำ).
                if (($chars[$i + 1] ?? null) === self::SARA_AM) {
                    $nik = 6;   // index of ํ in UPPER
                    $out .= ($tall ? self::chr(self::NARROW_BASE + $nik) : self::NIKHAHIT)
                        . self::stacked($t, $nik, $tall)
                        . self::SARA_AA;
                    $i++;
                    $upper = null;

                    continue;
                }
                $out .= $upper !== null
                    ? self::stacked($t, $upper, $tall)
                    : ($tall ? self::chr(self::NARROW_BASE + count(self::UPPER) + $t) : $c);

                continue;
            }

            if (in_array($c, self::LOWER, true)) {
                $out .= $c;     // below the base: no effect on what goes above it

                continue;
            }

            // Anything else starts a new cluster.
            $upper = null;
            $tall = in_array($c, self::TALL, true);
            $next = $chars[$i + 1] ?? null;
            $out .= isset(self::DESCLESS[$c]) && in_array($next, self::LOWER, true)
                ? self::chr(self::DESCLESS[$c])
                : $c;
        }

        return $out;
    }

    /**
     * Drop what the alert font cannot draw. Emoji above all: GD renders a missing glyph as a hollow
     * box, and a 4-byte emoji can come out as mojibake. Keeps exactly the ranges the build script
     * subsets to (minus the Latin-1 symbols Noto Sans Thai never had).
     */
    public static function fontSafe(string $text): string
    {
        $text = preg_replace('/[\x{00A4}\x{00A6}\x{00AC}\x{00AD}\x{00B1}-\x{00B3}\x{00B5}\x{00B9}\x{00BC}-\x{00BE}]/u', '', $text) ?? '';
        $text = preg_replace('/[^\n\x{0020}-\x{007E}\x{00A0}-\x{00FF}\x{0E01}-\x{0E5B}\x{2013}\x{2014}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2026}]/u', '', $text) ?? '';

        // A dropped emoji leaves its neighbouring space behind; don't let it indent the line.
        return trim(preg_replace('/[ \t]{2,}/', ' ', $text) ?? '', " \t");
    }

    private static function stacked(int $tone, int $upper, bool $tall): string
    {
        return self::chr(($tall ? self::STACK_TALL_BASE : self::STACK_BASE) + $tone * 8 + $upper);
    }

    private static function chr(int $code): string
    {
        return mb_chr($code, 'UTF-8');
    }
}
