<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DownloadPageTest extends TestCase
{
    // The page reads Settings + the layout reads the theme Setting.
    use RefreshDatabase;

    private const DEFAULT_PLAY_URL = 'https://play.google.com/store/apps/details?id=com.xjanova.juntra';

    public function test_download_page_renders_with_play_store_link(): void
    {
        $response = $this->get('/download');

        $response->assertOk();
        $response->assertSee('Google Play');
        $response->assertSee(route('download.go'), false);
        // ไม่มี app_store_url → ปุ่ม iOS ต้องเป็นสถานะ "เร็ว ๆ นี้"
        $response->assertSee('เร็ว ๆ นี้');
    }

    public function test_go_redirects_to_default_play_url_and_counts_click(): void
    {
        $this->get('/download/go')->assertRedirect(self::DEFAULT_PLAY_URL);

        $this->assertSame('1', Setting::get('app_download_clicks'));

        $this->get('/download/go');
        $this->assertSame('2', Setting::get('app_download_clicks'));
    }

    public function test_go_uses_admin_configured_url(): void
    {
        Setting::put('play_store_url', 'https://play.google.com/store/apps/details?id=custom.id', 'app');

        $this->get('/download/go')
            ->assertRedirect('https://play.google.com/store/apps/details?id=custom.id');
    }

    public function test_non_https_store_url_falls_back_to_default(): void
    {
        // ค่าที่ไม่ใช่ https (เช่น javascript: หรือ http) ต้องไม่ถูกใช้ redirect
        Setting::put('play_store_url', 'javascript:alert(1)', 'app');

        $this->get('/download/go')->assertRedirect(self::DEFAULT_PLAY_URL);
    }

    public function test_app_store_button_appears_when_configured(): void
    {
        Setting::put('app_store_url', 'https://apps.apple.com/th/app/juntra/id999', 'app');

        $response = $this->get('/download');
        $response->assertOk();
        $response->assertSee('https://apps.apple.com/th/app/juntra/id999', false);
        $response->assertDontSee('เร็ว ๆ นี้');
    }

    public function test_app_alias_redirects_to_download(): void
    {
        $this->get('/app')->assertRedirect('/download');
    }
}
