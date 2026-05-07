<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class ThemeManager
{
    /** Active theme slug (cached). */
    public function active(): string
    {
        $slug = Setting::get('theme', config('themes.default'));
        return $this->resolve($slug);
    }

    /** Active theme config array. */
    public function config(): array
    {
        $slug = $this->active();
        return config("themes.themes.$slug");
    }

    /** All registered themes (for admin picker). */
    public function all(): array
    {
        return config('themes.themes', []);
    }

    /** Switch the active theme by slug. Returns true if it was a known theme. */
    public function switch(string $slug): bool
    {
        if (!array_key_exists($slug, $this->all())) {
            return false;
        }
        Setting::put('theme', $slug, 'theme', false);
        Cache::forget('setting:theme');
        return true;
    }

    /** Fall back to the default if `$slug` is unknown. */
    private function resolve(?string $slug): string
    {
        $themes = $this->all();
        if ($slug && array_key_exists($slug, $themes)) {
            return $slug;
        }
        return config('themes.default');
    }

    /** Layout view name for the active theme — used by `@extends` in pages. */
    public function layout(): string
    {
        return $this->config()['layout'] ?? 'themes.mae-mor-chantra.layout';
    }

    /** Home view name for the active theme. */
    public function home(): string
    {
        return $this->config()['home'] ?? 'themes.mae-mor-chantra.home';
    }
}
