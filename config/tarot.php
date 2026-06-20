<?php

/**
 * Tarot import sources.
 *
 * Used by App\Services\TarotImporter and the "Import 78 ใบ จากเครื่องนี้"
 * action in the Filament admin.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | local_import_path
    |--------------------------------------------------------------------------
    | Default root directory shown in the Filament admin's local-import form.
    | Set TAROT_LOCAL_IMPORT_PATH in .env on each environment so the dev
    | machine doesn't ship a Windows-only path to production.
    |
    | The directory should contain:
    |   <root>/Major/0.png … 21.png
    |   <root>/Cups/ace.png … king.png
    |   <root>/Wands/ace.png … king.png
    |   <root>/Swords/ace.png … king.png
    |   <root>/Pentacles/ace.png … king.png
    */
    'local_import_path' => env('TAROT_LOCAL_IMPORT_PATH', ''),

    /*
    | Thaiprompt-Affiliate sibling layout (flat directory of webp files with
    | random storage filenames). importFromPath() reads each card's CURRENT
    | filename live from the Thaiprompt DB (below), then copies from this dir.
    | Default is the Thaiprompt storage path on the shared DirectAdmin server.
    */
    'import_source' => env('TAROT_IMPORT_SOURCE'),

    /*
    |--------------------------------------------------------------------------
    | Thaiprompt live import (API-driven, rotation-proof)
    |--------------------------------------------------------------------------
    | importFromPath() GETs the {name_en, image_url} catalog from Thaiprompt's
    | juntra API (path below, on the configured `thaiprompt_base_url` Setting —
    | the same base FortuneBotClient/MlmApiClient use), then copies each file
    | from the shared filesystem (import_source) — falling back to an HTTPS
    | download of image_url when the local file isn't reachable (different host
    | / local dev). No DB credentials for the sibling site are needed.
    */
    'thaiprompt_catalog_path' => env('TAROT_THAIPROMPT_CATALOG_PATH', '/api/v1/juntra/tarot/cards'),

    // Optional override for the host the HTTP image-download fallback is allowed
    // to fetch from (SSRF guard — a tampered image_url elsewhere is refused).
    // Leave unset to reuse the SAME host as the catalog API (the thaiprompt_base_url
    // Setting) so the allowlist and the catalog source can never silently diverge;
    // set this only when card art is served from a different host (e.g. a CDN).
    'thaiprompt_base_url'   => env('TAROT_THAIPROMPT_BASE_URL'),

    // Allow downloading image_url over HTTPS when the shared file is missing.
    'allow_http_fallback'   => env('TAROT_IMPORT_HTTP_FALLBACK', true),

    /*
    | Card-back source dir (also typically on the Thaiprompt sibling server).
    */
    'card_back_source' => env('TAROT_CARD_BACK_SOURCE'),
];
