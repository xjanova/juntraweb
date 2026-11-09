<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SmsPaymentNotification;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\SmsPayment\SmsCheckerService;
use App\Services\Wallet\SlipAutoVerifier;
use App\Services\Wallet\WalletService;
use App\Support\TopupAutoApprove;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\SmsCheckerDeviceRequests;
use Tests\TestCase;

/**
 * เจ้าของ (2026-11-09): "ถ้ายอดตรง sms checker ผ่าน หรือ slip ok ผ่าน ต้องอนุมัติให้เลย ไม่งั้นลูกค้าจะงง"
 *
 * เคสจริงคืนนั้น: บิล ฿39.80 ลูกค้าโอน ฿39.00 · บิล ฿60.59 ลูกค้าโอน ฿100 (โอนด้วยเลขบัญชี พิมพ์ยอดกลม)
 * → SMS ไม่ตรงทุกสตางค์ → แอดมินต้องอนุมัติมือ
 *
 * กติกาเงิน: เครดิตครั้งเดียวเสมอ (SMS กับสลิปชนกัน ส่งซ้ำ) · สลิปใช้ซ้ำไม่ได้ · ยอดขาดจริงห้ามอนุมัติเอง
 */
class TopupAutoApproveTest extends TestCase
{
    use RefreshDatabase;
    use SmsCheckerDeviceRequests;

    private const TP = 'https://main.thaiprompt.online';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put('topup-slips/slip.jpg', 'fake-image-bytes');
        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', '9f1c-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'super-secret-value', 'thaiprompt', true);
        $this->user = User::factory()->create();
    }

    /** บิลเติมเงินแบบที่แชทสร้าง: ยอดฐาน + เศษสตางค์จับคู่ SMS */
    private function bill(float $payable, float $base, ?User $user = null): WalletTransaction
    {
        $tx = app(WalletService::class)->recordPendingTopup($user ?? $this->user, $payable, null, 'promptpay');
        $tx->update(['meta' => array_merge((array) $tx->meta, ['base_amount' => $base, 'source' => 'chat'])]);

        return $tx->fresh();
    }

    private function slip(array $overrides = []): array
    {
        return array_merge([
            'ok' => true, 'error_code' => null, 'message' => '',
            'trans_ref' => '016313000000ABC12345', 'amount' => 39.80,
            'receiver_account' => 'xxx-x-x7534-x', 'receiver_name' => 'จันทราพยากรณ์', 'sender_name' => 'นาง ทดสอบ',
            'trans_timestamp' => now()->utc()->toIso8601String(),
            'receiver_matches' => true, 'slip_age_ok' => true,
            'used' => false, 'used_source' => null, 'used_by' => null,
        ], $overrides);
    }

    private function fakeSlipOk(array $verify): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::TP . '/oauth/token' => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
            self::TP . '/api/v1/juntra/server/slips/verify' => Http::response(['data' => $verify]),
            self::TP . '/api/v1/juntra/server/slips/claim' => Http::response(['data' => ['claimed' => true]], 201),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    private function checkSlip(WalletTransaction $tx): ?array
    {
        $tx->update(['slip_path' => 'topup-slips/slip.jpg']);

        return app(SlipAutoVerifier::class)->verify($tx->fresh(), $this->user, 'topup-slips/slip.jpg');
    }

    private function sms(string $amount): void
    {
        $device = $this->device('auto');
        $req = $this->notifyRequest($device, [
            'bank' => 'KBANK', 'type' => 'credit', 'amount' => $amount,
            'sender_or_receiver' => 'X-6756',
            'sms_timestamp' => (int) round(microtime(true) * 1000),
        ]);
        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])->assertOk();
    }

    private function balance(): float
    {
        return app(WalletService::class)->balance($this->user);
    }

    // ───────────────────────── SMS ─────────────────────────

    public function test_sms_with_exact_amount_credits_at_once_with_audit_label(): void
    {
        $tx = $this->bill(39.80, 39);

        $this->sms('39.80');

        $tx = $tx->fresh();
        $this->assertSame('success', $tx->status);
        $this->assertSame(39.80, $this->balance());
        $this->assertSame('sms', data_get($tx->meta, 'auto_approved.by'));
        $this->assertSame('อนุมัติอัตโนมัติโดย SMS', data_get($tx->meta, 'auto_approved.label'));
        $this->assertNull($tx->approved_by, 'ไม่มีแอดมินกด');
        $this->assertSame('confirmed', SmsPaymentNotification::first()->status);

        // ลูกค้าเห็นสถานะสำเร็จทันทีจากหน้าแชท (poll)
        $this->actingAs($this->user)->getJson(route('chat.topup.status', ['tx' => $tx->id]))
            ->assertOk()->assertJsonPath('paid', true)->assertJsonPath('credited', 39.8);
    }

    public function test_sms_without_the_satang_stays_pending_for_admin(): void
    {
        // บิลจริง TUP-HA9UNISF: ต้องโอน ฿39.80 โอนมา ฿39.00 — SMS ไม่มีตัวตนผู้โอน ห้ามเดาว่าเป็นของใคร
        $tx = $this->bill(39.80, 39);

        $this->sms('39.00');

        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, $this->balance());
        $this->assertSame('pending', SmsPaymentNotification::first()->status);
        $this->assertNull(SmsPaymentNotification::first()->matched_transaction_id);
    }

    public function test_sms_switch_off_matches_but_waits_for_admin(): void
    {
        TopupAutoApprove::set(TopupAutoApprove::SMS, false);
        $tx = $this->bill(39.80, 39);

        $this->sms('39.80');

        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, $this->balance());
        $this->assertSame('matched', SmsPaymentNotification::first()->status);
        $this->assertSame($tx->id, (int) SmsPaymentNotification::first()->matched_transaction_id);
    }

    public function test_replayed_sms_never_credits_twice(): void
    {
        $tx = $this->bill(39.80, 39);
        $device = $this->device('auto');
        $req = $this->notifyRequest($device, [
            'bank' => 'KBANK', 'type' => 'credit', 'amount' => '39.80',
            'sms_timestamp' => (int) round(microtime(true) * 1000),
        ]);

        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])->assertOk();
        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers']);

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(39.80, $this->balance());
    }

    // ───────────────────────── SlipOK ─────────────────────────

    public function test_slip_with_exact_amount_credits_at_once_with_audit_label(): void
    {
        $tx = $this->bill(39.80, 39);
        $this->fakeSlipOk($this->slip());

        $res = $this->checkSlip($tx);

        $this->assertTrue($res['paid']);
        $tx = $tx->fresh();
        $this->assertSame('success', $tx->status);
        $this->assertSame(39.80, $this->balance());
        $this->assertSame('slipok', data_get($tx->meta, 'auto_approved.by'));
        $this->assertSame('อนุมัติอัตโนมัติโดย SlipOK', data_get($tx->meta, 'auto_approved.label'));
        $this->assertNull(data_get($tx->meta, 'payable_amount'));
    }

    public function test_slip_without_the_satang_is_approved_for_what_was_really_paid(): void
    {
        // เคส TUP-HA9UNISF ถ้าลูกค้าแนบสลิป: ฿39.00 ให้บิล ฿39.80 = จ่ายครบราคาที่เลือก (฿39)
        $tx = $this->bill(39.80, 39);
        $this->fakeSlipOk($this->slip(['amount' => 39.00]));

        $res = $this->checkSlip($tx);

        $this->assertTrue($res['paid']);
        $this->assertSame(39.0, $res['credited']);
        $tx = $tx->fresh();
        $this->assertSame('success', $tx->status);
        $this->assertSame('39.00', (string) $tx->amount);
        $this->assertSame('39.80', (string) data_get($tx->meta, 'payable_amount'));
        $this->assertSame(39.0, $this->balance(), 'เครดิตตามยอดที่โอนจริง ไม่ใช่ยอดบิล');
    }

    public function test_slip_short_of_the_chosen_price_stays_pending(): void
    {
        $tx = $this->bill(39.80, 39);
        $this->fakeSlipOk($this->slip(['amount' => 38.00]));

        $res = $this->checkSlip($tx);

        $this->assertFalse($res['paid']);
        $this->assertSame('review', $res['decision']);
        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, $this->balance());
    }

    public function test_bill_without_base_amount_still_needs_the_full_payable(): void
    {
        // บิลแบบเก่า/ไม่มียอดฐาน — ห้ามเดายอดฐานเอง
        $tx = app(WalletService::class)->recordPendingTopup($this->user, 39.80, null, 'promptpay');
        $this->fakeSlipOk($this->slip(['amount' => 39.00]));

        $this->assertSame('review', $this->checkSlip($tx)['decision']);
        $this->assertSame('pending', $tx->fresh()->status);
    }

    public function test_overpaid_slip_credits_the_bill_and_tells_the_customer_about_the_rest(): void
    {
        // เคส TUP-UD38XNEM: บิล ฿60.59 โอนมา ฿100
        $tx = $this->bill(60.59, 60);
        $this->fakeSlipOk($this->slip(['amount' => 100.00]));

        $res = $this->checkSlip($tx);

        $this->assertTrue($res['paid']);
        $this->assertSame(60.59, $this->balance());
        $this->assertStringContainsString('โอนเกินมา', $res['message']);
        $this->assertSame(100.0, (float) data_get($tx->fresh()->meta, 'slip_amount'), 'ยอดในสลิปจริงเก็บไว้ให้แอดมินเติมส่วนต่าง');
    }

    public function test_reused_slip_is_rejected_on_the_second_bill(): void
    {
        $first = $this->bill(39.80, 39);
        $this->fakeSlipOk($this->slip());
        $this->assertTrue($this->checkSlip($first)['paid']);

        $second = $this->bill(39.41, 39);
        $this->fakeSlipOk($this->slip(['amount' => 39.80]));
        $res = $this->checkSlip($second);

        $this->assertSame('duplicate', $res['decision']);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(39.80, $this->balance(), 'สลิปใบเดียวเครดิตครั้งเดียว');
    }

    public function test_slip_switch_off_checks_the_slip_but_waits_for_admin(): void
    {
        TopupAutoApprove::set(TopupAutoApprove::SLIP, false);
        $tx = $this->bill(39.80, 39);
        $this->fakeSlipOk($this->slip());

        $res = $this->checkSlip($tx);

        $this->assertSame('review', $res['decision']);
        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, $this->balance());
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/slips/claim'));
    }

    // ───────────────────────── SMS × สลิป ชนกัน ─────────────────────────

    public function test_slip_arriving_after_sms_already_credited_reports_paid_and_credits_once(): void
    {
        $tx = $this->bill(39.80, 39);
        $stale = $tx->fresh(); // หน้าแชทถือบิลสถานะ pending ไว้ ขณะที่ SMS เครดิตไปแล้ว

        $this->sms('39.80');
        $this->fakeSlipOk($this->slip());
        $stale->update(['slip_path' => 'topup-slips/slip.jpg']); // ไม่เปลี่ยน status ใน DB
        $stale->status = 'pending';
        $res = app(SlipAutoVerifier::class)->verify($stale, $this->user, 'topup-slips/slip.jpg');

        $this->assertTrue($res['paid']);
        $this->assertSame(39.80, $this->balance(), 'เงินก้อนเดียว เครดิตครั้งเดียว');
    }

    public function test_sms_losing_the_race_to_a_slip_keeps_its_record_and_credits_once(): void
    {
        $tx = $this->bill(39.80, 39);

        // จำลองสลิปเครดิตบิลนี้ "ระหว่าง" ที่ SMS หาบิลเจอแล้วกำลังจะเครดิต
        $this->app->instance(WalletService::class, new class extends WalletService
        {
            public bool $raced = false;

            public function confirmTopupAuto(WalletTransaction $tx, array $meta = [], ?string $creditAmount = null): WalletTransaction
            {
                if (! $this->raced) {
                    $this->raced = true;
                    parent::confirmTopupAuto($tx, ['source' => 'slipok', 'auto_approved_by' => 'slipok']);
                }

                return parent::confirmTopupAuto($tx, $meta, $creditAmount);
            }
        });
        $this->app->forgetInstance(SmsCheckerService::class);

        $this->sms('39.80');

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(39.80, $this->balance());
        $sms = SmsPaymentNotification::first();
        $this->assertNotNull($sms, 'บันทึก SMS ต้องไม่หายเพราะชนกัน');
        $this->assertSame('confirmed', $sms->status);
        $this->assertSame($tx->id, (int) $sms->matched_transaction_id);
    }

    public function test_double_confirm_never_double_credits(): void
    {
        $tx = $this->bill(39.80, 39);
        $wallet = app(WalletService::class);

        $wallet->confirmTopupAuto($tx, ['auto_approved_by' => 'sms']);
        try {
            $wallet->confirmTopupAuto($tx, ['auto_approved_by' => 'slipok']);
            $this->fail('second confirm must throw');
        } catch (\RuntimeException) {
        }

        $this->assertSame(39.80, $this->balance());
        $this->assertSame('sms', data_get($tx->fresh()->meta, 'auto_approved.by'));
    }

    public function test_credit_override_outside_the_bill_range_is_refused(): void
    {
        $tx = $this->bill(39.80, 39);
        $wallet = app(WalletService::class);

        foreach (['40.00', '38.99', '0'] as $bad) {
            try {
                $wallet->confirmTopupAuto($tx->fresh(), [], $bad);
                $this->fail("credit {$bad} must be refused");
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, $this->balance());
    }
}
