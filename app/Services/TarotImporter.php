<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\TarotCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
 *  B. **Thaiprompt-Affiliate live import** — GETs the {name_en, image_url}
 *     catalog from Thaiprompt's own juntra API (`/api/v1/juntra/tarot/cards`),
 *     joins on name_en, then copies each file from the shared filesystem
 *     (or downloads image_url over HTTPS as a fallback). Covers all 78 cards and
 *     is immune to Laravel's random storage-filename rotation — Thaiprompt
 *     resolves its own current filenames server-side, so juntra holds no DB
 *     credentials for the sibling site.
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

    /** Reject any image payload larger than this (defense-in-depth on the download path).
     *  Real card webp files are ~150–200 KB, so 5 MB is a generous ceiling. */
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

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
     * Import card faces from the sibling Thaiprompt-Affiliate site (all 78 cards).
     *
     * GETs the {name_en, image_url} catalog from Thaiprompt's own juntra API
     * (`/api/v1/juntra/tarot/cards`) keyed by name_en, so it is immune to
     * Laravel's random storage-filename rotation — Thaiprompt resolves its own
     * current filenames server-side. For every card it copies the file from the
     * shared filesystem ($sourceDir, same-server, fast) and falls back to an
     * HTTPS download of the returned image_url when the local file is unavailable
     * (different host / local dev). The HTTP fallback only fetches from the
     * configured Thaiprompt host (SSRF guard).
     *
     * @param string|null $sourceDir Filesystem base for the shared-disk copy
     *                               (defaults to defaultSourcePath()).
     */
    public function importFromPath(?string $sourceDir = null): array
    {
        $sourceDir = $sourceDir ?: $this->defaultSourcePath();
        $report = ['source' => $sourceDir, 'remote_total' => 0, 'imported' => 0, 'skipped_missing' => 0, 'updated' => 0, 'errors' => []];

        // Deliberate batch (up to 78 images); don't let the PHP time limit abort mid-run.
        @set_time_limit(0);

        // 1) Pull the live name_en → image_url catalog from Thaiprompt's juntra API.
        $remoteRows = $this->fetchThaipromptCatalog($report);
        if ($remoteRows === null) {
            return $report; // fetch failed — $report already carries the reason
        }
        if ($remoteRows->isEmpty()) {
            $report['errors'][] = 'Thaiprompt ส่ง catalog ไพ่ว่างเปล่า — ไม่มีอะไรให้นำเข้า';
            return $report;
        }
        $report['remote_total'] = $remoteRows->count();

        // 2) Index juntra cards by lower-cased name_en for an exact join.
        $localByName = TarotCard::all()->keyBy(fn ($c) => mb_strtolower(trim((string) $c->name_en)));

        $publicDest = public_path('images/tarot');
        if (!is_dir($publicDest)) {
            File::makeDirectory($publicDest, 0755, true);
        }

        $allowHttp   = (bool) config('tarot.allow_http_fallback', true);
        $allowedHost = $this->thaipromptHost();

        // No wrapping DB transaction: this loop does blocking file/network I/O, and
        // the import is naturally idempotent + partial-success (each card commits on
        // its own). A transaction here would only hold row locks on the public
        // tarot_cards table across that I/O for zero atomicity benefit.
        foreach ($remoteRows as $row) {
            $nameEn = trim((string) ($row->name_en ?? ''));
            if ($nameEn === '') {
                $report['errors'][] = 'แถวจาก API ไม่มีชื่อไพ่ (name_en) — ข้าม';
                continue;
            }
            $card = $localByName->get(mb_strtolower($nameEn));
            if (!$card) {
                $report['errors'][] = "ไม่พบไพ่ในฐานข้อมูล juntra: $nameEn";
                continue;
            }

            $imageUrl = trim((string) ($row->image_url ?? ''));
            if ($imageUrl === '') {
                $report['skipped_missing']++;
                $report['errors'][] = "Thaiprompt ไม่มี image_url: $nameEn";
                continue;
            }

            // Safe basename + whitelisted extension from the URL path.
            $basename = basename((string) parse_url($imageUrl, PHP_URL_PATH));
            $ext      = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['webp', 'png', 'jpg', 'jpeg'], true)) {
                $ext = 'webp';
            }

            // a) Prefer the shared filesystem (same-server, fast).
            $bytes = null;
            if ($basename !== '') {
                $localSrc = rtrim($sourceDir, '/\\') . DIRECTORY_SEPARATOR . $basename;
                if (is_file($localSrc)) {
                    $bytes = @file_get_contents($localSrc);
                }
            }

            // b) Fall back to an HTTPS download of image_url (SSRF-guarded).
            if ($bytes === null || $bytes === false) {
                if (!$allowHttp) {
                    $report['skipped_missing']++;
                    $report['errors'][] = "ไฟล์ไม่อยู่บนดิสก์ (ปิด HTTP fallback): $nameEn → $basename";
                    continue;
                }
                if (!$this->urlHostAllowed($imageUrl, $allowedHost)) {
                    $report['errors'][] = "ปฏิเสธ image_url นอกโดเมน Thaiprompt ($nameEn): " . parse_url($imageUrl, PHP_URL_HOST);
                    continue;
                }
                try {
                    // withoutRedirecting(): the host allowlist only vetted the INITIAL
                    // URL — following a 30x could land on an internal host (SSRF).
                    $resp = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->get($imageUrl);
                    if ($resp->redirect()) {
                        $report['skipped_missing']++;
                        $report['errors'][] = "ปฏิเสธ redirect จาก image_url ($nameEn): {$resp->status()}";
                        continue;
                    }
                    if (!$resp->successful()) {
                        $report['skipped_missing']++;
                        $report['errors'][] = "ดาวน์โหลดไม่สำเร็จ ({$resp->status()}): $nameEn";
                        continue;
                    }
                    $bytes = $resp->body();
                } catch (\Throwable $e) {
                    $report['skipped_missing']++;
                    $report['errors'][] = "ดาวน์โหลดผิดพลาด ($nameEn): " . $e->getMessage();
                    continue;
                }
            }

            // Validate the payload before it lands under the public web root.
            if ($bytes === null || $bytes === false || strlen($bytes) === 0) {
                $report['skipped_missing']++;
                $report['errors'][] = "ไฟล์ภาพว่างเปล่า: $nameEn";
                continue;
            }
            if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
                $report['errors'][] = "ไฟล์ใหญ่เกินกำหนด ($nameEn): " . round(strlen($bytes) / 1048576, 1) . ' MB';
                continue;
            }
            if (@getimagesizefromstring($bytes) === false) {
                $report['errors'][] = "ไฟล์ไม่ใช่รูปภาพที่ถูกต้อง ($nameEn)";
                continue;
            }

            $destFilename = $card->slug . '.' . $ext;
            $destPath     = $publicDest . DIRECTORY_SEPARATOR . $destFilename;

            if (@file_put_contents($destPath, $bytes) === false) {
                $report['errors'][] = "เขียนไฟล์ไม่สำเร็จ ($nameEn): $destPath";
                continue;
            }

            $card->image_path = "images/tarot/$destFilename";
            $card->save();

            $report['imported']++;
            $report['updated']++;
        }

        Log::info('TarotImporter thaiprompt DB run', [
            'source' => $sourceDir,
            'imported' => $report['imported'],
            'updated' => $report['updated'],
            'skipped_missing' => $report['skipped_missing'],
            'errors' => count($report['errors']),
        ]);

        return $report;
    }

    /* =========================================================================
       INTERNAL HELPERS
       ========================================================================= */

    /**
     * GET the {name_en, image_url} card catalog from Thaiprompt's juntra API.
     * The endpoint is public (card art is public), so no token is attached —
     * the importer runs as an admin/CLI action with no end-user context.
     *
     * Returns a Collection of stdClass rows ({name_en, image_url}), or null when
     * the call fails (the reason is pushed onto $report['errors']).
     */
    private function fetchThaipromptCatalog(array &$report): ?Collection
    {
        // Same base URL all juntra→Thaiprompt API clients use (FortuneBot, MLM).
        $base = rtrim((string) Setting::get('thaiprompt_base_url', ''), '/');
        if ($base === '') {
            $report['errors'][] = 'ยังไม่ได้ตั้งค่า URL ของ Thaiprompt — ตั้งที่ /admin → ตั้งค่า Thaiprompt ก่อน';
            return null;
        }
        $url = $base . config('tarot.thaiprompt_catalog_path', '/api/v1/juntra/tarot/cards');

        try {
            // withoutRedirecting(): never chase a 30x to an unexpected host (SSRF).
            $resp = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->withoutRedirecting()
                ->get($url);
        } catch (\Throwable $e) {
            $report['errors'][] = 'เรียก API ไพ่ของ Thaiprompt ไม่สำเร็จ — ' . $e->getMessage();
            Log::warning('TarotImporter catalog fetch threw', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }

        if ($resp->redirect()) {
            $report['errors'][] = "API ไพ่ Thaiprompt ตอบ redirect ({$resp->status()}) — ปฏิเสธเพื่อความปลอดภัย";
            return null;
        }
        if (!$resp->successful()) {
            $report['errors'][] = "API ไพ่ Thaiprompt ตอบ HTTP {$resp->status()} — ตรวจว่า endpoint /api/v1/juntra/tarot/cards พร้อมใช้งานฝั่ง Thaiprompt";
            Log::warning('TarotImporter catalog fetch non-200', ['url' => $url, 'status' => $resp->status()]);
            return null;
        }

        // Accept the juntra API envelope {data: [...]} OR a bare [...] array.
        $rows = $resp->json('data');
        if (!is_array($rows)) {
            $rows = $resp->json();
        }
        if (!is_array($rows)) {
            $report['errors'][] = 'API ไพ่ Thaiprompt ตอบรูปแบบไม่ถูกต้อง (ไม่ใช่ JSON array)';
            Log::warning('TarotImporter catalog bad shape', ['url' => $url]);
            return null;
        }

        // Normalise each row to an object so the import loop can use ->name_en / ->image_url.
        return collect($rows)->map(fn ($r) => (object) (array) $r);
    }

    /** Host that the HTTP fallback is allowed to fetch from (from config base URL). */
    private function thaipromptHost(): ?string
    {
        $base = (string) config('tarot.thaiprompt_base_url', '');
        $host = $base !== '' ? parse_url($base, PHP_URL_HOST) : null;

        return $host ? strtolower($host) : null;
    }

    /** True only for http(s) URLs whose host equals the allowed Thaiprompt host (SSRF guard). */
    private function urlHostAllowed(string $url, ?string $allowedHost): bool
    {
        if (!$allowedHost) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($scheme, ['http', 'https'], true) && $host === $allowedHost;
    }

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
