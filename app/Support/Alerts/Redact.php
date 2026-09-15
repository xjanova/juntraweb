<?php

namespace App\Support\Alerts;

use App\Models\Setting;
use Throwable;

/**
 * Scrub text before it leaves the server in an alert. Error messages are the dangerous ones: a
 * Guzzle failure quotes the full URL (API keys ride in query strings), a PDO error quotes the SQL
 * and its values, and all of it was written for a log file nobody else reads — not for a chat app
 * on a phone.
 */
final class Redact
{
    public static function text(string $text): string
    {
        // Every secret we hold, by value — whatever form the message quotes it in.
        foreach (self::secrets() as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        $patterns = [
            // key=value pairs in URLs, query strings and config dumps
            '~((?:api[_-]?key|apikey|access[_-]?token|token|secret|password|passwd|pwd|signature|sig|auth|key)=)[^&\s"\']+~i' => '$1***',
            '#(Bearer\s+)[A-Za-z0-9._~+/=-]{8,}#i' => '$1***',
            '~bot\d{5,}:[A-Za-z0-9_-]{20,}~' => 'bot***',                     // Telegram bot tokens
            '~://([^/\s:@]+):([^/\s@]+)@~' => '://$1:***@',                    // user:pass@host
            // e-mail addresses: keep enough to recognise, not enough to harvest
            '~\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b~' => '$1***@$2',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }

    /** @return array<int,string> secret values worth hunting for, longest first */
    private static function secrets(): array
    {
        $values = [
            (string) config('app.key'),
            (string) config('database.connections.' . config('database.default') . '.password'),
            (string) config('mail.mailers.smtp.password'),
        ];
        try {
            // Every setting stored encrypted is a secret by definition (bot token, OAuth client
            // secret, API keys) — plus the OAuth client secret, which older installs kept in clear.
            $keys = Setting::where('is_encrypted', true)->pluck('key')->push('thaiprompt_client_secret')->unique();
            foreach ($keys as $key) {
                $values[] = (string) Setting::get($key, '');
            }
        } catch (Throwable) {
            // settings table unreachable (the error may BE the database) — patterns still apply
        }

        // Short strings would shred ordinary words; nothing real here is under 8 characters.
        $values = array_values(array_unique(array_filter($values, fn ($v) => strlen($v) >= 8)));
        usort($values, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $values;
    }
}
