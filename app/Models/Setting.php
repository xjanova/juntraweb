<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'is_encrypted'];

    protected $casts = ['is_encrypted' => 'boolean'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("setting:$key", function () use ($key, $default) {
            $row = static::where('key', $key)->first();
            if (!$row) return $default;
            try {
                return $row->is_encrypted ? Crypt::decryptString($row->value) : $row->value;
            } catch (\Throwable) {
                return $row->value;
            }
        });
    }

    public static function put(string $key, ?string $value, string $group = 'general', bool $encrypted = false): void
    {
        $stored = $encrypted && $value !== null ? Crypt::encryptString($value) : $value;
        static::updateOrCreate(['key' => $key], ['value' => $stored, 'group' => $group, 'is_encrypted' => $encrypted]);
        Cache::forget("setting:$key");
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
        Cache::forget("setting:$key");
    }
}
