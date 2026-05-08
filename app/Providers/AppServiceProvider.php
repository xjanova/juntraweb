<?php

namespace App\Providers;

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
     * Override `app.name` from the `site_name` Setting so:
     *   - admin-editable brand survives every deploy
     *   - all blades using config('app.name') (footer/nav/title) stay
     *     in sync with what the operator typed at /admin → ตั้งค่าเว็บไซต์
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
    }
}
