<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use App\Support\Alerts\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function enableTelegram(array $categories = null): void
    {
        Setting::put('telegram_bot_token', '123456789:AAHfakeTokenForTestsOnly_abcdefghij', 'telegram', true);
        Setting::put('telegram_chat_id', '555000111', 'telegram');
        Setting::put('telegram_alerts_enabled', '1', 'telegram');
        if ($categories !== null) {
            AdminAlerts::saveCategories($categories);
        }
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
    }

    /** @return array<int,Request> requests that reached Telegram */
    private function telegramCalls(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'api.telegram.org'))->values()->all();
    }

    public function test_disabled_by_default_sends_nothing(): void
    {
        Http::fake();
        $this->assertFalse(AdminAlerts::send(new Alert(key: 'x', level: Alert::OK, title: 'hello', category: 'system')));
        Http::assertNothingSent();
    }

    public function test_send_delivers_card_once_per_throttle_and_records_history(): void
    {
        $this->enableTelegram();
        $alert = new Alert(key: 'same-thing', level: Alert::WARNING, title: 'ทดสอบ', category: 'system');

        $this->assertTrue(AdminAlerts::send($alert, 60));
        $this->assertFalse(AdminAlerts::send($alert, 60), 'same key inside the cooling-off period is silent');

        $calls = $this->telegramCalls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('/sendPhoto', $calls[0]->url());
        // The bot token never reaches the history table.
        $row = DB::table('admin_alerts')->first();
        $this->assertTrue((bool) $row->ok);
        $this->assertSame(77, (int) $row->message_id);
        $this->assertStringNotContainsString('AAHfakeToken', json_encode($row));
    }

    public function test_switched_off_category_is_not_sent(): void
    {
        $this->enableTelegram(['money', 'security']);
        $this->assertFalse(AdminAlerts::send(new Alert(key: 'r1', level: Alert::INFO, title: 'ดูดวง', category: 'readings')));
        $this->assertCount(0, $this->telegramCalls());
    }

    public function test_sms_credit_announces_money_in(): void
    {
        $this->enableTelegram();
        $user = User::factory()->create(['name' => 'คุณทดสอบ']);
        $tx = app(WalletService::class)->recordPendingTopup($user, 100.37, null, 'promptpay');

        app(WalletService::class)->confirmTopupAuto($tx, ['confirmed_via' => 'sms']);

        $calls = $this->telegramCalls();
        $this->assertNotEmpty($calls);
        $caption = collect($calls[0]->data())->firstWhere('name', 'caption')['contents'] ?? '';
        $this->assertStringContainsString('เงินเข้า ฿100.37', $caption);
        $this->assertStringContainsString('SMS ธนาคาร', $caption);
        $this->assertSame('topup:' . $tx->id, DB::table('admin_alerts')->value('subject'));
    }

    public function test_admin_adjust_and_role_grant_are_reported(): void
    {
        $this->enableTelegram();
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();

        app(WalletService::class)->adjust($user, 50, 'โปรโมชัน', $admin);
        $user->update(['role' => 'admin']);

        $titles = DB::table('admin_alerts')->pluck('title')->all();
        $this->assertContains('แอดมินเพิ่มเครดิต ฿50.00', $titles);
        $this->assertTrue(collect($titles)->contains(fn ($t) => str_contains($t, 'มีการให้สิทธิ์ admin')));
        $this->assertSame('critical', DB::table('admin_alerts')->where('category', 'security')->value('level'));
    }

    public function test_reading_is_reported_silently(): void
    {
        $this->enableTelegram();
        $user = User::factory()->create();
        Reading::create(['user_id' => $user->id, 'session_token' => 't', 'type' => 'tarot_three', 'payload' => ['cost' => 19]]);

        $call = $this->telegramCalls()[0] ?? null;
        $this->assertNotNull($call);
        $fields = collect($call->data())->pluck('contents', 'name');
        $this->assertSame('true', $fields['disable_notification'], 'readings must not buzz the phone');
        $this->assertStringContainsString('เปิดไพ่ 3 ใบ', (string) $fields['caption']);
    }

    public function test_password_guessing_alerts_after_threshold(): void
    {
        $this->enableTelegram();
        User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'victim@example.com', 'email' => 'victim@example.com', 'password' => 'wrong-' . $i]);
        }

        $this->assertTrue(DB::table('admin_alerts')->where('category', 'security')->where('title', 'like', '%เดารหัสผ่าน%')->exists());
    }

    public function test_daily_report_builds_a_card_with_week_columns(): void
    {
        $user = User::factory()->create();
        $tx = app(WalletService::class)->recordPendingTopup($user, 120, null, 'promptpay');
        app(WalletService::class)->confirmTopupAuto($tx, ['confirmed_via' => 'sms']);

        $alert = Reports::day(CarbonImmutable::now('Asia/Bangkok'));
        $this->assertCount(7, $alert->columns);
        $this->assertSame(120.0, (float) array_values($alert->columns)[6], 'today is the last column');
        $this->assertSame('฿120.00', $alert->facts['เงินเข้า']);
    }

    public function test_watchdog_runs_and_reminds_about_waiting_slips(): void
    {
        $this->enableTelegram();
        $user = User::factory()->create();
        $tx = WalletTransaction::create([
            'user_id' => $user->id, 'wallet_id' => app(WalletService::class)->getOrCreate($user)->id,
            'type' => 'topup', 'status' => 'pending', 'amount' => '99.00', 'method' => 'promptpay',
            'slip_path' => 'topup-slips/x.jpg', 'reference_code' => 'TUP-WAIT0001',
        ]);
        WalletTransaction::whereKey($tx->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('alerts:watchdog')->assertSuccessful();
        $this->assertTrue(DB::table('admin_alerts')->where('alert_key', 'slips-waiting')->exists());
    }
}
