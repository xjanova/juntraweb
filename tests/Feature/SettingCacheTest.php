<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settings are read hundreds of times per page (the theme, once per Blade view). Every read after
 * the first must cost nothing — and a key with no row must be remembered too, not re-queried.
 */
class SettingCacheTest extends TestCase
{
    use RefreshDatabase;

    private function countQueries(callable $fn): int
    {
        $n = 0;
        DB::listen(function ($q) use (&$n) {
            if (str_contains($q->sql, '"settings"')) {
                $n++;
            }
        });
        $fn();

        return $n;
    }

    public function test_repeated_reads_hit_the_database_once(): void
    {
        Setting::put('theme', 'mae-mor-chantra', 'theme');

        $queries = $this->countQueries(function () {
            for ($i = 0; $i < 200; $i++) {
                $this->assertSame('mae-mor-chantra', Setting::get('theme'));
                app(ThemeManager::class)->active();
            }
        });

        $this->assertLessThanOrEqual(1, $queries);
    }

    public function test_missing_key_is_remembered_and_each_caller_keeps_its_default(): void
    {
        $queries = $this->countQueries(function () {
            $this->assertSame('0', Setting::get('pricing_nope_enabled', '0'));
            $this->assertSame('1', Setting::get('pricing_nope_enabled', '1'));
            $this->assertNull(Setting::get('pricing_nope_enabled'));
        });

        $this->assertSame(1, $queries, 'a key with no row is looked up once, not on every call');
    }

    public function test_put_is_seen_immediately(): void
    {
        $this->assertSame('x', Setting::get('site_name', 'x'));
        Setting::put('site_name', 'จันทรา', 'general');
        $this->assertSame('จันทรา', Setting::get('site_name', 'x'));

        app(ThemeManager::class)->active();
        app(ThemeManager::class)->switch(array_key_first(config('themes.themes')));
        $this->assertSame(array_key_first(config('themes.themes')), app(ThemeManager::class)->active());
    }
}
