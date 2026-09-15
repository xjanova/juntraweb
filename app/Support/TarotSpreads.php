<?php

namespace App\Support;

/**
 * Read-only accessor over config/tarot_spreads.php.
 *
 * Everything tarot-spread-shaped (landing page, pick fan, controller,
 * result page, AI prompt) goes through here so there is exactly ONE place
 * that knows what spreads exist and how many cards each needs.
 */
class TarotSpreads
{
    /**
     * Spreads on sale, keyed by spread key, in registry order.
     *
     * A spread with `visible_setting` is left out until that Setting is '1' —
     * a new package (คุณไสย) stays unsellable everywhere (landing page, pick,
     * cast validation, chat offers, app) until the owner approves its sample
     * readings, and one admin switch turns it on.
     */
    public static function all(): array
    {
        return array_filter(
            static::registry(),
            fn (array $meta) => empty($meta['visible_setting'])
                || \App\Models\Setting::get($meta['visible_setting']) === '1',
        );
    }

    /** Every registered spread, including ones not on sale yet (admin pricing, history pages). */
    public static function registry(): array
    {
        return config('tarot_spreads', []);
    }

    /** True when the pick page should offer the optional birth date for this spread. */
    public static function wantsBirthDate(string $key): bool
    {
        return (bool) (static::get($key)['birth'] ?? false);
    }

    /** Spread keys only, e.g. ['single','three',...]. */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    /**
     * Known spread (on sale or not). Selling paths validate against keys()
     * (on sale only); has()/get() also answer for hidden spreads so a reading
     * bought while a package was on sale still renders after it is hidden.
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, static::registry());
    }

    /** Full meta array for one spread (on sale or not), or null. */
    public static function get(string $key): ?array
    {
        return static::registry()[$key] ?? null;
    }

    /** Number of cards the spread needs (== number of positions). */
    public static function cardCount(string $key): int
    {
        return count(static::get($key)['positions'] ?? []);
    }

    /** Ordered position metas: [['label'=>..,'asks'=>..], ...]. */
    public static function positions(string $key): array
    {
        return static::get($key)['positions'] ?? [];
    }

    /** Ordered position labels only. */
    public static function positionLabels(string $key): array
    {
        return array_map(fn ($p) => $p['label'], static::positions($key));
    }

    /** Pricing feature key for the spread (falls back to a derived key). */
    public static function priceKey(string $key): string
    {
        return static::get($key)['price_key'] ?? "tarot_{$key}";
    }

    public static function layout(string $key): string
    {
        return static::get($key)['layout'] ?? 'grid';
    }

    /* ----- Reading.type <-> spread key bridge ----- */

    /** "tarot_celtic" -> "celtic". Returns null if not a known tarot spread. */
    public static function keyFromType(string $type): ?string
    {
        if (! str_starts_with($type, 'tarot_')) {
            return null;
        }
        $key = substr($type, strlen('tarot_'));
        return static::has($key) ? $key : null;
    }

    /** "celtic" -> "tarot_celtic". */
    public static function typeFromKey(string $key): string
    {
        return "tarot_{$key}";
    }

    /** True when a Reading.type belongs to any tarot spread. */
    public static function isTarotType(string $type): bool
    {
        return static::keyFromType($type) !== null;
    }

    /** Human display name for a Reading.type, e.g. "ไพ่ความรัก / เนื้อคู่". */
    public static function nameForType(string $type): ?string
    {
        $key = static::keyFromType($type);
        return $key ? (static::get($key)['name_th'] ?? null) : null;
    }
}
