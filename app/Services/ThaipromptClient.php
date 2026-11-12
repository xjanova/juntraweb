<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight OAuth2 client + REST adapter for the Thaiprompt sister project.
 * Endpoints are conventional — administrators can override the base URL
 * during install / from /admin → Settings → Thaiprompt.
 *
 *   {base}/oauth/authorize    → redirect target
 *   {base}/oauth/token        → token exchange
 *   {base}/api/user           → user profile
 */
class ThaipromptClient
{
    public function isEnabled(): bool
    {
        return Setting::get('thaiprompt_enabled', '0') === '1'
            && $this->clientId() !== ''
            && $this->clientSecret() !== ''
            && $this->baseUrl() !== '';
    }

    public function baseUrl(): string
    {
        return rtrim(Setting::get('thaiprompt_base_url', 'https://main.thaiprompt.online'), '/');
    }

    public function clientId(): string
    {
        return (string) Setting::get('thaiprompt_client_id', '');
    }

    public function clientSecret(): string
    {
        return (string) Setting::get('thaiprompt_client_secret', '');
    }

    public function redirectUri(): string
    {
        return route('thaiprompt.callback');
    }

    public function authorizeUrl(string $state): string
    {
        $q = http_build_query([
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => 'read profile email',
            'state'         => $state,
        ]);
        return $this->baseUrl() . '/oauth/authorize?' . $q;
    }

    public function socialProviders(): array
    {
        if (! $this->isEnabled() || parse_url($this->baseUrl(), PHP_URL_SCHEME) !== 'https') {
            return [];
        }
        return Cache::remember('social-providers:'.hash('sha256', $this->baseUrl()), 30, function () {
            try {
                $response = Http::acceptJson()->withoutRedirecting()->timeout(5)
                    ->get($this->baseUrl().'/api/oauth/social/providers');
                if (! $response->successful() || $response->json('data.flow') !== 'juntra-social-v1') {
                    return [];
                }
                $enabled = $response->json('data.providers');
                if (! is_array($enabled)) {
                    return [];
                }
                $providers = [];
                foreach (['facebook' => 'Facebook', 'google' => 'Google'] as $id => $name) {
                    if (in_array($id, array_column($enabled, 'id'), true)) {
                        $providers[] = compact('id', 'name');
                    }
                }
                return $providers;
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public function socialAuthorizeUrl(string $state, string $provider, string $nonce): string
    {
        $query = parse_url($this->authorizeUrl($state), PHP_URL_QUERY);
        return $this->baseUrl().'/oauth/social/authorize?'.$query.'&'.http_build_query([
            'provider' => $provider, 'social_nonce' => $nonce,
        ]);
    }

    public function exchangeCode(string $code, ?array $social = null): ?array
    {
        try {
            $body = [
                'grant_type'    => 'authorization_code',
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri'  => $this->redirectUri(),
                'code'          => $code,
            ];
            if ($social) {
                $body['social_provider'] = $social['provider'];
                $body['social_nonce'] = $social['nonce'];
            }
            $resp = Http::asForm()->withoutRedirecting()->timeout(15)->post($this->baseUrl() . '/oauth/token', $body);
            if (!$resp->successful()) {
                Log::warning('Thaiprompt token exchange failed', ['status' => $resp->status()]);
                return null;
            }
            if ($social && (! is_string($resp->json('social_provider')) || ! is_string($resp->json('social_nonce'))
                || ! hash_equals($social['provider'], $resp->json('social_provider'))
                || ! hash_equals($social['nonce'], $resp->json('social_nonce')))) {
                return null;
            }
            return $resp->json();
        } catch (\Throwable $e) {
            Log::error('Thaiprompt token exchange threw', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Exchange a refresh token for a fresh access token. Returns the token
     * response (access_token / refresh_token / expires_in) or null if the
     * upstream doesn't support the grant or the refresh token is dead.
     */
    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $resp = Http::asForm()->timeout(15)->post($this->baseUrl() . '/oauth/token', [
                'grant_type'    => 'refresh_token',
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $refreshToken,
            ]);
            if (!$resp->successful()) {
                Log::warning('Thaiprompt token refresh failed', ['status' => $resp->status()]);
                return null;
            }
            return $resp->json();
        } catch (\Throwable $e) {
            Log::warning('Thaiprompt token refresh threw', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    public function fetchUser(string $accessToken): ?array
    {
        // Try `/api/user` first (the OAuth2 standard convention) and fall
        // back to `/api/v1/me` (Thaiprompt's actual current endpoint, which
        // is what Sanctum tokens authenticate against today). When Thaiprompt
        // ships a real OAuth provider that exposes /api/user, this will Just
        // Work without juntra changes.
        foreach (['/api/user', '/api/v1/me'] as $path) {
            try {
                $resp = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(15)
                    ->get($this->baseUrl() . $path);
                if ($resp->successful()) {
                    $payload = $resp->json();
                    // /api/v1/me wraps in {success, data: {...}} — normalise
                    if (isset($payload['data']) && is_array($payload['data'])) {
                        return $payload['data'];
                    }
                    return $payload;
                }
            } catch (\Throwable $e) {
                Log::warning("Thaiprompt $path threw", ['msg' => $e->getMessage()]);
                // try next path
            }
        }

        Log::warning('Thaiprompt user fetch failed on both /api/user and /api/v1/me');
        return null;
    }
}
