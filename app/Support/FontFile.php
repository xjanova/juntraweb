<?php

namespace App\Support;

/**
 * Is this file actually a font?
 *
 * `storage/fonts/Sarabun-*.ttf` shipped for months as saved GitHub "Page not found" pages. Every
 * check asked only "does the file exist" — it did — so GD failed on every call and the OG image fell
 * back to a 9px bitmap font, with Thai titles coming out as mojibake; dompdf fell back to DejaVu Sans,
 * which has no Thai at all. Look at the file's signature, never just its name.
 */
final class FontFile
{
    /** TrueType (00010000 or 'true'), OpenType/CFF ('OTTO'), TrueType collection ('ttcf'). */
    private const SIGNATURES = ["\x00\x01\x00\x00", 'true', 'OTTO', 'ttcf'];

    public static function isReal(?string $path): bool
    {
        if (! $path || ! is_file($path) || ! is_readable($path)) {
            return false;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = fread($fh, 4);
        fclose($fh);

        return in_array($magic, self::SIGNATURES, true);
    }

    /**
     * The first real font among $paths, or null.
     *
     * @param  array<int,string|null>  $paths
     */
    public static function firstReal(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (self::isReal($path)) {
                return $path;
            }
        }

        return null;
    }
}
