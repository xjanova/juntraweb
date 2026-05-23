<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Auto-derive zodiac slugs from a user's birth date.
 *
 * Slugs match the seeded rows in `zodiacs` / `chinese_zodiacs` so the
 * Profile→Zodiac relation works without an extra mapping table.
 */
class Astrology
{
    /**
     * Western zodiac sign from month+day (Tropical, Gregorian).
     * Boundaries match the seeded `date_range` strings in ZodiacSeeder.
     */
    public static function westernZodiacSlug(?Carbon $birth): ?string
    {
        if (!$birth) return null;
        $m = (int) $birth->month;
        $d = (int) $birth->day;

        return match (true) {
            ($m === 3  && $d >= 21) || ($m === 4  && $d <= 19) => 'aries',
            ($m === 4  && $d >= 20) || ($m === 5  && $d <= 20) => 'taurus',
            ($m === 5  && $d >= 21) || ($m === 6  && $d <= 20) => 'gemini',
            ($m === 6  && $d >= 21) || ($m === 7  && $d <= 22) => 'cancer',
            ($m === 7  && $d >= 23) || ($m === 8  && $d <= 22) => 'leo',
            ($m === 8  && $d >= 23) || ($m === 9  && $d <= 22) => 'virgo',
            ($m === 9  && $d >= 23) || ($m === 10 && $d <= 22) => 'libra',
            ($m === 10 && $d >= 23) || ($m === 11 && $d <= 21) => 'scorpio',
            ($m === 11 && $d >= 22) || ($m === 12 && $d <= 21) => 'sagittarius',
            ($m === 12 && $d >= 22) || ($m === 1  && $d <= 19) => 'capricorn',
            ($m === 1  && $d >= 20) || ($m === 2  && $d <= 18) => 'aquarius',
            ($m === 2  && $d >= 19) || ($m === 3  && $d <= 20) => 'pisces',
            default => null,
        };
    }

    /**
     * Chinese zodiac from CE year. The 12-year cycle anchors so that
     * 1900 = Rat — every year y maps to slug index ((y - 1900) mod 12).
     * Note: this uses the Gregorian-year approximation, not the Lunar
     * New Year boundary. Good enough for general display; users born in
     * Jan–Feb can override manually in the form.
     */
    public static function chineseZodiacSlug(?int $year): ?string
    {
        if (!$year) return null;
        $slugs = ['rat','ox','tiger','rabbit','dragon','snake',
                  'horse','goat','monkey','rooster','dog','pig'];
        $idx = (($year - 1900) % 12 + 12) % 12;
        return $slugs[$idx] ?? null;
    }
}
