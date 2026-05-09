<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Resolve credit cost for a feature with a Setting → config fallback.
 *
 * Reads `Setting('pricing_<feature>')` first so the admin can adjust
 * pricing live from /admin without redeploying. Falls back to the
 * hardcoded value in config/pricing.php (which is itself .env-driven).
 */
class Pricing
{
    public static function for(string $feature): float
    {
        $val = Setting::get("pricing_$feature");
        if ($val !== null && $val !== '' && is_numeric($val)) {
            return (float) $val;
        }
        return (float) config("pricing.$feature", 0);
    }

    public static function format(float $amount): string
    {
        return '฿' . number_format($amount, 2);
    }

    /** Map of feature key → human label (Thai). */
    public static function labels(): array
    {
        return [
            'tarot_three'  => 'เปิดไพ่ 3 ใบ',
            'tarot_celtic' => 'เปิดไพ่ Celtic Cross',
            'numerology'   => 'ดวงเลขศาสตร์',
            'palmistry'    => 'ดูลายมือ',
            'auspicious'   => 'หาฤกษ์ยาม',
            'chat_message' => 'สนทนากับแม่หมอ (ต่อข้อความ)',
        ];
    }
}
