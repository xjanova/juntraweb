<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'is_encrypted'];

    protected $casts = ['is_encrypted' => 'boolean'];

    /**
     * Values already read in THIS request. A page asks for the same key over and over (the theme is
     * read for every Blade view Filament renders — 2,000+ times on the members table), and without
     * this every one of those was a round trip to the cache store, which on production is the
     * database. Scoped to the current application instance so tests (a fresh app each) and anything
     * long-running in the console never see a stale value.
     *
     * @var array<string,mixed>
     */
    private static array $memo = [];

    private static ?int $memoApp = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $useMemo = self::memoEnabled();
        if ($useMemo && array_key_exists($key, self::$memo)) {
            $entry = self::$memo[$key];
        } else {
            // Stored WRAPPED: the cache treats a null as "not cached", so a key with no row (every
            // price still on its default) or a null value used to be re-queried and re-written on
            // every request — 19 SELECT+UPSERT pairs per /tarot view. ['m'=>1] remembers "no row"
            // and lets each caller keep its own default (the old code cached whichever default
            // the first caller happened to pass).
            $entry = Cache::rememberForever(self::cacheKey($key), function () use ($key) {
                $row = static::where('key', $key)->first();
                if (!$row) return ['m' => 1];
                try {
                    return ['v' => $row->is_encrypted ? Crypt::decryptString($row->value) : $row->value];
                } catch (\Throwable) {
                    return ['v' => $row->value];
                }
            });
            if ($useMemo) {
                self::$memo[$key] = $entry;
            }
        }

        return is_array($entry) && array_key_exists('v', $entry) ? $entry['v'] : $default;
    }

    public static function put(string $key, ?string $value, string $group = 'general', bool $encrypted = false): void
    {
        $stored = $encrypted && $value !== null ? Crypt::encryptString($value) : $value;
        static::updateOrCreate(['key' => $key], ['value' => $stored, 'group' => $group, 'is_encrypted' => $encrypted]);
        self::forgetCached($key);
    }

    /** Drop a key from the cache and this request's memo (both the current and the legacy entry). */
    public static function forgetCached(string $key): void
    {
        Cache::forget(self::cacheKey($key));
        Cache::forget("setting:$key");
        unset(self::$memo[$key]);
    }

    private static function cacheKey(string $key): string
    {
        return "setting.v2:$key";
    }

    /** Web requests (and the test suite) memoize; long-running console processes always ask the cache. */
    private static function memoEnabled(): bool
    {
        $app = app();
        if ($app->runningInConsole() && ! $app->runningUnitTests()) {
            return false;
        }
        $id = spl_object_id($app);
        if (self::$memoApp !== $id) {
            self::$memo = [];
            self::$memoApp = $id;
        }

        return true;
    }

    /**
     * Insert a setting ONLY if the key does not already exist. Never overwrites
     * a value the operator has edited via /admin → SettingsController. Used by
     * SettingSeeder so deploys can re-run without resetting branding/secrets.
     */
    public static function putIfMissing(string $key, ?string $value, string $group = 'general', bool $encrypted = false): void
    {
        if (static::where('key', $key)->exists()) {
            return;
        }
        $stored = $encrypted && $value !== null ? Crypt::encryptString($value) : $value;
        static::create(['key' => $key, 'value' => $stored, 'group' => $group, 'is_encrypted' => $encrypted]);
        self::forgetCached($key);
    }
}
