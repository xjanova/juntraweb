<?php

namespace Tests\Feature\Api;

use App\Models\PushDevice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Push\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 📲 (2026-11-12) สัญญา API หมวด G — แอป Android จด/เลิกจดเครื่องรับแจ้งเตือน (FCM) + เลือกหมวด
 *
 * ล็อกไว้:
 *  - จดซ้ำได้ (แถวเดียวต่อ token) · token ย้ายไปบัญชีที่ล็อกอินอยู่ · เพดานเครื่องต่อคน (ลบเครื่องที่ไม่ได้เปิดนานสุด)
 *  - จดได้แม้ยังไม่ตั้ง FCM (ตอบ fcm: false) · ลบได้เฉพาะเครื่องของตัวเอง
 *  - ออกจากระบบ/ลบบัญชี = เครื่องนั้นเลิกรับเรื่องของบัญชีเดิม
 *  - error เป็นภาษาไทยพร้อม reason_code · ไม่ล็อกอิน = 401
 *  - หมวดที่เลือกได้ชุดเดียวกับเว็บ หมวดแปลก ๆ ถูกกรองทิ้ง · แอปจาก Play ไม่เห็นหมวดยอดค้าง
 */
class PushDevicesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['fcm.service_account' => '', 'fcm.project_id' => '']);
    }

    private function fcmToken(string $tail = ''): string
    {
        return 'fGx1'.bin2hex(random_bytes(8)).':APA91b'.str_repeat('Q', 120).$tail;
    }

    public function test_a_member_registers_the_app_once_and_learns_fcm_is_off(): void
    {
        $user = Sanctum::actingAs(User::factory()->create());
        $token = $this->fcmToken();
        $body = ['token' => $token, 'platform' => 'android', 'channel' => 'play'];

        $this->postJson('/api/v1/push/devices', $body)->assertOk()
            ->assertExactJson(['data' => ['registered' => true, 'devices' => 1, 'fcm' => false]]);
        $this->postJson('/api/v1/push/devices', $body)->assertOk()->assertJsonPath('data.devices', 1);

        $this->assertSame(1, PushDevice::count());
        $device = PushDevice::first();
        $this->assertSame($user->id, $device->user_id);
        $this->assertSame($token, $device->token);
        $this->assertSame(PushDevice::hashToken($token), $device->token_hash);
        $this->assertSame(['android', 'play'], [$device->platform, $device->channel]);
        $this->assertArrayNotHasKey('token', $device->toArray(), 'token ห้ามหลุดออกไปกับ JSON ของโมเดล');
    }

    public function test_channel_falls_back_to_the_app_channel_header_and_platform_defaults_to_android(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->withHeader('X-App-Channel', 'direct')
            ->postJson('/api/v1/push/devices', ['token' => $this->fcmToken()])->assertOk();
        $this->withHeader('X-App-Channel', 'something-else')
            ->postJson('/api/v1/push/devices', ['token' => $this->fcmToken()])->assertOk();

        $this->assertSame(['direct', null], PushDevice::orderBy('id')->pluck('channel')->all());
        $this->assertSame(['android'], PushDevice::distinct()->pluck('platform')->all());
    }

    public function test_a_phone_moves_to_whoever_is_signed_in_now(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $token = $this->fcmToken();

        Sanctum::actingAs($a);
        $this->postJson('/api/v1/push/devices', ['token' => $token, 'platform' => 'android', 'channel' => 'direct'])->assertOk();
        Sanctum::actingAs($b);
        $this->postJson('/api/v1/push/devices', ['token' => $token, 'platform' => 'android', 'channel' => 'direct'])
            ->assertOk()->assertJsonPath('data.devices', 1);

        $this->assertSame([$b->id], PushDevice::pluck('user_id')->all());
    }

    public function test_devices_are_capped_per_member_and_the_least_recently_opened_one_goes(): void
    {
        config(['fcm.max_per_user' => 3]);
        $user = Sanctum::actingAs(User::factory()->create());
        $tokens = [];
        foreach (range(1, 3) as $i) {
            $this->travel(1)->minutes();
            $tokens[$i] = $this->fcmToken("-$i");
            $this->postJson('/api/v1/push/devices', ['token' => $tokens[$i], 'platform' => 'android'])->assertOk();
        }

        // เครื่องแรกเปิดแอปอีกครั้ง (จดซ้ำ) = ยังใช้อยู่ — เครื่องที่ 2 กลายเป็นเครื่องที่เงียบนานสุด
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/push/devices', ['token' => $tokens[1], 'platform' => 'android'])->assertOk();
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/push/devices', ['token' => $this->fcmToken('-4'), 'platform' => 'android'])
            ->assertOk()->assertJsonPath('data.devices', 3);

        $this->assertSame(3, PushDevice::where('user_id', $user->id)->count());
        $this->assertFalse(PushDevice::where('token_hash', PushDevice::hashToken($tokens[2]))->exists());
        $this->assertTrue(PushDevice::where('token_hash', PushDevice::hashToken($tokens[1]))->exists());
    }

    public function test_unregistering_removes_only_your_own_device(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $tokenA = $this->fcmToken('-a');
        $tokenB = $this->fcmToken('-b');
        PushDevice::create(['user_id' => $a->id, 'token' => $tokenA, 'token_hash' => PushDevice::hashToken($tokenA)]);
        PushDevice::create(['user_id' => $b->id, 'token' => $tokenB, 'token_hash' => PushDevice::hashToken($tokenB)]);

        Sanctum::actingAs($a);
        $this->deleteJson('/api/v1/push/devices', ['token' => $tokenB])->assertOk()
            ->assertExactJson(['data' => ['removed' => false, 'devices' => 1]]);
        $this->deleteJson('/api/v1/push/devices', ['token' => $tokenA])->assertOk()
            ->assertExactJson(['data' => ['removed' => true, 'devices' => 0]]);

        $this->assertSame([$b->id], PushDevice::pluck('user_id')->all());
    }

    public function test_signing_out_stops_that_phone_from_getting_the_old_accounts_news(): void
    {
        $user = User::factory()->create();
        $bearer = $user->createToken('android')->plainTextToken;

        $this->withToken($bearer)->postJson('/api/v1/push/devices', ['token' => $this->fcmToken(), 'platform' => 'android'])->assertOk();
        $this->assertNotNull(PushDevice::first()->personal_access_token_id);

        $this->withToken($bearer)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, PushDevice::count());
    }

    public function test_deleting_the_account_drops_its_app_devices(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(['password' => bcrypt('secret-pass-1')]));
        $this->postJson('/api/v1/push/devices', ['token' => $this->fcmToken(), 'platform' => 'android'])->assertOk();

        $this->postJson('/api/v1/account/delete', ['password' => 'secret-pass-1'])->assertOk();

        $this->assertFalse(PushDevice::where('user_id', $user->id)->exists());
    }

    public function test_bad_input_is_rejected_in_thai_with_a_reason_code(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cases = [
            [[], 'token'],
            [['token' => 'short'], 'token'],
            [['token' => 'has spaces '.str_repeat('x', 40)], 'token'],
            [['token' => ['not', 'a', 'string']], 'token'],
            [['token' => $this->fcmToken(), 'platform' => 'ios'], 'platform'],
            [['token' => $this->fcmToken(), 'channel' => 'beta'], 'channel'],
        ];
        foreach ($cases as [$body, $field]) {
            $res = $this->postJson('/api/v1/push/devices', $body)->assertStatus(422)
                ->assertJsonPath('reason_code', 'invalid_input')
                ->assertJsonValidationErrors($field);
            $this->assertMatchesRegularExpression('/\p{Thai}/u', $res->json('message'), json_encode($body));
            $this->assertStringNotContainsString('field', $res->json('message'));
        }
        $this->deleteJson('/api/v1/push/devices', [])->assertStatus(422)->assertJsonPath('reason_code', 'invalid_input');
        $this->putJson('/api/v1/push/preferences', [])->assertStatus(422)->assertJsonPath('reason_code', 'invalid_input');
        $this->putJson('/api/v1/push/preferences', ['muted' => 'daily'])->assertStatus(422);

        $this->assertSame(0, PushDevice::count());
    }

    public function test_guests_get_401_on_every_push_endpoint(): void
    {
        $this->postJson('/api/v1/push/devices', ['token' => $this->fcmToken()])->assertUnauthorized();
        $this->deleteJson('/api/v1/push/devices', ['token' => $this->fcmToken()])->assertUnauthorized();
        $this->getJson('/api/v1/push/preferences')->assertUnauthorized();
        $this->putJson('/api/v1/push/preferences', ['muted' => []])->assertUnauthorized();
        $this->assertSame(0, PushDevice::count());
    }

    public function test_preferences_round_trip_with_the_same_rules_as_the_web(): void
    {
        $user = Sanctum::actingAs(User::factory()->create());

        $this->withHeader('X-App-Channel', 'direct')->getJson('/api/v1/push/preferences')->assertOk()
            ->assertExactJson(['data' => [
                'muted' => [],
                'types' => [
                    ['key' => 'bill', 'name' => 'ยอดค้างชำระ', 'description' => 'บิลเติมเงินที่ยังไม่ได้โอน'],
                    ['key' => 'reading_ready', 'name' => 'คำทำนายพร้อมแล้ว', 'description' => 'คำทำนายอ่านเสร็จแล้ว'],
                    ['key' => 'daily', 'name' => 'ดวงประจำวัน', 'description' => 'ดวงประจำวันช่วงเช้า (วันละครั้ง)'],
                ],
                'quiet' => ['start' => '21:00', 'end' => '08:00'],
                'fcm' => false,
            ]]);

        $this->withHeader('X-App-Channel', 'direct')
            ->putJson('/api/v1/push/preferences', ['muted' => ['daily', 'nonsense', 'daily']])->assertOk()
            ->assertJsonPath('data.muted', ['daily'])
            ->assertJsonCount(3, 'data.types');
        $this->assertSame(['daily'], PushNotifier::mutedTypes($user->fresh()), 'ต้องเป็นค่าเดียวกับที่หน้าเว็บใช้');
        $this->getJson('/api/v1/push/preferences')->assertJsonPath('data.muted', ['daily']);

        $this->putJson('/api/v1/push/preferences', ['muted' => []])->assertOk()->assertJsonPath('data.muted', []);
        $this->assertSame([], PushNotifier::mutedTypes($user->fresh()));
    }

    public function test_the_play_build_and_admin_switched_off_kinds_are_not_offered(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Setting::put('push_type_daily_enabled', '0', 'push');

        foreach (['play', null] as $channel) {
            $req = $channel ? $this->withHeader('X-App-Channel', $channel) : $this->flushHeaders();
            $keys = collect($req->getJson('/api/v1/push/preferences')->assertOk()->json('data.types'))->pluck('key')->all();
            $this->assertSame(['reading_ready'], $keys, 'แอปจาก Play ห้ามเห็นหมวดยอดค้าง (ชวนไปจ่ายนอก Play) · หมวดที่แอดมินปิดไม่แสดง');
        }
    }
}
