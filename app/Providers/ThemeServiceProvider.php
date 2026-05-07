<?php

namespace App\Providers;

use App\Services\ThemeManager;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ThemeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ThemeManager::class);
    }

    public function boot(): void
    {
        View::composer('*', function ($view) {
            $themes = $this->app->make(ThemeManager::class);
            $view->with([
                'activeTheme' => $themes->active(),
                'themeConfig' => $themes->config(),
                'themeLayout' => $themes->layout(),
            ]);
        });
    }
}
