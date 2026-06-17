<?php

namespace App\Support;

/**
 * Render arbitrary text as an inline SVG QR data URI (no gd needed). Used for
 * the SMS Checker device-setup QR. Returns null if the QR lib is unavailable
 * so callers can fall back to showing the raw config text.
 */
class QrImage
{
    public static function svgDataUri(string $text): ?string
    {
        if ($text === '' || ! class_exists(\Endroid\QrCode\Writer\SvgWriter::class)) {
            return null;
        }
        try {
            return (new \Endroid\QrCode\Writer\SvgWriter())
                ->write(new \Endroid\QrCode\QrCode($text))
                ->getDataUri();
        } catch (\Throwable) {
            return null;
        }
    }
}
