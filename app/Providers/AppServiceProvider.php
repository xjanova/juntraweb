<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * - Override `app.name` from the `site_name` Setting so:
     *     - admin-editable brand survives every deploy
     *     - all blades using config('app.name') (footer/nav/title) stay
     *       in sync with what the operator typed at /admin → ตั้งค่าเว็บไซต์
     *
     * - Define per-user RateLimiter buckets for paid/AI POSTs. Per-user
     *   keys (auth user id) are fairer than per-IP — users behind shared
     *   NAT (campus/cafe wifi) won't rate-limit each other, while a single
     *   abusive user is still throttled.
     *
     * Wrapped defensively because:
     *   - install wizard runs before the settings table exists
     *   - artisan commands like `key:generate` boot the app pre-DB
     */
    public function boot(): void
    {
        try {
            if (\Schema::hasTable('settings')) {
                $brand = \App\Models\Setting::get('site_name');
                if (is_string($brand) && $brand !== '') {
                    config(['app.name' => $brand]);
                }
            }
        } catch (\Throwable $e) {
            // DB unreachable — keep the .env default. Don't crash boot.
        }

        // Paid reading actions (tarot/celtic-cross, numerology, palmistry,
        // auspicious): 6 / minute / user. Caps wallet drain from a runaway
        // script while leaving plenty of headroom for legit usage.
        RateLimiter::for('reading', function (Request $request) {
            $key = $request->user()?->id ?: 'ip:' . $request->ip();
            return Limit::perMinute(6)->by((string) $key);
        });

        // AI chat: 60 / minute / user. Each message is cheap (฿2 default)
        // but each fires an upstream Thaiprompt API call — 1/sec is plenty.
        RateLimiter::for('chat-send', function (Request $request) {
            $key = $request->user()?->id ?: 'ip:' . $request->ip();
            return Limit::perMinute(60)->by((string) $key);
        });

        // Top-up submission: 10 / minute / user. Slip uploads are i/o
        // heavy — guard against accidental retries from flaky mobile.
        RateLimiter::for('topup', function (Request $request) {
            $key = $request->user()?->id ?: 'ip:' . $request->ip();
            return Limit::perMinute(10)->by((string) $key);
        });
    }
}
