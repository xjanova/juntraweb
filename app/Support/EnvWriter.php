<?php

namespace App\Support;

class EnvWriter
{
    /**
     * Update or insert key=value pairs in .env (creating it from .env.example if missing).
     * Values containing spaces, # or = are wrapped in double quotes.
     */
    public static function set(array $pairs): void
    {
        $path = base_path('.env');
        if (!is_file($path)) {
            $sample = base_path('.env.example');
            if (is_file($sample)) {
                copy($sample, $path);
            } else {
                file_put_contents($path, '');
            }
        }

        $contents = file_get_contents($path) ?: '';
        $lines = preg_split("/\r\n|\n|\r/", $contents);

        foreach ($pairs as $key => $value) {
            $formatted = self::format($value);
            $found = false;
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
                    $lines[$i] = $key . '=' . $formatted;
                    $found = true;
                    break;
                }
                // commented-out form: # KEY=...
                if (preg_match('/^\s*#\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
                    $lines[$i] = $key . '=' . $formatted;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $lines[] = $key . '=' . $formatted;
            }
        }

        file_put_contents($path, implode(PHP_EOL, $lines));
    }

    private static function format(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (preg_match('/[\s"\'#=]/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }
}
