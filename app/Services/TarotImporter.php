<?php

namespace App\Services;

use App\Models\TarotCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Imports Tarot card images from a sibling Laravel project (Thaiprompt-Affiliate)
 * that lives on the same server.
 *
 * Both projects use Laravel storage layout, so the images sit at:
 *   <other-project>/storage/app/public/tarot/cards/<random>.webp
 *
 * We copy them into our own:
 *   <self>/storage/app/public/tarot/cards/<our-slug>.webp
 *
 * and update the tarot_cards.image_path column to the public URL.
 *
 * Lookup is by `name_en` (e.g. "The Fool" → matches our slug "fool" by the canonical
 * map below). The other project queries Thaiprompt's MySQL DB if available;
 * otherwise it falls back to a static name→file map embedded here.
 */
class TarotImporter
{
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

    /**
     * Default Thaiprompt source path on the shared DirectAdmin server.
     * Override via env('TAROT_IMPORT_SOURCE') if the path moves.
     */
    public function defaultSourcePath(): string
    {
        return config('tarot.import_source')
            ?: '/home/admin/domains/main.thaiprompt.online/public_html/storage/app/public/tarot/cards';
    }

    /** Default card-back source dir (also on the same DA server). */
    public function defaultCardBackSourcePath(): string
    {
        return config('tarot.card_back_source')
            ?: '/home/admin/domains/main.thaiprompt.online/public_html/storage/app/public/tarot/card-backs';
    }

    /**
     * Copy the first card-back image from a Thaiprompt-style source dir into our
     * public storage and store its path in the `tarot_card_back_path` setting.
     *
     * Returns ['imported' => bool, 'path' => string|null, 'error' => string|null].
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
     * Copy + map images from a source dir into our public storage and update DB.
     *
     * Returns ['imported' => N, 'skipped_missing' => N, 'updated' => N, 'errors' => [..]]
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
}
