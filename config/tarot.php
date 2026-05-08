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
    | Legacy Thaiprompt-Affiliate sibling layout (flat directory of webp
    | files with random storage filenames). Used by importFromPath().
    */
    'import_source' => env('TAROT_IMPORT_SOURCE'),

    /*
    | Card-back source dir (also typically on the Thaiprompt sibling server).
    */
    'card_back_source' => env('TAROT_CARD_BACK_SOURCE'),
];
