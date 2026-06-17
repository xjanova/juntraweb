<?php

/**
 * SMS Checker payment gateway (thaiprompt-smschecker-v1 protocol).
 *
 * The juntra SMS Checker Android app reads bank SMS, encrypts each one
 * (AES-256-GCM + HMAC), and POSTs to /api/v1/sms-payment/notify. The server
 * matches the credited amount to a reserved pending wallet top-up and credits
 * the wallet automatically — no admin slip approval needed.
 */
return [
    // Master switch. When off, top-ups don't reserve a unique amount and the
    // /notify endpoint still records but never auto-credits.
    'enabled' => (bool) env('SMSCHECKER_ENABLED', true),

    // 'auto'   → credit the wallet the moment an SMS matches a reserved top-up.
    // 'manual' → record the match but leave the top-up for admin approval.
    'default_approval_mode' => env('SMSCHECKER_APPROVAL_MODE', 'auto'),

    // Reject a /notify whose X-Timestamp is older than this (replay/skew guard).
    'timestamp_window_seconds' => (int) env('SMSCHECKER_TS_WINDOW', 300), // 5 min

    // How long a used nonce is remembered (replay protection).
    'nonce_ttl_seconds' => (int) env('SMSCHECKER_NONCE_TTL', 900),

    // The satang suffix range used to make a top-up amount unique for matching
    // (e.g. ฿100 → ฿100.37). 1..99 gives 99 concurrent slots per base amount.
    'unique_suffix_min' => 1,
    'unique_suffix_max' => 99,
];
