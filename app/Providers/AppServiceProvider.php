<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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

        // ─── ประตูหน้าของ API (ไม่ต้องล็อกอินก็ยิงได้) ─────────────────
        // Laravel 11 ไม่ใส่ `throttle:api` ให้อัตโนมัติ และ withRouting()
        // ใน bootstrap/app.php ก็ไม่ได้เรียก throttleApi() — แปลว่าก่อนหน้านี้
        // /api/v1/auth/login กับ /register **ไม่มีการจำกัดอัตราเลย** เดารหัสผ่าน
        // ได้ไม่จำกัดครั้ง และสมัครบัญชีขยะได้ไม่จำกัดใบ ต่างจากฝั่งเว็บที่
        // LoginRequest ล็อกไว้ที่ 5 ครั้ง + Turnstile กันบอทตอนสมัคร
        //
        // คีย์ผูกกับ (ชื่อผู้ใช้ที่กรอก + IP) เพื่อไม่ให้คนหลัง NAT เดียวกัน
        // ล็อกกันเอง แต่ยังมีเพดานรวมต่อ IP กันสคริปต์ไล่สุ่มหลายบัญชี
        RateLimiter::for('auth-login', function (Request $request) {
            $id = \Illuminate\Support\Str::lower(trim(
                (string) ($request->input('login') ?? $request->input('email') ?? '')
            ));

            return [
                Limit::perMinute(5)->by('login:' . $id . '|' . $request->ip()),
                Limit::perMinute(20)->by('loginip:' . $request->ip()),
            ];
        });

        // สมัครสมาชิก: แอพไม่มี Turnstile (ทำไม่ได้บนมือถือ) เพดานต่อ IP
        // จึงเป็นด่านเดียวที่กันฟาร์มบัญชี — 3/นาที และ 20/วัน ต่อ IP
        RateLimiter::for('auth-register', function (Request $request) {
            return [
                Limit::perMinute(3)->by('reg:' . $request->ip()),
                Limit::perDay(20)->by('regday:' . $request->ip()),
            ];
        });

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

        // Account-area sidebar — shared balance + pending top-up badge so
        // every /account/* and /wallet/* page renders identical chrome
        // without each controller having to compute it. Wrapped defensively
        // so the partial renders even if wallet tables aren't set up yet.
        View::composer('partials.account-sidebar', function ($view) {
            $user = auth()->user();
            if (!$user) return;
            try {
                $wallet = app(\App\Services\Wallet\WalletService::class);
                $balance = $wallet->balance($user);
                $pending = \App\Models\WalletTransaction::where('user_id', $user->id)
                    ->where('type', 'topup')->where('status', 'pending')->count();
            } catch (\Throwable $e) {
                $balance = 0;
                $pending = 0;
            }
            $view->with([
                'sidebarUser'          => $user,
                'sidebarBalance'       => $balance,
                'sidebarPendingTopups' => $pending,
                'sidebarHasMlm'        => method_exists($user, 'isThaipromptLinked') && $user->isThaipromptLinked(),
            ]);
        });
    }
}
