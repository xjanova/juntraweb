<?php

namespace App\Services;

use App\Models\TarotCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Imports Tarot card images from one of two source layouts:
 *
 *  A. **Local layout** (recommended) — directory tree
 *       <source>/Major/0.png     ... 21.png         (22 Major Arcana, by Rider-Waite number)
 *       <source>/Cups/ace.png    ... king.png       (14 Cups)
 *       <source>/Wands/ace.png   ... king.png       (14 Wands)
 *       <source>/Swords/ace.png  ... king.png       (14 Swords)
 *       <source>/Pentacles/ace.png ... king.png     (14 Pentacles)
 *
 *     Folder names are matched case-insensitively. Files may be .png/.jpg/.jpeg/.webp.
 *     Minor Arcana ranks accept either the english name (`ace`, `two` … `king`)
 *     OR the number (`1` … `14`).
 *
 *  B. **Thaiprompt-Affiliate sibling layout** — flat directory with the random
 *     storage filenames captured from production. Used when juntra and Thaiprompt
 *     share a server. Only Major Arcana mapped (legacy).
 *
 * Both produce a report with counts + per-card errors.
 */
class TarotImporter
{
    /** Major Arcana slug by Rider-Waite number (0–21). */
    private const MAJOR_BY_NUMBER = [
        0  => 'fool',             1  => 'magician',     2  => 'high-priestess',
        3  => 'empress',          4  => 'emperor',      5  => 'hierophant',
        6  => 'lovers',           7  => 'chariot',      8  => 'strength',
        9  => 'hermit',           10 => 'wheel-of-fortune',
        11 => 'justice',          12 => 'hanged-man',   13 => 'death',
        14 => 'temperance',       15 => 'devil',        16 => 'tower',
        17 => 'star',             18 => 'moon',         19 => 'sun',
        20 => 'judgement',        21 => 'world',
    ];

    /** Map english rank words to numeric position 1–14. */
    private const RANK_WORDS = [
        'ace' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'page' => 11, 'knight' => 12, 'queen' => 13, 'king' => 14,
    ];

    /** Numeric rank 1–14 back to slug word for building the card slug. */
    private const RANK_SLUG = [
        1 => 'ace',   2 => 'two',   3 => 'three', 4 => 'four',  5 => 'five',
        6 => 'six',   7 => 'seven', 8 => 'eight', 9 => 'nine',  10 => 'ten',
        11 => 'page', 12 => 'knight', 13 => 'queen', 14 => 'king',
    ];

    /** Suit folder names → DB suit value. Folder lookup is case-insensitive. */
    private const SUITS = [
        'cups' => 'cups', 'cup' => 'cups',
        'wands' => 'wands', 'wand' => 'wands',
        'swords' => 'swords', 'sword' => 'swords',
        'pentacles' => 'pentacles', 'pentacle' => 'pentacles', 'coins' => 'pentacles',
    ];

    /**
     * Static fallback map — Thaiprompt Major Arcana files by name_en.
     * (Captured from production 2026-05-07. Update if Thaiprompt rotates filenames.)
     */
    private const THAIPROMPT_MAJOR = [
        'The Fool'           => 'FaIZl0coOvIVKBbCbtYutJxMWkF2f0NE1KoIv5oc.webp',
        'The Magician'       => '1Q3wb5eQpzExScL4MaZWF6EYk88ItcKvIMjP1xqd.webp',
        'The High Priestess' => 'EZy0TG6zQihDW1WY1TFdDo7aDcPw2KWkPrNEMiK0.webp',
        'The Empress'        => 'voZS2fXheO4czNH46Ux04pXmikExrvnyiBzLV4gj.webp',
        'The Emperor'        => '0eePBDIYWqzMBEtmAXd2ZMB9Kneen4lYfRPykW7f.webp',
        'The Hierophant'     => '0dv0JrgUzWON8QoURgUJmGrxVc4X9Cumwd2gmimB.webp',
        'The Lovers'         => 'mfDEee6m3Ahw4jfRl3Wae0XE8bfkKh89klrxAHus.webp',
        'The Chariot'        => 'W0s8RPNyCnckJa91JvrduoSvtGSgWd6Ssp6yPFA4.webp',
        'Strength'           => 'd5wNJJOsHH3rYeoVUcpXRBv7pgJP0HCtaHHl4kn2.webp',
        'The Hermit'         => 'XRcer1W0MSAtWvlXIKQnNQpkr4Qz1mAnU96ieY4A.webp',
        'Wheel of Fortune'   => 'affnc2qgUR7YZyzkvFpX4Y8LbqlOmZjw5fvbLjxW.webp',
        'Justice'            => 'ubwXoSAVpKQAblpCfaW5D8XMs0oNDRgnmgVEL3OG.webp',
        'The Hanged Man'     => 'fnch00EwOgaT4PdLSLN2s8XtDBE08y42dT0FgUD8.webp',
        'Death'              => 'wWXwrFXVZYmp9aEcYpMyRaji3rKDVWvAydrmpE9Y.webp',
        'Temperance'         => 'gSsgbBRPgF5PNC4paWGW3m0WkhiIkR2AUj21xrFa.webp',
        'The Devil'          => 'A0ZaiogY4saSahMoZKIkaCtXFAgffKagTdVLDoPf.webp',
        'The Tower'          => '5wJVsAY096zGZ8IxMEwE6aJ25993AYAxDPtfYQhT.webp',
        'The Star'           => 'ZZTjAWXEy4y7zmUAoUD5L0YPJzoddLRzYmRgjVn8.webp',
        'The Moon'           => 'PN6gTggArP9JQ0OW4CuhgvETrB913MjUpdUBsC52.webp',
        'The Sun'            => 'BHpCQQSZcT2oCZEirUTlNO69Lptg92AlpyH7VIU8.webp',
        'Judgement'          => '0HJEsPSd4eQfsqpVS1vYXmW8gV4Q0OrCdOUiHy77.webp',
        'The World'          => 'YHxQOWUnxO2WtKYG8ZGM3b1FFyF19NdHrj3hxccN.webp',
    ];

    public function defaultSourcePath(): string
    {
        return config('tarot.import_source')
            ?: '/home/admin/domains/main.thaiprompt.online/public_html/storage/app/public/tarot/cards';
    }

    public function defaultCardBackSourcePath(): string
    {
        return config('tarot.card_back_source')
            ?: '/home/admin/domains/main.thaiprompt.online/public_html/storage/app/public/tarot/card-backs';
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Import card-back. Picks the most-recently-modified image in $sourceDir
     * and stores it as the global tarot_card_back_path setting.
     */
    public function importCardBack(?string $sourceDir = null): array
    {
        $sourceDir = $sourceDir ?: $this->defaultCardBackSourcePath();
        $report = ['imported' => false, 'path' => null, 'error' => null];

        if (!is_dir($sourceDir)) {
            $report['error'] = "Source directory not found: $sourceDir";
            return $report;
        }

        $candidates = collect(File::files($sourceDir))
            ->filter(fn ($f) => in_array(strtolower($f->getExtension()), ['webp', 'jpg', 'jpeg', 'png']))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        if ($candidates->isEmpty()) {
            $report['error'] = "No image files in: $sourceDir";
            return $report;
        }

        $src = $candidates->first()->getPathname();
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));

        $destRel = 'tarot/card-backs/imported-' . date('Ymd-His') . '.' . $ext;
        if (!Storage::disk('public')->put($destRel, file_get_contents($src))) {
            $report['error'] = "Failed to write to public storage: $destRel";
            return $report;
        }

        \App\Models\Setting::put('tarot_card_back_path', $destRel, 'tarot', false);

        $report['imported'] = true;
        $report['path'] = $destRel;
        Log::info('TarotImporter card-back imported', $report);
        return $report;
    }

    /**
     * Local layout — scans <root>/Major + <root>/{Cups,Wands,Swords,Pentacles}.
     * The recommended way to bring all 78 face images into the system.
     *
     * Returns:
     *   ['source' => string,
     *    'imported' => N (files copied),
     *    'updated'  => N (DB rows updated),
     *    'skipped_missing' => N (cards in DB with no source file),
     *    'errors'   => [string, ...] ]
     */
    public function importFromLocalPath(string $rootDir): array
    {
        $report = [
            'source' => $rootDir,
            'imported' => 0, 'updated' => 0, 'skipped_missing' => 0,
            'by_arcana' => ['major' => 0, 'minor' => 0],
            'errors' => [],
        ];

        if (!is_dir($rootDir)) {
            $report['errors'][] = "Source directory not found: $rootDir";
            return $report;
        }

        $publicDest = public_path('images/tarot');
        if (!is_dir($publicDest)) {
            File::makeDirectory($publicDest, 0755, true);
        }

        DB::transaction(function () use ($rootDir, $publicDest, &$report) {
            // 1) Major Arcana — Major/<number>.<ext>
            $majorDir = $this->resolveSubdir($rootDir, ['Major', 'major', 'MAJOR']);
            if ($majorDir) {
                foreach (self::MAJOR_BY_NUMBER as $num => $slug) {
                    $src = $this->findImageByBasename($majorDir, (string) $num);
                    if (!$src) {
                        $report['skipped_missing']++;
                        $report['errors'][] = "Missing Major #$num ($slug)";
                        continue;
                    }
                    $this->placeCardImage($slug, $src, $publicDest, $report);
                    $report['by_arcana']['major']++;
                }
            } else {
                $report['errors'][] = "No Major/ subdirectory in $rootDir";
            }

            // 2) Minor Arcana — <Suit>/<rank>.<ext>
            $canonicalSuits = ['cups', 'wands', 'swords', 'pentacles'];
            foreach ($canonicalSuits as $suit) {
                $aliases = array_keys(array_filter(self::SUITS, fn ($v) => $v === $suit));
                $variants = [];
                foreach ($aliases as $a) {
                    $variants[] = $a;
                    $variants[] = ucfirst($a);
                    $variants[] = strtoupper($a);
                }
                $suitDir = $this->resolveSubdir($rootDir, $variants);
                if (!$suitDir) {
                    // missing suit folder is not a hard error — log per-card below
                    foreach (self::RANK_SLUG as $rankSlug) {
                        $report['skipped_missing']++;
                        $report['errors'][] = "Missing $suit folder · $rankSlug-of-$suit";
                    }
                    continue;
                }

                foreach (self::RANK_SLUG as $rankNum => $rankSlug) {
                    $candidates = [(string) $rankNum, $rankSlug, ucfirst($rankSlug)];
                    $src = null;
                    foreach ($candidates as $base) {
                        $src = $this->findImageByBasename($suitDir, $base);
                        if ($src) break;
                    }
                    if (!$src) {
                        $report['skipped_missing']++;
                        $report['errors'][] = "Missing $suit · $rankSlug ($rankNum)";
                        continue;
                    }

                    $cardSlug = "$rankSlug-of-$suit";
                    $this->placeCardImage($cardSlug, $src, $publicDest, $report);
                    $report['by_arcana']['minor']++;
                }
            }
        });

        Log::info('TarotImporter local run', [
            'source' => $rootDir,
            'imported' => $report['imported'],
            'updated' => $report['updated'],
            'skipped_missing' => $report['skipped_missing'],
            'errors' => count($report['errors']),
        ]);
        return $report;
    }

    /**
     * Legacy Thaiprompt-style flat-directory import (Major Arcana only).
     * Kept for backwards compatibility with the existing admin button.
     */
    public function importFromPath(?string $sourceDir = null): array
    {
        $sourceDir = $sourceDir ?: $this->defaultSourcePath();
        $report = ['source' => $sourceDir, 'imported' => 0, 'skipped_missing' => 0, 'updated' => 0, 'errors' => []];

        if (!is_dir($sourceDir)) {
            $report['errors'][] = "Source directory not found: $sourceDir";
            return $report;
        }

        $publicDest = public_path('images/tarot');
        if (!is_dir($publicDest)) {
            File::makeDirectory($publicDest, 0755, true);
        }

        DB::transaction(function () use ($sourceDir, $publicDest, &$report) {
            foreach (self::THAIPROMPT_MAJOR as $nameEn => $filename) {
                $src = rtrim($sourceDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
                if (!is_file($src)) {
                    $report['skipped_missing']++;
                    $report['errors'][] = "Missing on source: $nameEn → $filename";
                    continue;
                }

                $card = TarotCard::where('name_en', $nameEn)->first();
                if (!$card) {
                    $report['errors'][] = "Card not in DB: $nameEn";
                    continue;
                }

                $destFilename = $card->slug . '.webp';
                $destPath = $publicDest . DIRECTORY_SEPARATOR . $destFilename;

                if (!@copy($src, $destPath)) {
                    $report['errors'][] = "Copy failed for $nameEn ($src → $destPath)";
                    continue;
                }

                $card->image_path = "images/tarot/$destFilename";
                $card->save();

                $report['imported']++;
                $report['updated']++;
            }
        });

        Log::info('TarotImporter run', $report);
        return $report;
    }

    /* =========================================================================
       INTERNAL HELPERS
       ========================================================================= */

    /** Locate `<rootDir>/<one of $names>` (case-insensitive); return abs path or null. */
    private function resolveSubdir(string $rootDir, array $names): ?string
    {
        foreach ($names as $n) {
            $abs = rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR . $n;
            if (is_dir($abs)) {
                return $abs;
            }
        }
        // case-insensitive scan as final fallback
        foreach (@scandir($rootDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $abs = $rootDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($abs)) continue;
            foreach ($names as $n) {
                if (strcasecmp($entry, $n) === 0) {
                    return $abs;
                }
            }
        }
        return null;
    }

    /**
     * Find a file in $dir whose basename (without extension) matches $base
     * exactly, case-insensitively. Accepts png/jpg/jpeg/webp. Returns abs path or null.
     */
    private function findImageByBasename(string $dir, string $base): ?string
    {
        $exts = ['png', 'jpg', 'jpeg', 'webp', 'PNG', 'JPG', 'JPEG', 'WEBP'];
        foreach ($exts as $ext) {
            $abs = $dir . DIRECTORY_SEPARATOR . $base . '.' . $ext;
            if (is_file($abs)) return $abs;
        }
        // case-insensitive scan of the directory
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $abs = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($abs)) continue;
            $info = pathinfo($entry);
            $ext = strtolower($info['extension'] ?? '');
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) continue;
            if (strcasecmp($info['filename'] ?? '', $base) === 0) {
                return $abs;
            }
        }
        return null;
    }

    /**
     * Copy $src → public/images/tarot/<slug>.<ext> and update the matching
     * tarot_cards row. Updates $report counters in place.
     */
    private function placeCardImage(string $cardSlug, string $src, string $publicDest, array &$report): void
    {
        $card = TarotCard::where('slug', $cardSlug)->first();
        if (!$card) {
            $report['errors'][] = "Card not in DB: $cardSlug";
            return;
        }

        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'png';
        $destFilename = $cardSlug . '.' . $ext;
        $destPath = $publicDest . DIRECTORY_SEPARATOR . $destFilename;

        if (!@copy($src, $destPath)) {
            $report['errors'][] = "Copy failed: $cardSlug ($src → $destPath)";
            return;
        }

        $card->image_path = "images/tarot/$destFilename";
        $card->save();

        $report['imported']++;
        $report['updated']++;
    }
}
