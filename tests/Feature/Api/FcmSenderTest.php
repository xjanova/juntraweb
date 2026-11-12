<?php

namespace Tests\Feature\Api;

use App\Models\DailyCheckin;
use App\Models\PushDelivery;
use App\Models\PushDevice;
use App\Models\PushSubscription;
use App\Models\Reading;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Push\FcmSender;
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
 * 📲 (2026-11-12) FCM HTTP v1 — ช่องทางแจ้งเตือนของแอป Android คู่กับ Web Push
 *
 * ล็อกไว้:
 *  - ไม่ตั้ง/ตั้งผิด = ปิดเงียบ ๆ ไม่ยิง HTTP สักครั้ง · Web Push ไม่รู้สึกอะไร
 *  - access token เซ็น RS256 ถูกต้อง แลกครั้งเดียวใช้ซ้ำได้ · แลกไม่ผ่าน = พัก ไม่ลบเครื่องใคร
 *  - ข้อความถึงแอปมี Bearer + token ของเครื่อง + URL เต็ม (เว็บยังได้ path เดิม)
 *  - token ตาย = ลบทันที · ล่มชั่วคราว = นับถึง 5 · คีย์ฝั่งเราผิด = ไม่นับ
 *  - คนที่มีแค่แอป (ไม่มีเว็บ) ได้รับตามกติกาเดิมทุกข้อ: ปิดหมวด · เวลาเงียบ · ไม่ซ้ำ
 *  - แอปจาก Google Play ไม่ได้รับเตือนยอดค้าง (ชวนไปจ่ายนอก Play)
 */
class FcmSenderTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'juntra-test';

    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/juntra-test/messages:send';

    /** @var array{0:string,1:string}|null [private PEM, public PEM] — สร้างครั้งเดียวต่อรอบเทสต์ (RSA 2048 ช้า) */
    private static ?array $rsa = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        config([
            'webpush.public_key' => '', 'webpush.private_key' => '',
            'fcm.service_account' => '', 'fcm.project_id' => '',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:string,1:string} */
    private static function rsa(): array
    {
        if (self::$rsa === null) {
            $opts = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
            $key = openssl_pkey_new($opts);
            $cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            if ($key === false && is_file($cnf)) { // PHP บน Windows บางชุดไม่มี openssl.cnf ในที่ปกติ
                $key = openssl_pkey_new($opts + ['config' => $cnf]);
            }
            $pem = '';
            openssl_pkey_export($key, $pem, null, is_file($cnf) ? ['config' => $cnf] : []);
            self::$rsa = [$pem, openssl_pkey_get_details($key)['key']];
        }

        return self::$rsa;
    }

    private function serviceAccount(array $override = []): string
    {
        return json_encode($override + [
            'type' => 'service_account',
            'project_id' => self::PROJECT,
            'private_key_id' => 'kid-123',
            'private_key' => self::rsa()[0],
            'client_email' => 'push@juntra-test.iam.gserviceaccount.com',
            'token_uri' => 'https://evil.example/token', // ต้องไม่ถูกใช้ — ปลายทางแลก token ตายตัว
        ], JSON_UNESCAPED_SLASHES);
    }

    private function fcmOn(): void
    {
        config(['fcm.service_account' => $this->serviceAccount()]);
    }

    private function fakeGoogle($fcm = null, $oauth = null): void
    {
        Http::fake([
            FcmSender::TOKEN_URL => $oauth ?? Http::response(['access_token' => 'ya29.test-access', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            'https://fcm.googleapis.com/v1/projects/*' => $fcm ?? Http::response(['name' => 'projects/juntra-test/messages/0:1']),
            'https://web.push.apple.com/*' => Http::response('', 201),
        ]);
    }

    private function device(User $user, ?string $channel = 'direct'): PushDevice
    {
        $token = 'dev'.bin2hex(random_bytes(10)).':APA91b'.str_repeat('Z', 100);

        return PushDevice::create(['user_id' => $user->id, 'token' => $token, 'token_hash' => PushDevice::hashToken($token), 'channel' => $channel]);
    }

    private function sends()
    {
        return Http::recorded(fn (HttpRequest $req) => $req->url() === self::SEND_URL);
    }

    private function oauthCalls(): int
    {
        return Http::recorded(fn (HttpRequest $req) => $req->url() === FcmSender::TOKEN_URL)->count();
    }

    private function reading(User $user): Reading
    {
        return Reading::create(['user_id' => $user->id, 'session_token' => 't', 'type' => 'tarot_celtic',
            'question' => 'งาน', 'payload' => [], 'result' => 'ผล', 'status' => null]);
    }

    private function topup(User $user): WalletTransaction
    {
        $tx = WalletTransaction::create([
            'user_id' => $user->id, 'wallet_id' => app(WalletService::class)->getOrCreate($user)->id,
            'type' => 'topup', 'status' => 'pending', 'amount' => '39.80', 'method' => 'promptpay',
            'reference_code' => 'TUP-'.strtoupper(bin2hex(random_bytes(4))), 'expires_at' => now()->addHours(48),
        ]);
        WalletTransaction::whereKey($tx->id)->update(['created_at' => now()->subMinutes(45)]);

        return $tx->fresh();
    }

    private static function b64uDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /* ══════════ ไม่ตั้งค่า = ปิดเงียบ ══════════ */

    public function test_without_credentials_nothing_is_sent_and_push_stays_off(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);

        $this->assertFalse(FcmSender::configured());
        $this->assertFalse(PushNotifier::ready(), 'ไม่มีทั้ง VAPID และ FCM = ระบบแจ้งเตือนปิด');
        $this->assertSame('off', app(FcmSender::class)->send($device, ['title' => 't', 'body' => 'b']));
        $this->assertSame('off', app(PushNotifier::class)->readingReady($this->reading($user)->id));
        $this->assertSame(['devices' => 0, 'delivered' => 0], app(PushNotifier::class)->test($user));
        $this->artisan('push:daily-nudge')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PushDelivery::count());
        $this->assertModelExists($device);
    }

    public function test_broken_credentials_count_as_not_configured_without_throwing(): void
    {
        $missing = base_path('storage/does-not-exist-'.bin2hex(random_bytes(4)).'.json');
        foreach ([
            '{not json',
            $this->serviceAccount(['private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n"]),
            $this->serviceAccount(['client_email' => '']),
            $this->serviceAccount(['type' => 'authorized_user']),
            $this->serviceAccount(['project_id' => 'Bad Project/../x']),
            $missing,
            'plain words',
        ] as $raw) {
            config(['fcm.service_account' => $raw]);
            $this->assertFalse(FcmSender::configured(), mb_substr($raw, 0, 40));
        }
        Http::assertNothingSent();
    }

    public function test_credentials_may_be_a_file_path_raw_json_base64_and_the_project_can_be_overridden(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fcm-sa-');
        try {
            file_put_contents($file, $this->serviceAccount());
            config(['fcm.service_account' => $file]);
            $this->assertTrue(FcmSender::configured(), 'path ไฟล์');

            config(['fcm.service_account' => base64_encode($this->serviceAccount())]);
            $this->assertTrue(FcmSender::configured(), 'base64');

            // dotenv แบบ "..." แปลง \n ใน private_key เป็นขึ้นบรรทัดจริง
            config(['fcm.service_account' => str_replace('\n', "\n", $this->serviceAccount())]);
            $this->assertTrue(FcmSender::configured(), 'JSON ที่ \n ถูกแปลงเป็นขึ้นบรรทัด');

            $this->fakeGoogle();
            config(['fcm.service_account' => $this->serviceAccount(['project_id' => null]), 'fcm.project_id' => 'other-project']);
            $user = User::factory()->create();
            $this->device($user);
            app(PushNotifier::class)->test($user);
            Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://fcm.googleapis.com/v1/projects/other-project/messages:send');
        } finally {
            @unlink($file);
        }
    }

    /* ══════════ ส่งจริง ══════════ */

    public function test_an_app_only_member_gets_the_reading_with_bearer_token_and_a_full_url(): void
    {
        $this->fcmOn();
        $this->fakeGoogle();
        $user = User::factory()->create();
        $device = $this->device($user, 'play');
        $reading = $this->reading($user);

        $this->assertTrue(PushNotifier::ready(), 'มีแค่ FCM ก็ถือว่าพร้อม');
        $this->assertSame('sent', app(PushNotifier::class)->readingReady($reading->id));

        // ขอ access token: JWT RS256 ที่ตรวจด้วยกุญแจสาธารณะได้ ส่งไปปลายทางของ Google เท่านั้น
        Http::assertSent(function (HttpRequest $req) {
            if ($req->url() !== FcmSender::TOKEN_URL) {
                return false;
            }
            [$h, $c, $sig] = explode('.', $req['assertion']);
            $header = json_decode(self::b64uDecode($h), true);
            $claims = json_decode(self::b64uDecode($c), true);

            return $req['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && openssl_verify("$h.$c", self::b64uDecode($sig), self::rsa()[1], OPENSSL_ALGO_SHA256) === 1
                && $header === ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-123']
                && $claims['iss'] === 'push@juntra-test.iam.gserviceaccount.com'
                && $claims['scope'] === FcmSender::SCOPE
                && $claims['aud'] === FcmSender::TOKEN_URL
                && $claims['exp'] - $claims['iat'] === 3600;
        });
        Http::assertNotSent(fn (HttpRequest $req) => str_contains($req->url(), 'evil.example'));

        $this->assertCount(1, $this->sends());
        Http::assertSent(function (HttpRequest $req) use ($device, $reading) {
            $m = $req['message'] ?? [];

            return $req->url() === self::SEND_URL
                && $req->hasHeader('Authorization', 'Bearer ya29.test-access')
                && $m['token'] === $device->token
                && $m['data']['url'] === route('reading.show', $reading)
                && str_starts_with($m['data']['url'], 'http')
                && $m['data']['type'] === 'reading_ready'
                && $m['data']['tag'] === 'reading-'.$reading->id
                && $m['android']['priority'] === 'HIGH'
                && $m['android']['notification']['tag'] === 'reading-'.$reading->id
                && str_contains($m['notification']['title'], 'คุณ')
                && $m['notification']['body'] !== ''
                && collect($m['data'])->every(fn ($v) => is_string($v));
        });

        $this->assertNotNull($device->fresh()->last_success_at);
        $this->assertSame(1, PushDelivery::first()->delivered);
    }

    public function test_the_access_token_is_minted_once_and_reused(): void
    {
        $this->fcmOn();
        $this->fakeGoogle();
        $user = User::factory()->create();
        $this->device($user);
        $this->device($user);

        app(PushNotifier::class)->test($user);
        app(PushNotifier::class)->test($user);

        $this->assertSame(1, $this->oauthCalls());
        $this->assertCount(4, $this->sends());
    }

    public function test_an_unregistered_token_is_dropped_at_once(): void
    {
        $this->fcmOn();
        $this->fakeGoogle(Http::response(['error' => [
            'code' => 404, 'message' => 'Requested entity was not found.', 'status' => 'NOT_FOUND',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
        ]], 404));
        $user = User::factory()->create();
        $device = $this->device($user);

        $this->assertSame('undelivered', app(PushNotifier::class)->readingReady($this->reading($user)->id));

        $this->assertModelMissing($device);
        $this->assertSame(0, PushDelivery::first()->delivered);
    }

    public function test_a_malformed_token_is_dropped_but_other_bad_requests_only_count(): void
    {
        $this->fcmOn();
        $user = User::factory()->create();
        $bad = $this->device($user);
        $fine = $this->device($user);
        Http::fake([
            FcmSender::TOKEN_URL => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3599]),
            self::SEND_URL => function (HttpRequest $req) use ($bad) {
                return $req['message']['token'] === $bad->token
                    ? Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT',
                        'message' => 'The registration token is not a valid FCM registration token']], 400)
                    : Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT',
                        'message' => "Invalid value at 'message.data[0].value'"]], 400);
            },
        ]);

        app(PushNotifier::class)->test($user);

        $this->assertModelMissing($bad);
        $this->assertSame(1, $fine->fresh()->fail_count, 'ข้อความเราผิดรูปไม่ใช่ token เสีย — นับ ไม่ลบทันที');
    }

    public function test_temporary_errors_keep_the_device_until_five_in_a_row(): void
    {
        $this->fcmOn();
        $this->fakeGoogle(Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503));
        $user = User::factory()->create();
        $device = $this->device($user);

        for ($i = 1; $i <= 4; $i++) {
            app(PushNotifier::class)->test($user);
            $this->assertSame($i, $device->fresh()->fail_count);
        }
        app(PushNotifier::class)->test($user);
        $this->assertModelMissing($device);
    }

    public function test_wrong_server_credentials_never_wipe_members_devices(): void
    {
        $this->fcmOn();
        $user = User::factory()->create();
        $device = $this->device($user);

        // FCM ปฏิเสธคีย์/สิทธิ์ของเรา
        $this->fakeGoogle(Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 403));
        for ($i = 0; $i < 6; $i++) {
            app(PushNotifier::class)->test($user);
        }
        $this->assertSame(0, $device->fresh()->fail_count);
    }

    public function test_a_failed_token_exchange_pauses_quietly_without_blaming_devices(): void
    {
        $this->fcmOn();
        $this->fakeGoogle(null, Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400));
        $user = User::factory()->create();
        $device = $this->device($user);

        $this->assertSame(['devices' => 1, 'delivered' => 0], app(PushNotifier::class)->test($user));
        app(PushNotifier::class)->test($user);

        $this->assertSame(1, $this->oauthCalls(), 'แลกไม่ผ่านแล้วต้องพัก ไม่ยิง Google ทุกข้อความ');
        $this->assertCount(0, $this->sends());
        $this->assertSame(0, $device->fresh()->fail_count);
    }

    /* ══════════ กติกาเดิมทุกข้อ ใช้กับแอปด้วย ══════════ */

    public function test_app_only_members_keep_mute_quiet_hours_and_no_duplicates(): void
    {
        $this->fcmOn();
        $this->fakeGoogle();
        $user = User::factory()->create();
        $this->device($user);
        $notifier = app(PushNotifier::class);

        // ไม่ซ้ำ
        $reading = $this->reading($user);
        $this->assertSame('sent', $notifier->readingReady($reading->id));
        $this->assertSame('duplicate', $notifier->readingReady($reading->id));
        $this->assertCount(1, $this->sends());

        // ปิดหมวดเอง
        PushNotifier::saveMutedTypes($user, ['daily']);
        $this->assertSame('muted', $notifier->dailyNudge($user->fresh()));

        // เวลาเงียบ
        $this->travelTo(CarbonImmutable::parse('2026-11-11 22:30', 'Asia/Bangkok'));
        $this->assertSame('quiet', $notifier->billReminder($this->topup($user)));

        $this->assertCount(1, $this->sends());
    }

    public function test_the_morning_nudge_command_reaches_members_who_only_have_the_app(): void
    {
        $this->fcmOn();
        $this->fakeGoogle();
        [$waiting, $done] = User::factory()->count(2)->create();
        $device = $this->device($waiting, 'play');
        $this->device($done, 'play');
        DailyCheckin::create(['user_id' => $done->id, 'day' => DailyLuck::today()->toDateString(), 'streak' => 1]);

        $this->artisan('push:daily-nudge')->assertSuccessful();
        $this->artisan('push:daily-nudge')->assertSuccessful();

        $this->assertCount(1, $this->sends());
        Http::assertSent(fn (HttpRequest $req) => $req->url() === self::SEND_URL
            && $req['message']['token'] === $device->token
            && $req['message']['data']['url'] === route('account.dashboard').'#daily-luck'
            && $req['message']['android']['priority'] === 'NORMAL'
            && $req['message']['android']['collapse_key'] === 'daily');
    }

    public function test_bill_reminders_go_to_the_direct_app_only_never_the_play_build(): void
    {
        $this->fcmOn();
        $this->fakeGoogle();
        [$playOnly, $both] = User::factory()->count(2)->create();
        $this->device($playOnly, 'play');
        $this->device($playOnly, null); // ไม่รู้ช่อง = ถือเป็น play
        $this->device($both, 'play');
        $direct = $this->device($both, 'direct');
        $this->topup($playOnly);
        $tx = $this->topup($both);

        $this->artisan('push:remind-bills')->assertSuccessful();

        $this->assertCount(1, $this->sends());
        Http::assertSent(fn (HttpRequest $req) => $req->url() === self::SEND_URL
            && $req['message']['token'] === $direct->token
            && $req['message']['android']['collapse_key'] === 'bill-'.$tx->id
            && $req['message']['data']['url'] === route('wallet.topup.show', $tx));
        $this->assertSame(0, PushDelivery::where('user_id', $playOnly->id)->count(), 'คนที่มีแต่แอป Play ต้องไม่ถูกจดว่าทวงแล้ว');
        $this->assertSame('no_device', app(PushNotifier::class)->billReminder($this->topup($playOnly)));
    }

    /* ══════════ สองช่องทางอยู่ด้วยกัน ══════════ */

    public function test_with_both_channels_the_web_still_gets_exactly_what_it_got_before(): void
    {
        $keys = Vapid::generateKeys();
        config(['webpush.public_key' => $keys['public'], 'webpush.private_key' => $keys['private'], 'webpush.subject' => 'mailto:owner@example.test']);
        $this->fcmOn();
        $this->fakeGoogle();
        $user = User::factory()->create();
        [, $pub, $key] = Vapid::newKeyPair();
        $auth = random_bytes(16);
        $endpoint = 'https://web.push.apple.com/QG'.bin2hex(random_bytes(8));
        PushSubscription::create(['user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'p256dh' => Vapid::b64u($pub), 'auth' => Vapid::b64u($auth)]);
        $this->device($user);

        $this->assertSame(['devices' => 2, 'delivered' => 2], app(PushNotifier::class)->test($user));

        Http::assertSent(function (HttpRequest $req) use ($endpoint, $key, $pub, $auth) {
            if ($req->url() !== $endpoint) {
                return false;
            }
            $msg = json_decode(PayloadCipher::decrypt($req->body(), $key, $pub, $auth), true);

            return $msg['url'] === route('account.dashboard', [], false) && str_starts_with($req->header('Authorization')[0], 'vapid t=');
        });
        Http::assertSent(fn (HttpRequest $req) => $req->url() === self::SEND_URL
            && $req['message']['data']['url'] === route('account.dashboard'));
    }

    public function test_fcm_alone_leaves_web_subscriptions_and_the_web_card_untouched(): void
    {
        $this->fcmOn();
        $this->fakeGoogle();
        $user = User::factory()->create();
        $endpoint = 'https://web.push.apple.com/QGold';
        $sub = PushSubscription::create(['user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'p256dh' => 'x', 'auth' => 'y']);

        // มีแต่เว็บ แต่ VAPID ปิด = ไม่มีเครื่องที่ส่งได้ (ไม่จดว่าส่งแล้ว)
        $this->assertSame('no_device', app(PushNotifier::class)->readingReady($this->reading($user)->id));
        $this->device($user);
        $this->assertSame(['devices' => 1, 'delivered' => 1], app(PushNotifier::class)->test($user));
        $this->assertSame(0, $sub->fresh()->fail_count, 'ไม่มี VAPID ต้องไม่ลองส่ง Web Push (ไม่งั้นนับพลาดจนเครื่องเว็บถูกลบ)');
        Http::assertNotSent(fn (HttpRequest $req) => $req->url() === $endpoint);

        $html = $this->actingAs($user)->get(route('account.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('pushSettings(', $html);
        $this->assertStringNotContainsString('jw-push-key', $html);
        [, $p] = Vapid::newKeyPair();
        $this->actingAs($user)->postJson('/push/subscribe', ['endpoint' => 'https://web.push.apple.com/QGnew',
            'keys' => ['p256dh' => Vapid::b64u($p), 'auth' => Vapid::b64u(random_bytes(16))]])->assertStatus(503);
    }

    public function test_web_push_alone_ignores_app_devices(): void
    {
        $keys = Vapid::generateKeys();
        config(['webpush.public_key' => $keys['public'], 'webpush.private_key' => $keys['private']]);
        $user = User::factory()->create();
        $device = $this->device($user);

        $this->assertSame('no_device', app(PushNotifier::class)->readingReady($this->reading($user)->id));
        $this->assertSame(['devices' => 0, 'delivered' => 0], app(PushNotifier::class)->test($user));
        Http::assertNothingSent();
        $this->assertModelExists($device);
    }

    public function test_admin_page_shows_both_channels(): void
    {
        $this->fcmOn();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->device($admin);

        $this->actingAs($admin)->get('/admin/push-notifications')->assertOk()
            ->assertSee('FCM (แอป Android) พร้อม')
            ->assertSee('ยังไม่มีกุญแจ VAPID ใน .env')
            ->assertSee('แอป Android 1 คน · 1 เครื่อง');
    }
}
