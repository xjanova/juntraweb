<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

/**
 * Like Laravel's built-in 'encrypted' cast, but graceful: if a value
 * was stored before the cast was added (plaintext in DB), reading it
 * returns the raw string instead of throwing DecryptException. Writes
 * are always encrypted.
 *
 * This lets us migrate existing plaintext bearer tokens to encrypted
 * storage transparently — the next save() rewrites the row encrypted.
 */
class EncryptedString implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            // Legacy plaintext value — return as-is. Next save() encrypts.
            return $value;
        }
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return [$key => $value];
        }
        return [$key => Crypt::encryptString((string) $value)];
    }
}
