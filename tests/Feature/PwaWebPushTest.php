<?php

namespace Tests\Feature;

use App\Models\DailyCheckin;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Push\PayloadCipher;
use App\Services\Push\PushNotifier;
use App\Services\Push\Vapid;
use App\Services\Wallet\WalletService;
use App\Support\DailyLuck;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 📱🔔 (2026-11-11) เว็บเป็นแอป + แจ้งเตือน — เจ้าของสั่ง "ทำให้เว็บติดตั้งลงมือถือ หรือ ไอแพดได้เหมือน web app
 *   ทำให้แจ้งเตือนได้เมื่อติดตั้งแล้ว เช่น แจ้งเตือนยอดค้าง หรือตามมาดูดวงได้"
 *
 * ล็อกไว้:
 *  - manifest ครบตามที่ Chrome ต้องใช้ตัดสินว่า "ติดตั้งได้" (ชื่อ, start_url, standalone, ไอคอน 192+512 มีไฟล์จริง)
 *  - service worker ไม่แคชหน้าเว็บ/API (มีแต่ไฟล์นิ่ง) — ห้ามคำทำนาย/วอลเลตค้างในเครื่อง
 *  - สมัครรับได้เฉพาะ push service ที่รู้จัก (กัน SSRF) · เครื่องย้ายบัญชีได้ · ไม่มีกุญแจ = ปิดทั้งระบบ
 *  - ยอดค้าง: ครั้งเดียวต่อบิล หลัง 30 นาที ไม่ทวงบิลที่แนบสลิปแล้ว ไม่ส่งช่วงเวลาเงียบ
 *  - ดวงประจำวัน: วันละครั้ง เฉพาะคนยังไม่เช็กอิน ช่วงเช้าเท่านั้น
 *  - คำทำนายพร้อม: ส่งทันทีครั้งเดียว ไม่ติดเวลาเงียบ
 *  - แอดมินปิดรายหมวดได้ · ผู้ใช้ปิดรายหมวดได้ · ข้อความเรียก "คุณ" ไม่ใช่ "ลูก"
 *  - ข้อความที่ส่งออกไปถอดรหัสได้จริงด้วยกุญแจของเบราว์เซอร์
 */
class PwaWebPushTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0:\OpenSSLAsymmetricKey, 1:string, 2:string}> กุญแจเบราว์เซอร์จำลอง ตาม id ของ subscription */
    private array $browsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();

        $keys = Vapid::generateKeys();
        config([
            'webpush.public_key' => $keys['public'],
            'webpush.private_key' => $keys['private'],
            'webpush.subject' => 'mailto:owner@example.test',
        ]);
        // วันพุธ 11 พ.ย. 2026 09:00 เวลาไทย = พ้นเวลาเงียบ อยู่ในหน้าต่างดวงเช้า
        $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscribe(User $user, string $host = 'fcm.googleapis.com'): PushSubscription
    {
        [, $pub, $key] = Vapid::newKeyPair();
        $auth = random_bytes(16);
        $endpoint = "https://$host/fcm/send/".bin2hex(random_bytes(12));
        $sub = PushSubscription::create([
            'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'p256dh' => Vapid::b64u($pub), 'auth' => Vapid::b64u($auth), 'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130',
        ]);
        $this->browsers[$sub->id] = [$key, $pub, $auth];

        return $sub;
    }

    /** ถอดข้อความที่ถูกส่งไปหาเครื่องนี้ ด้วยกุญแจของเครื่องนั้นเอง */
    private function decrypt(HttpRequest $req, PushSubscription $sub): array
    {
        [$key, $pub, $auth] = $this->browsers[$sub->id];

        return json_decode(PayloadCipher::decrypt($req->body(), $key, $pub, $auth), true);
    }

    private function fakePush(int $status = 201): void
    {
        Http::fake(['https://fcm.googleapis.com/*' => Http::response('', $status), 'https://web.push.apple.com/*' => Http::response('', $status)]);
    }

    private function topup(User $user, array $extra = []): WalletTransaction
    {
        $tx = WalletTransaction::create(array_merge([
            'user_id' => $user->id, 'wallet_id' => app(WalletService::class)->getOrCreate($user)->id,
            'type' => 'topup', 'status' => 'pending', 'amount' => '39.80', 'method' => 'promptpay',
            'reference_code' => 'TUP-'.strtoupper(bin2hex(random_bytes(4))), 'expires_at' => now()->addHours(48),
        ], $extra));
        WalletTransaction::whereKey($tx->id)->update(['created_at' => now()->subMinutes(45)]);

        return $tx->fresh();
    }

    /* ══════════ ติดตั้งเป็นแอป ══════════ */

    public function test_manifest_has_everything_chrome_needs_to_offer_install(): void
    {
        $res = $this->get('/manifest.webmanifest')->assertOk();
        $this->assertStringStartsWith('application/manifest+json', $res->headers->get('Content-Type'));
        $m = $res->json();

        $this->assertSame('จันทรา', $m['short_name']);
        $this->assertNotEmpty($m['name']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('/', $m['scope']);
        $this->assertStringStartsWith('/', $m['start_url']);
        $this->assertSame('th', $m['lang']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $m['theme_color']);
        $this->assertFalse($m['prefer_related_applications'], 'ห้ามบังคับไปโหลดแอป Play แทนการติดตั้งเว็บ');

        $sizes = collect($m['icons'])->groupBy('purpose')->map(fn ($g) => $g->pluck('sizes')->all());
        $this->assertEqualsCanonicalizing(['192x192', '512x512'], $sizes['any']);
        $this->assertEqualsCanonicalizing(['192x192', '512x512'], $sizes['maskable']);
        foreach ($m['icons'] as $icon) {
            $file = public_path(ltrim(parse_url($icon['src'], PHP_URL_PATH), '/'));
            $this->assertFileExists($file);
            [$w, $h] = getimagesize($file);
            $this->assertSame($icon['sizes'], "{$w}x{$h}", basename($file).' ขนาดไม่ตรงที่ประกาศ');
        }
    }

    public function test_every_page_links_the_manifest_and_the_ios_home_screen_tags(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('rel="manifest" href="'.route('pwa.manifest').'"', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-capable" content="yes"', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-status-bar-style" content="black-translucent"', $html);
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('x-data="pwaInstall"', $html, 'ชิปติดตั้งแอปต้องอยู่บนหน้าทั่วไป');
        $this->assertStringContainsString('env(safe-area-inset-top', $html, 'โหมดแอป iOS ต้องดันเมนูหลบแถบสถานะ');
    }

    public function test_install_chip_stays_off_the_oracle_room(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('chat.index'))->getContent();

        $this->assertStringNotContainsString('x-data="pwaInstall"', $html);
    }

    public function test_service_worker_caches_only_static_files_never_pages_or_api(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString("'/offline.html'", $sw);
        // หน้าเว็บ: ยิงเน็ตแล้วคืนเลย ไม่มี cache.put ในสาขา navigate
        $navigate = substr($sw, strpos($sw, "req.mode === 'navigate'"), 700);
        $this->assertStringNotContainsString('.put(', $navigate);
        $this->assertStringContainsString("if (req.method !== 'GET') return;", $sw);
        foreach (['/wallet', '/api', '/push', '/account', '/reading'] as $private) {
            $this->assertStringNotContainsString("startsWith('$private", $sw, "$private ต้องไม่ถูกแคช");
        }
        // คลิกแจ้งเตือนเปิดได้เฉพาะหน้าในเว็บเรา
        $this->assertStringContainsString('u.origin === self.location.origin', $sw);
    }

    /* ══════════ สมัครรับแจ้งเตือน ══════════ */

    private function payload(string $endpoint): array
    {
        [, $pub] = Vapid::newKeyPair();

        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => Vapid::b64u($pub), 'auth' => Vapid::b64u(random_bytes(16))]];
    }

    public function test_a_member_subscribes_and_the_device_is_stored_once(): void
    {
        $user = User::factory()->create();
        $body = $this->payload('https://fcm.googleapis.com/fcm/send/abc:APA91bF');

        $this->actingAs($user)->postJson('/push/subscribe', $body)->assertOk()->assertJson(['ok' => true, 'devices' => 1]);
        $this->actingAs($user)->postJson('/push/subscribe', $body)->assertOk()->assertJson(['devices' => 1]);

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($user->id, PushSubscription::first()->user_id);
    }

    public function test_guests_cannot_subscribe(): void
    {
        $this->postJson('/push/subscribe', $this->payload('https://fcm.googleapis.com/fcm/send/x'))->assertUnauthorized();
    }

    public function test_only_known_push_services_are_accepted_so_the_server_cannot_be_aimed_inward(): void
    {
        $user = User::factory()->create();
        foreach ([
            'http://fcm.googleapis.com/fcm/send/x',          // ไม่ใช่ https
            'https://127.0.0.1/fcm/send/x',
            'https://localhost/x',
            'https://evil.example/x',
            'https://fcm.googleapis.com.evil.example/x',     // ต่อท้ายชื่อโดเมนจริง
            'https://user:pw@fcm.googleapis.com/x',
            'https://fcm.googleapis.com:8443/x',
        ] as $endpoint) {
            $this->actingAs($user)->postJson('/push/subscribe', $this->payload($endpoint))->assertStatus(422);
        }
        $this->assertSame(0, PushSubscription::count());

        foreach (['https://web.push.apple.com/QGuQ', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://wns2-sg2p.notify.windows.com/w/?token=x'] as $ok) {
            $this->actingAs($user)->postJson('/push/subscribe', $this->payload($ok))->assertOk();
        }
    }

    public function test_bad_keys_are_rejected(): void
    {
        $user = User::factory()->create();
        $body = $this->payload('https://fcm.googleapis.com/fcm/send/x');

        $this->actingAs($user)->postJson('/push/subscribe', ['keys' => ['p256dh' => 'BBBB', 'auth' => $body['keys']['auth']]] + $body)->assertStatus(422);
        $this->actingAs($user)->postJson('/push/subscribe', ['keys' => ['p256dh' => $body['keys']['p256dh'], 'auth' => 'AAAA']] + $body)->assertStatus(422);
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_device_moves_to_whoever_is_signed_in_now(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $body = $this->payload('https://fcm.googleapis.com/fcm/send/shared-ipad');

        $this->actingAs($a)->postJson('/push/subscribe', $body)->assertOk();
        $this->actingAs($b)->postJson('/push/subscribe', $body)->assertOk();

        $this->assertSame([$b->id], PushSubscription::pluck('user_id')->all());
    }

    public function test_unsubscribe_removes_only_your_own_device(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $subA = $this->subscribe($a);
        $subB = $this->subscribe($b);

        $this->actingAs($a)->postJson('/push/unsubscribe', ['endpoint' => $subB->endpoint])->assertOk();
        $this->assertModelExists($subB);
        $this->actingAs($a)->postJson('/push/unsubscribe', ['endpoint' => $subA->endpoint])->assertOk();
        $this->assertModelMissing($subA);
    }

    public function test_without_vapid_keys_everything_is_off(): void
    {
        config(['webpush.public_key' => '', 'webpush.private_key' => '']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/push/subscribe', $this->payload('https://fcm.googleapis.com/fcm/send/x'))->assertStatus(503);
        $html = $this->actingAs($user)->get(route('account.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('pushSettings(', $html);
        $this->assertStringNotContainsString('jw-push-key', $html);
    }

    public function test_dashboard_shows_the_notification_card_and_the_public_key_only(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('account.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('แจ้งเตือนบนเครื่องนี้', $html);
        $this->assertStringContainsString('pushSettings(', $html);
        $this->assertStringContainsString(config('webpush.public_key'), $html);
        $this->assertStringNotContainsString(config('webpush.private_key'), $html, 'กุญแจลับห้ามหลุดไปหน้าเว็บ');
    }

    public function test_the_test_button_sends_to_every_device_of_that_member(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $subs = [$this->subscribe($user), $this->subscribe($user)];

        $this->actingAs($user)->postJson('/push/test')->assertOk()->assertJson(['devices' => 2, 'delivered' => 2]);

        Http::assertSentCount(2);
        Http::assertSent(function (HttpRequest $req) use ($subs) {
            $sub = collect($subs)->firstWhere('endpoint', $req->url());
            $msg = $this->decrypt($req, $sub);

            return $req->hasHeader('Content-Encoding', 'aes128gcm')
                && str_starts_with($req->header('Authorization')[0], 'vapid t=')
                && $req->hasHeader('TTL')
                && str_contains($msg['body'], 'ค่ะ');
        });
    }

    /* ══════════ คำทำนายพร้อม ══════════ */

    public function test_reading_ready_pushes_once_even_during_quiet_hours(): void
    {
        $this->fakePush();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 23:30', 'Asia/Bangkok'));
        $user = User::factory()->create();
        $sub = $this->subscribe($user);
        $reading = Reading::create(['user_id' => $user->id, 'session_token' => 't', 'type' => 'tarot_celtic',
            'question' => 'งาน', 'payload' => [], 'result' => 'ผล', 'status' => null]);

        $notifier = app(PushNotifier::class);
        $this->assertSame('sent', $notifier->readingReady($reading->id));
        $this->assertSame('duplicate', $notifier->readingReady($reading->id));

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $req) use ($sub, $reading) {
            $msg = $this->decrypt($req, $sub);

            return $msg['url'] === route('reading.show', $reading, false) && $msg['type'] === 'reading_ready'
                && $req->hasHeader('Urgency', 'high');
        });
    }

    public function test_unfinished_or_failed_readings_do_not_push(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $this->subscribe($user);
        $failed = Reading::create(['user_id' => $user->id, 'session_token' => 't', 'type' => 'tarot_three',
            'question' => 'q', 'payload' => [], 'status' => Reading::STATUS_FAILED]);

        $this->assertSame('skip', app(PushNotifier::class)->readingReady($failed->id));
        Http::assertNothingSent();
    }

    /* ══════════ ยอดค้าง ══════════ */

    public function test_an_unpaid_bill_gets_one_gentle_reminder(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $sub = $this->subscribe($user);
        $tx = $this->topup($user);

        $this->artisan('push:remind-bills')->assertSuccessful();
        $this->artisan('push:remind-bills')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $req) use ($sub, $tx) {
            $msg = $this->decrypt($req, $sub);

            return str_contains($msg['body'], '฿39.80') && str_contains($msg['body'].$msg['title'], 'คุณ')
                && $msg['url'] === route('wallet.topup.show', $tx, false)
                && $req->hasHeader('Topic', 'bill-'.$tx->id);
        });
        $this->assertSame(1, PushDelivery::where('type', 'bill')->count());
    }

    public function test_bills_that_are_new_paid_slipped_expiring_or_ancient_are_left_alone(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $this->subscribe($user);

        $young = $this->topup($user);
        WalletTransaction::whereKey($young->id)->update(['created_at' => now()->subMinutes(10)]);
        $this->topup($user, ['slip_path' => 'topup-slips/paid.jpg']);
        $this->topup($user, ['status' => 'success']);
        $this->topup($user, ['expires_at' => now()->addMinutes(5)]);
        $old = $this->topup($user);
        WalletTransaction::whereKey($old->id)->update(['created_at' => now()->subDays(2)]);

        $this->artisan('push:remind-bills')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_quiet_hours_hold_the_reminder_until_morning(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->topup($user);

        $this->travelTo(CarbonImmutable::parse('2026-11-11 22:15', 'Asia/Bangkok'));
        $this->artisan('push:remind-bills')->assertSuccessful();
        Http::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-12 08:05', 'Asia/Bangkok'));
        $this->artisan('push:remind-bills')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_one_bill_reminder_per_twenty_hours_even_with_several_bills(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->topup($user);
        $this->topup($user, ['amount' => '99.00']);

        $this->artisan('push:remind-bills')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_admin_can_switch_bill_reminders_off(): void
    {
        $this->fakePush();
        Setting::put('push_type_bill_enabled', '0', 'push');
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->topup($user);

        $this->artisan('push:remind-bills')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_device_that_was_uninstalled_is_dropped(): void
    {
        $this->fakePush(410);
        $user = User::factory()->create();
        $sub = $this->subscribe($user);
        $this->topup($user);

        $this->artisan('push:remind-bills')->assertSuccessful();

        $this->assertModelMissing($sub);
        $this->assertSame(0, PushDelivery::first()->delivered);
    }

    public function test_temporary_push_service_errors_keep_the_device_until_five_in_a_row(): void
    {
        $this->fakePush(503);
        $user = User::factory()->create();
        $sub = $this->subscribe($user);
        $notifier = app(PushNotifier::class);

        for ($i = 1; $i <= 4; $i++) {
            $notifier->test($user);
            $this->assertSame($i, $sub->fresh()->fail_count);
        }
        $notifier->test($user);
        $this->assertModelMissing($sub);
    }

    /* ══════════ ดวงประจำวัน ══════════ */

    public function test_daily_luck_nudge_goes_once_a_day_to_members_who_have_not_checked_in(): void
    {
        $this->fakePush();
        [$waiting, $done, $noDevice] = User::factory()->count(3)->create();
        $sub = $this->subscribe($waiting);
        $this->subscribe($done);
        DailyCheckin::create(['user_id' => $done->id, 'day' => DailyLuck::today()->toDateString(), 'streak' => 1]);

        $this->artisan('push:daily-nudge')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:30', 'Asia/Bangkok'));
        $this->artisan('push:daily-nudge')->assertSuccessful();

        Http::assertSentCount(1);
        $luck = DailyLuck::reading($waiting->fresh());
        Http::assertSent(function (HttpRequest $req) use ($sub, $luck) {
            $msg = $this->decrypt($req, $sub);

            return str_contains($msg['body'], $luck['lucky_color']['name']) && str_contains($msg['body'], $luck['number'])
                && str_ends_with($msg['url'], '#daily-luck') && $req->hasHeader('Urgency', 'low');
        });

        // วันใหม่ = ได้อีกครั้ง ทั้งสองคนที่มีเครื่อง (เช็กอินเมื่อวานไม่นับเป็นของวันนี้)
        $this->travelTo(CarbonImmutable::parse('2026-11-12 08:00', 'Asia/Bangkok'));
        $this->artisan('push:daily-nudge')->assertSuccessful();
        Http::assertSentCount(3);
    }

    public function test_daily_nudge_only_in_the_morning_window_and_never_in_quiet_hours(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $this->subscribe($user);

        foreach (['2026-11-11 06:30', '2026-11-11 12:00', '2026-11-11 15:00', '2026-11-11 21:30'] as $t) {
            $this->travelTo(CarbonImmutable::parse($t, 'Asia/Bangkok'));
            $this->artisan('push:daily-nudge')->assertSuccessful();
        }
        Http::assertNothingSent();
    }

    public function test_admin_quiet_hours_and_toggle_are_respected(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $this->subscribe($user);

        Setting::put('push_quiet_start', '21:00', 'push');
        Setting::put('push_quiet_end', '10:00', 'push');
        $this->artisan('push:daily-nudge')->assertSuccessful(); // 09:00 ยังเงียบอยู่
        Http::assertNothingSent();

        Setting::put('push_quiet_end', '08:00', 'push');
        Setting::put('push_type_daily_enabled', '0', 'push');
        $this->artisan('push:daily-nudge')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_member_can_mute_one_kind_and_keep_the_others(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $this->subscribe($user);

        $this->actingAs($user)->postJson('/push/preferences', ['muted' => ['daily', 'nonsense']])->assertOk()->assertJson(['muted' => ['daily']]);
        $this->artisan('push:daily-nudge')->assertSuccessful();
        Http::assertNothingSent();

        $this->topup($user);
        $this->artisan('push:remind-bills')->assertSuccessful();
        Http::assertSentCount(1);

        $this->actingAs($user)->postJson('/push/preferences', ['muted' => []])->assertOk()->assertJson(['muted' => []]);
        $this->assertSame([], PushNotifier::mutedTypes($user->fresh()));
    }

    public function test_every_message_calls_the_member_khun_never_luk(): void
    {
        $this->fakePush();
        $user = User::factory()->create();
        $this->subscribe($user);
        $reading = Reading::create(['user_id' => $user->id, 'session_token' => 't', 'type' => 'deep',
            'question' => 'q', 'payload' => [], 'result' => 'r', 'status' => null]);
        $n = app(PushNotifier::class);
        $n->readingReady($reading->id);
        $n->billReminder($this->topup($user));
        $n->dailyNudge($user);
        $n->test($user);

        $sub = PushSubscription::first();
        $texts = Http::recorded()->map(fn ($pair) => implode(' ', array_intersect_key($this->decrypt($pair[0], $sub), ['title' => 1, 'body' => 1])));
        $this->assertCount(4, $texts);
        foreach ($texts as $t) {
            $this->assertStringContainsString('คุณ', $t);
            $this->assertStringNotContainsString('ลูก', $t);
        }
    }

    /* ══════════ หลังบ้าน ══════════ */

    public function test_admin_page_loads_and_saves_toggles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/push-notifications')->assertOk()->assertSee('แจ้งเตือนแอปถึงสมาชิก');

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Livewire\Livewire::actingAs($admin)->test(\App\Filament\Pages\PushNotifications::class)
            ->set('data.type_daily', false)
            ->set('data.quiet_start', '22:00')
            ->set('data.quiet_end', '07:30')
            ->call('save')->assertHasNoErrors();

        $this->assertFalse(PushNotifier::typeEnabled('daily'));
        $this->assertTrue(PushNotifier::typeEnabled('bill'));
        $this->assertSame(['22:00', '07:30'], PushNotifier::quietHours());
    }

    public function test_members_cannot_open_the_admin_page(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->get('/admin/push-notifications')->assertForbidden();
    }
}
