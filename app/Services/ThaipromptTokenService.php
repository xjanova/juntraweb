<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Renews a user's Thaiprompt access token from the stored refresh token, so a
 * 401 from the pool/MLM doesn't force a full re-link. Degrades gracefully:
 * returns false when no refresh token is held or the upstream doesn't issue
 * one (the caller then clears the dead token as before).
 */
class ThaipromptTokenService
{
    public function __construct(private ThaipromptClient $client) {}

    /** True when the token was successfully renewed (and persisted). */
    public function refresh(User $user): bool
    {
        $rt = (string) $user->thaiprompt_refresh_token;
        if ($rt === '') {
            return false;
        }

        $token = $this->client->refreshToken($rt);
        if (!$token || empty($token['access_token'])) {
            return false;
        }

        $user->forceFill([
            'thaiprompt_token'            => $token['access_token'],
            'thaiprompt_refresh_token'    => $token['refresh_token'] ?? $rt,
            'thaiprompt_token_expires_at' => isset($token['expires_in'])
                ? now()->addSeconds((int) $token['expires_in'])
                : null,
        ])->saveQuietly();

        Log::info('ThaipromptTokenService: refreshed thaiprompt_token', ['user_id' => $user->id]);
        return true;
    }
}
