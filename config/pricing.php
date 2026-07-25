<?php

/**
 * Per-feature credit cost (THB).
 *
 * Admin can override any value via Setting('pricing_<feature>') from the
 * Filament settings page; if no Setting exists we fall back to these
 * defaults. Treat 1 credit == 1 THB internally so the wallet UI reads
 * naturally to Thai users.
 */
return [
    'currency' => env('PRICING_CURRENCY', 'THB'),

    // Tarot — one key per spread in config/tarot_spreads.php.
    // Admin can override any of these live via Setting('pricing_<key>').
    'tarot_single'   => env('PRICING_TAROT_SINGLE', 9),
    'tarot_three'    => env('PRICING_TAROT_THREE', 19),
    'tarot_love'     => env('PRICING_TAROT_LOVE', 39),
    'tarot_career'   => env('PRICING_TAROT_CAREER', 39),
    'tarot_decision' => env('PRICING_TAROT_DECISION', 39),
    'tarot_celtic'   => env('PRICING_TAROT_CELTIC', 99),
    'tarot_year'     => env('PRICING_TAROT_YEAR', 129),

    // Other readings
    'numerology' => env('PRICING_NUMEROLOGY', 9),
    'palmistry'  => env('PRICING_PALMISTRY', 29),
    'auspicious' => env('PRICING_AUSPICIOUS', 19),

    // ดูดวงเชิงลึก — แพ็กเดียวกับที่บอท FB/LINE ขายอยู่ (READING_TYPE_DEEP)
    // ราคาต้องตรงกับฝั่งบอท ไม่งั้นลูกค้าคนเดียวกันเจอสองราคาคนละช่องทาง
    'deep' => env('PRICING_DEEP', 39),

    // AI chat — debited per outbound user message (the bot reply is free).
    // Set to 0 to disable per-message billing.
    'chat_message' => env('PRICING_CHAT_MESSAGE', 2),

    // Top-up
    'topup_bundles' => [50, 100, 200, 500, 1000, 2000],
    'min_topup'     => env('PRICING_MIN_TOPUP', 20),
    'max_topup'     => env('PRICING_MAX_TOPUP', 50000),

    // A pending top-up (slip awaiting admin approval) auto-expires after this
    // many hours so abandoned requests + their slip images don't accumulate.
    // Cleaned by `php artisan wallet:cleanup-expired-topups` (scheduled hourly).
    'topup_pending_ttl_hours' => env('PRICING_TOPUP_TTL_HOURS', 48),

    // Max pending top-ups a single user may have open at once (anti-spam).
    'max_pending_topups' => env('PRICING_MAX_PENDING_TOPUPS', 5),

    // Static PromptPay info (operator can override via .env or Setting)
    'promptpay_id'   => env('PROMPTPAY_ID', ''),         // e.g. 0812345678 or 1101700000000
    'promptpay_name' => env('PROMPTPAY_NAME', ''),
];
