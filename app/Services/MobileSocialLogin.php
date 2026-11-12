<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileSocialLogin
{
    public const TTL = 300;

    public function browserOrigin(): ?string
    {
        $parts = parse_url((string) config('app.url'));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (preg_match('/[^\x00-\x7F]/', $host)) {
            if (! function_exists('idn_to_ascii')) {
                return null;
            }
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) {
                return null;
            }
        }
        $ipv6 = str_starts_with($host, '[') && str_ends_with($host, ']')
            && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        if (! $ipv6 && ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return 'https://'.$host.($port !== null && $port !== 443 ? ':'.$port : '');
    }

    public function key(string $id): string
    {
        return hash('sha256', $id);
    }

    public function pending(string $key)
    {
        return DB::table('social_login_requests')->where('id', $key)
            ->where('status', 'pending')->where('expires_at', '>', now())->first();
    }

    public function context(Request $request): ?array
    {
        $context = $request->session()->get('mobile_social');

        return is_array($context) ? $context : null;
    }

    public function validContext(Request $request): bool
    {
        $context = $this->context($request);
        $state = $request->query('state');
        if (! $context || ! is_string($context['state'] ?? null) || ! is_string($state)
            || ! hash_equals($context['state'], $state)) {
            return false;
        }
        $ticket = $this->pending($context['id']);

        return $ticket && hash_equals((string) $ticket->browser_hash, $this->key($context['binding']));
    }

    public function finish(Request $request, string $status, ?User $user = null): bool
    {
        $context = $this->context($request);
        if (! $context) {
            return false;
        }
        $updated = DB::table('social_login_requests')->where('id', $context['id'])
            ->where('browser_hash', $this->key($context['binding']))
            ->whereIn('status', ['pending', 'processing'])->where('expires_at', '>', now())
            ->update(['status' => $status, 'user_id' => $user?->id]);
        $request->session()->forget('mobile_social');

        return $updated === 1;
    }

    public function claimCallback(Request $request): bool
    {
        $context = $this->context($request);

        return $context && DB::table('social_login_requests')->where('id', $context['id'])
            ->where('browser_hash', $this->key($context['binding']))
            ->where('status', 'pending')->where('expires_at', '>', now())
            ->update(['status' => 'processing']) === 1;
    }

    public function page(bool $success = false)
    {
        return response()->view('pages.auth.social-complete', ['success' => $success], $success ? 200 : 400)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    }
}
