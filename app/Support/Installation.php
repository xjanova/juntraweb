<?php

namespace App\Support;

class Installation
{
    public static function flagPath(): string
    {
        return storage_path('app/.installed');
    }

    public static function isInstalled(): bool
    {
        return is_file(self::flagPath());
    }

    public static function markInstalled(): void
    {
        @mkdir(dirname(self::flagPath()), 0775, true);
        file_put_contents(self::flagPath(), now()->toIso8601String() . PHP_EOL);
    }

    public static function reset(): void
    {
        @unlink(self::flagPath());
    }
}
