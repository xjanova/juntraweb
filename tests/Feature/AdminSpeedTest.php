<?php

namespace Tests\Feature;

use App\Filament\Pages\TelegramAlerts;
use App\Filament\Widgets\BillingStatsWidget;
use App\Filament\Widgets\PendingTopupsWidget;
use App\Filament\Widgets\RevenueChartWidget;
use App\Models\Setting;
use App\Services\Affiliate\MaeMorAdmin;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Support\Alerts\AlertCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ⚡ หลังบ้านช้า (2026-11-04) — ตรึงสิ่งที่แก้ไว้ไม่ให้ถอยกลับ
 *
 * วัดบน prod: คำขอไป Thaiprompt ครั้งละ ~0.45–0.5 วิ · หน้า "ค่าแนะนำ" เรียกสองเส้นต่อคิว (~1 วิ)
 *   การ์ดตัวอย่าง Telegram วาดใหม่ ~0.5 วิ ทุก render · widget แดชบอร์ด poll ทุก 5 วิ + โหลดแยกอีก 3 คำขอ
 */
class AdminSpeedTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', '9f1c-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'super-secret-value', 'thaiprompt', true);
    }

    public function test_commissions_and_overview_come_back_from_one_concurrent_round(): void
    {
        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
            str_contains($r->url(), '/admin/overview') => Http::response(['data' => ['commissions' => ['pending_count' => 3]]]),
            str_contains($r->url(), '/admin/commissions') => Http::response(['data' => [['id' => 1]], 'meta' => ['last_page' => 1]]),
            default => Http::response(null, 404),
        });

        $res = app(MaeMorAdmin::class)->commissionsWithOverview(
            ['status' => 'pending', 'level' => 'all', 'search' => ''],
            ['date_from' => '2026-11-01'],
        );

        $this->assertTrue($res['list']['ok']);
        $this->assertSame([['id' => 1]], $res['list']['data']['data']);
        $this->assertTrue($res['overview']['ok']);
        $this->assertSame(3, $res['overview']['data']['data']['commissions']['pending_count']);

        // ตัวกรองต้องไปถึงทั้งสองเส้นเหมือนตอนเรียกแยก ('all' / ค่าว่าง ไม่ถูกส่ง)
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/commissions?')
            && $r['status'] === 'pending' && $r['date_from'] === '2026-11-01' && ! isset($r['level'], $r['search'])
            && $r->hasHeader('Authorization', 'Bearer srv-token'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/overview') && $r['date_from'] === '2026-11-01');
    }

    public function test_an_expired_token_inside_the_pool_is_renewed_and_the_read_retried(): void
    {
        Cache::put('juntra:server_token', 'old-token', 3600);
        $issued = 0;

        Http::fake(function (Request $r) use (&$issued) {
            if (str_contains($r->url(), '/oauth/token')) {
                $issued++;

                return Http::response(['access_token' => 'new-token', 'expires_in' => 3600]);
            }
            if ($r->hasHeader('Authorization', 'Bearer old-token')) {
                return Http::response(['message' => 'Unauthenticated.'], 401);
            }

            return str_contains($r->url(), '/stats')
                ? Http::response(['totals' => ['all_time' => 5]])
                : Http::response(['tree' => ['id' => 1, 'children' => []]]);
        });

        $res = app(MaeMorAdmin::class)->userStatsAndTree(9, 3);

        $this->assertTrue($res['stats']['ok']);
        $this->assertSame(5, $res['stats']['data']['totals']['all_time']);
        $this->assertTrue($res['tree']['ok']);
        $this->assertSame(1, $issued, 'ขอ token ใหม่ครั้งเดียว แม้สองเส้นจะได้ 401 พร้อมกัน');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tree') && $r['depth'] === 3 && $r->hasHeader('Authorization', 'Bearer new-token'));
    }

    public function test_a_dead_upstream_is_reported_unavailable_without_waiting_twice(): void
    {
        Cache::put('juntra:server_token', 'srv-token', 3600);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $res = app(JuntraServerClient::class)->affiliateMany([
            'a' => ['/admin/commissions', []],
            'b' => ['/admin/overview', []],
        ]);

        $this->assertSame('unavailable', $res['a']['status']);
        $this->assertSame('unavailable', $res['b']['status']);
        $this->assertSame(2, $calls, 'ต่อไม่ติด = ไม่ยิงซ้ำ ไม่งั้นหน้าค้างสองเท่าตอน Thaiprompt ล่ม');

        $page = app(MaeMorAdmin::class)->commissionsWithOverview([], []);
        $this->assertFalse($page['list']['ok']);
        $this->assertSame('ผังแม่หมอไม่ตอบ ลองใหม่อีกครั้ง', $page['list']['message']);
    }

    public function test_not_configured_means_every_read_is_unsupported_and_nothing_is_sent(): void
    {
        Setting::put('thaiprompt_client_id', '', 'thaiprompt');
        Http::fake();

        $res = app(JuntraServerClient::class)->affiliateMany([
            'a' => ['/admin/commissions', []],
            'b' => ['/admin/overview', []],
        ]);

        $this->assertSame('unsupported', $res['a']['status']);
        $this->assertSame('unsupported', $res['b']['status']);
        Http::assertNothingSent();
    }

    public function test_telegram_preview_card_is_drawn_once_and_reused(): void
    {
        if (! AlertCard::available()) {
            $this->markTestSkipped('เครื่องนี้ไม่มี GD/FreeType หรือฟอนต์ — การ์ดวาดไม่ได้อยู่แล้ว');
        }

        $page = new TelegramAlerts;
        $first = $page->getPreviewProperty();

        $this->assertIsString($first);
        $this->assertStringStartsWith('data:image/png;base64,', $first);

        // รอบสองต้องมาจากแคช ไม่วาดใหม่: แทนค่าที่เก็บไว้ด้วยของปลอม แล้วต้องได้ของปลอมกลับมา
        // (เทสต์ใช้ array store — อ่านคีย์จากที่เก็บตรง ๆ เพราะคีย์ผูกกับเวลาแก้ไขไฟล์)
        $cachedKeys = array_values(array_filter(
            array_keys((fn () => $this->storage)->call(Cache::store()->getStore())),
            fn ($k) => str_contains($k, 'telegram-alerts:preview:'),
        ));
        $this->assertCount(1, $cachedKeys);
        Cache::put($cachedKeys[0], 'data:image/png;base64,CACHED', 60);

        $this->assertSame('data:image/png;base64,CACHED', (new TelegramAlerts)->getPreviewProperty());
    }

    public function test_dashboard_widgets_render_with_the_page_and_do_not_poll_every_five_seconds(): void
    {
        $static = fn (string $class, string $prop) => (new \ReflectionProperty($class, $prop))->getValue();

        foreach ([BillingStatsWidget::class, RevenueChartWidget::class, PendingTopupsWidget::class] as $w) {
            $this->assertFalse($static($w, 'isLazy'), "{$w} ต้องวาดพร้อมหน้า (ไม่แยกคำขอ)");
        }
        $this->assertSame('60s', $static(BillingStatsWidget::class, 'pollingInterval'));
        $this->assertNull($static(RevenueChartWidget::class, 'pollingInterval'));
    }
}
