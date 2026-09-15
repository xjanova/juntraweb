<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\SmsPayment\AmountReservation;
use App\Services\SmsPayment\SmsCheckerService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\SmsCheckerDeviceRequests;
use Tests\TestCase;

/**
 * ยอดเศษสตางค์ไม่ซ้ำ "ข้ามสองเว็บ" (สัญญา section C) — จองจาก Thaiprompt ก่อนให้ลูกค้าเห็นยอด
 *
 * สิ่งที่ล็อกไว้:
 *   - ยอดที่ลูกค้าเห็น/สแกน = ยอดที่จองได้จริง (ไม่ใช่ยอดที่คิดไว้ก่อนสร้างรายการ)
 *   - Thaiprompt ล่ม/ไม่ได้ตั้งค่า/พูลเต็ม → ยังเติมเงินได้ด้วยยอดไม่ซ้ำในเครื่อง
 *   - ยอดจองที่ชนกับรายการในเครื่อง / ยอดผิดช่วง → ห้ามใช้
 *   - รายการพ้น pending ทุกทาง (SMS, ยกเลิก, หมดอายุ, ปฏิเสธ) → คืนยอดที่จองไว้
 *   - คืนยอดล้มเหลว ห้ามทำให้เส้นทางเงินล้ม
 */
class AmountReservationTest extends TestCase
{
    use RefreshDatabase;
    use SmsCheckerDeviceRequests;

    private const BASE = 'https://tp.test';
    private const RESERVE = self::BASE . '/api/v1/juntra/server/amounts/reserve';
    private const RELEASE = self::BASE . '/api/v1/juntra/server/amounts/release';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutDefer(); // งานคืนยอดรันทันที จะได้ assert ได้

        Setting::put('promptpay_id', '0812345678', 'pricing', false);
        Setting::put('promptpay_name', 'ร้านทดสอบ', 'pricing', false);
    }

    private function configureThaiprompt(): void
    {
        Setting::put('thaiprompt_base_url', self::BASE);
        Setting::put('thaiprompt_client_id', 'juntra-client');
        Setting::put('thaiprompt_client_secret', 'juntra-secret');
        Cache::flush();
    }

    /**
     * @param  array<int, array{0:int,1:array}>|\Closure  $reserve  คิวคำตอบของ /amounts/reserve
     */
    private function fakeThaiprompt(array|\Closure $reserve, int $releaseStatus = 200): void
    {
        $this->configureThaiprompt();

        if (is_array($reserve)) {
            $seq = Http::sequence();
            foreach ($reserve as [$status, $body]) {
                $seq->push($body, $status);
            }
            $reserve = $seq;
        }

        Http::fake([
            self::BASE . '/oauth/token' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'srv-token'], 200),
            self::RESERVE               => $reserve,
            self::RELEASE               => Http::response(['data' => ['released' => $releaseStatus < 300]], $releaseStatus),
        ]);
    }

    private function reserved(int $id, string $amount, string $base = '100.00'): array
    {
        return [201, ['data' => [
            'id'            => $id,
            'unique_amount' => $amount,
            'base_amount'   => $base,
            'expires_at'    => now()->addDays(2)->toIso8601String(),
        ]]];
    }

    private function releaseCalls(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->url() === self::RELEASE)
            ->map(fn ($pair) => $pair[0]->data())
            ->values()->all();
    }

    private function reserveCalls(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->url() === self::RESERVE)
            ->map(fn ($pair) => $pair[0]->data())
            ->values()->all();
    }

    /* ─────────────────────────── reserve ─────────────────────────── */

    public function test_api_topup_charges_the_amount_reserved_at_thaiprompt(): void
    {
        $this->fakeThaiprompt([$this->reserved(77, '100.42')]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100])->assertCreated();

        // ยอดที่ส่งให้แอพ + QR = ยอดที่จองได้
        $this->assertSame(100.42, (float) $res->json('data.payable_amount'));
        $this->assertSame(100.0, (float) $res->json('data.base_amount'));
        $this->assertStringContainsString('5406100.42', (string) $res->json('data.promptpay.qr_payload'));

        $tx = WalletTransaction::findOrFail($res->json('data.id'));
        $this->assertSame('100.42', (string) $tx->amount);
        $this->assertTrue($tx->meta['amount_reserved']);
        $this->assertSame(77, $tx->meta['upa_id']);
        $this->assertSame($tx->reference_code, $tx->meta['upa_ref']);
        $this->assertEquals(100, $tx->meta['base_amount']);

        // สัญญา C1: ref = reference_code, external_id = id รายการ, ttl = อายุ pending (48 ชม.)
        $call = $this->reserveCalls()[0];
        $this->assertSame($tx->reference_code, $call['ref']);
        $this->assertSame($tx->id, $call['external_id']);
        $this->assertSame(2880, $call['ttl_minutes']);
        $this->assertSame('100.00', $call['base_amount']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::RESERVE && $r->hasHeader('Authorization', 'Bearer srv-token'));
    }

    public function test_chat_topup_shows_the_reserved_amount_in_its_qr(): void
    {
        $this->fakeThaiprompt([$this->reserved(78, '50.63', '50.00')]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->assertOk();

        $this->assertSame(50.63, (float) $res->json('payable'));
        $this->assertSame(50.0, (float) $res->json('base_amount'));
        $this->assertStringContainsString('540550.63', (string) $res->json('qr_payload'));
        $this->assertSame('chat', WalletTransaction::find($res->json('id'))->meta['source']);
    }

    public function test_web_topup_without_slip_is_reserved(): void
    {
        $this->fakeThaiprompt([$this->reserved(79, '200.11', '200.00')]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/wallet/topup', ['amount' => 200, 'method' => 'promptpay'])->assertRedirect();

        $tx = WalletTransaction::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('200.11', (string) $tx->amount);
        $this->assertSame('promptpay', $tx->method);
        $this->assertTrue($tx->meta['amount_reserved']);
    }

    /**
     * แนบสลิปมาตอนส่งฟอร์ม = ลูกค้าโอนยอดกลมที่พิมพ์ไปแล้ว → เก็บยอดเดิมเป๊ะ ไม่จองยอด
     * และใช้ method 'promptpay_slip' ที่ตัวจับ SMS (method='promptpay') ไม่มีวันจับคู่
     */
    public function test_web_topup_with_slip_keeps_the_typed_amount_and_is_never_sms_matched(): void
    {
        Storage::fake('local');
        $this->fakeThaiprompt([$this->reserved(80, '100.42')]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/wallet/topup', [
            'amount' => 100,
            'method' => 'promptpay',
            'slip'   => UploadedFile::fake()->image('paid.jpg'),
        ])->assertRedirect();

        $tx = WalletTransaction::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('100.00', (string) $tx->amount);
        $this->assertSame('promptpay_slip', $tx->method);
        $this->assertSame([], $this->reserveCalls(), 'ต้องไม่ขอจองยอดเมื่อจ่ายมาแล้ว');
        $this->assertNull(app(SmsCheckerService::class)->findMatchingTopup(100.00, null));
    }

    /* ─────────────────────────── fallback ─────────────────────────── */

    public function test_falls_back_to_a_local_unique_amount_when_thaiprompt_is_down(): void
    {
        $this->fakeThaiprompt([[503, ['message' => 'down']]]);
        $user = User::factory()->create();

        $tx = app(AmountReservation::class)->createPendingTopup($user, 100);

        $this->assertSame('100.01', (string) $tx->amount); // ยอดไม่ซ้ำตัวแรกในเครื่อง
        $this->assertFalse($tx->meta['amount_reserved']);
        $this->assertArrayNotHasKey('upa_id', $tx->meta);
        $this->assertEquals(100, $tx->meta['base_amount']);
    }

    public function test_not_configured_means_no_network_and_a_local_amount(): void
    {
        Http::fake(); // ถ้ามีการยิงจะถูกบันทึกไว้
        $user = User::factory()->create();

        $a = app(AmountReservation::class)->createPendingTopup($user, 100);
        $b = app(AmountReservation::class)->createPendingTopup($user, 100);

        Http::assertNothingSent();
        $this->assertNotSame((string) $a->amount, (string) $b->amount, 'ยอดในเครื่องต้องไม่ชนกันเอง');
        $this->assertFalse($a->meta['amount_reserved']);
    }

    public function test_pool_exhausted_falls_back_locally(): void
    {
        $this->fakeThaiprompt([[409, ['reason_code' => 'pool_exhausted']]]);
        $tx = app(AmountReservation::class)->createPendingTopup(User::factory()->create(), 100);

        $this->assertFalse($tx->meta['amount_reserved']);
        $this->assertSame('pending', $tx->status);
    }

    public function test_reserved_amount_colliding_with_a_local_fallback_bill_is_swapped(): void
    {
        $other = User::factory()->create();
        // รายการ fallback ที่สร้างตอน Thaiprompt ล่ม — ยังรอเงินอยู่ที่ ฿100.42
        app(WalletService::class)->recordPendingTopup($other, 100.42, null, 'promptpay');

        $this->fakeThaiprompt([$this->reserved(90, '100.42'), $this->reserved(91, '100.55')]);
        $tx = app(AmountReservation::class)->createPendingTopup(User::factory()->create(), 100);

        $this->assertSame('100.55', (string) $tx->amount);
        $this->assertSame(91, $tx->meta['upa_id']);
        $this->assertSame($tx->reference_code . '-R2', $this->reserveCalls()[1]['ref'], 'รอบสองต้องใช้ ref ใหม่ ไม่งั้นได้การจองเดิม');
        $this->assertSame([['id' => 90, 'ref' => $tx->reference_code, 'status' => 'cancelled']], $this->releaseCalls());
    }

    public function test_out_of_range_amount_from_upstream_is_never_charged(): void
    {
        $this->fakeThaiprompt([$this->reserved(92, '250.10')]);
        $tx = app(AmountReservation::class)->createPendingTopup(User::factory()->create(), 100);

        $this->assertSame('100.01', (string) $tx->amount);
        $this->assertFalse($tx->meta['amount_reserved']);
        $this->assertSame('cancelled', $this->releaseCalls()[0]['status']);
    }

    /** รายการถูกยืนยันไปก่อนระหว่างรอ Thaiprompt → ห้ามแก้ยอดทับ และคืนยอดที่เพิ่งจอง */
    public function test_topup_that_left_pending_during_the_call_is_not_repriced(): void
    {
        $this->fakeThaiprompt(function (HttpRequest $request) {
            $tx = WalletTransaction::findOrFail($request['external_id']);
            app(WalletService::class)->confirmTopupAuto($tx, ['test' => 'raced']);

            return Http::response(['data' => ['id' => 93, 'unique_amount' => '100.77', 'base_amount' => '100.00', 'expires_at' => null]], 201);
        });

        $tx = app(AmountReservation::class)->createPendingTopup(User::factory()->create(), 100);

        $this->assertSame('success', $tx->status);
        $this->assertSame('100.01', (string) $tx->amount, 'ยอดที่เครดิตไปแล้วต้องไม่ถูกเปลี่ยน');
        $this->assertSame([['id' => 93, 'ref' => $tx->reference_code, 'status' => 'cancelled']], $this->releaseCalls());
    }

    /* ─────────────────────────── release ─────────────────────────── */

    private function reservedTopup(int $upaId = 77, string $amount = '100.42'): WalletTransaction
    {
        $this->fakeThaiprompt([$this->reserved($upaId, $amount)]);

        return app(AmountReservation::class)->createPendingTopup(User::factory()->create(), 100);
    }

    public function test_sms_confirmation_releases_the_reservation_as_used(): void
    {
        $tx  = $this->reservedTopup();
        $req = $this->notifyRequest($this->device('auto'), [
            'type' => 'credit', 'amount' => '100.42', 'bank' => 'KBANK',
            'sms_timestamp' => (int) round(microtime(true) * 1000),
        ]);

        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame([['id' => 77, 'ref' => $tx->reference_code, 'status' => 'used']], $this->releaseCalls());
    }

    public function test_user_cancel_releases_the_reservation(): void
    {
        $tx = $this->reservedTopup();
        app(WalletService::class)->cancelTopup($tx, $tx->user);

        $this->assertSame([['id' => 77, 'ref' => $tx->reference_code, 'status' => 'cancelled']], $this->releaseCalls());
    }

    public function test_expiry_cleanup_releases_the_reservation(): void
    {
        $tx = $this->reservedTopup();
        $tx->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('wallet:cleanup-expired-topups')->assertSuccessful();

        $this->assertSame('failed', $tx->fresh()->status);
        $this->assertSame('cancelled', $this->releaseCalls()[0]['status'] ?? null);
    }

    public function test_admin_approval_and_rejection_release_the_reservation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->fakeThaiprompt([$this->reserved(81, '100.42'), $this->reserved(82, '100.43')]);
        $svc = app(AmountReservation::class);

        $approved = $svc->createPendingTopup(User::factory()->create(), 100);
        app(WalletService::class)->approveTopup($approved, $admin, 100.42);

        $rejected = $svc->createPendingTopup(User::factory()->create(), 100);
        app(WalletService::class)->rejectTopup($rejected, $admin, 'no money');

        $this->assertSame([
            ['id' => 81, 'ref' => $approved->reference_code, 'status' => 'used'],
            ['id' => 82, 'ref' => $rejected->reference_code, 'status' => 'cancelled'],
        ], $this->releaseCalls());
    }

    public function test_local_fallback_topup_never_calls_release(): void
    {
        $this->fakeThaiprompt([[503, []]]);
        $tx = app(AmountReservation::class)->createPendingTopup(User::factory()->create(), 100);

        app(WalletService::class)->confirmTopupAuto($tx, ['test' => true]);

        $this->assertSame([], $this->releaseCalls());
    }

    /** Thaiprompt ตอบ 500 ตอนคืนยอด — เงินของลูกค้าต้องเข้าตามปกติ */
    public function test_release_failure_never_breaks_the_money_path(): void
    {
        $this->fakeThaiprompt([$this->reserved(77, '100.42')], releaseStatus: 500);
        $tx = app(AmountReservation::class)->createPendingTopup(User::factory()->create(), 100);

        $done = app(WalletService::class)->confirmTopupAuto($tx, ['test' => true]);

        $this->assertSame('success', $done->status);
        $this->assertSame(100.42, app(WalletService::class)->balance($tx->user));
        $this->assertCount(1, $this->releaseCalls());
    }
}
