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
        // Two kill-switches make a service free (cost 0) regardless of its
        // stored price. Every controller already guards `if ($cost > 0)` before
        // debiting, so returning 0 here cleanly disables charging end-to-end —
        // no per-controller change needed. The price value is preserved so the
        // operator can flip charging back on without re-typing it.
        if (!self::billingEnabled() || !self::chargeEnabled($feature)) {
            return 0.0;
        }

        $val = Setting::get("pricing_$feature");
        if ($val !== null && $val !== '' && is_numeric($val)) {
            // Clamp negatives to 0 — a negative price would make debit() throw
            // InvalidArgumentException and 500 the whole reading/chat request.
            return max(0.0, (float) $val);
        }
        return max(0.0, (float) config("pricing.$feature", 0));
    }

    /**
     * Master switch — when the operator turns billing off site-wide, every
     * feature becomes free. Defaults to ON (charge) when the Setting is unset.
     */
    public static function billingEnabled(): bool
    {
        return self::flag('billing_enabled');
    }

    /**
     * Per-feature charge switch (`Setting('pricing_<feature>_enabled')`).
     * Defaults to ON so existing installs keep charging until an admin opts a
     * specific service out.
     */
    public static function chargeEnabled(string $feature): bool
    {
        return self::flag("pricing_{$feature}_enabled");
    }

    /** True when a feature currently costs nothing (switched off or price 0). */
    public static function isFree(string $feature): bool
    {
        return self::for($feature) <= 0;
    }

    /** Read a boolean-ish Setting flag, treating "unset" as true (enabled). */
    private static function flag(string $key): bool
    {
        $v = Setting::get($key);
        if ($v === null || $v === '') {
            return true;
        }
        return $v === '1' || $v === 1 || $v === true || $v === 'true';
    }

    public static function format(float $amount): string
    {
        return '฿' . number_format($amount, 2);
    }

    /** Map of feature key → human label (Thai). */
    public static function labels(): array
    {
        return [
            'tarot_single'   => 'เปิดไพ่ 1 ใบ',
            'tarot_three'    => 'เปิดไพ่ 3 ใบ',
            'tarot_love'     => 'เปิดไพ่ความรัก 5 ใบ',
            'tarot_career'   => 'เปิดไพ่การงาน-การเงิน 5 ใบ',
            'tarot_decision' => 'เปิดไพ่ทางแยก 5 ใบ',
            'tarot_celtic'   => 'เปิดไพ่ Celtic Cross 10 ใบ',
            'tarot_year'     => 'เปิดไพ่พยากรณ์ 12 เดือน',
            'numerology'     => 'ดวงเลขศาสตร์',
            'palmistry'    => 'ดูลายมือ',
            'auspicious'   => 'หาฤกษ์ยาม',
            'chat_message' => 'สนทนากับแม่หมอ (ต่อข้อความ)',
        ];
    }
}
