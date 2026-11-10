<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\SmsCheckerDeviceRequests;
use Tests\TestCase;

/**
 * 🏦 (2026-11-11) หน้าเติมเงิน /wallet/topup — ช่องทาง "โอนเข้าบัญชีกสิกรไทย"
 *
 * เจ้าของ: "ทำหน้านี้ใหม่ให้สวยงาม และเพิ่มกสิกรด้วย"
 *
 * สิ่งที่ล็อกไว้:
 *   - เลขบัญชีมาจากระบบแม่หมอ (บัญชีเดียวกับ QR) เท่านั้น — ไม่มีให้ = ไม่โชว์ช่องทางนี้เลย
 *   - เลือกกสิกร = ภายในยังเป็น method 'promptpay' ยอดมีเศษสตางค์ → SMS กสิกรเข้า = เครดิตเอง
 *   - เลือกกสิกร + แนบสลิป = ตรวจสลิปอัตโนมัติเหมือนพร้อมเพย์
 *   - หน้าสถานะรายการโชว์บัญชีที่จดไว้กับรายการ พร้อมปุ่มคัดลอก
 */
class TopupKbankChannelTest extends TestCase
{
    use RefreshDatabase;
    use SmsCheckerDeviceRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** รูปแบบเดียวกับที่ Thaiprompt ตอบ /juntra/payment/account อยู่บน prod */
    private function account(bool $smsChecker = true): array
    {
        return ['data' => [
            'promptpay_id'        => '0811111111',
            'promptpay_type'      => 'phone',
            'account_name'        => 'จันทราพยากรณ์',
            'account_number'      => '2323775349',
            'bank_code'           => 'KBANK',
            'bank_name'           => 'ธนาคารกสิกรไทย',
            'sms_checker_enabled' => $smsChecker,
        ]];
    }

    private function member(bool $smsChecker = true, array $extraFakes = []): User
    {
        Http::fake(array_merge(
            ['*/juntra/payment/account' => Http::response($this->account($smsChecker), 200)],
            $extraFakes,
        ));
        Cache::flush();

        return User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
    }

    public function test_topup_page_offers_the_kasikorn_account_next_to_promptpay(): void
    {
        $user = $this->member();

        $this->actingAs($user)->get('/wallet/topup')
            ->assertOk()
            ->assertSee('0811111111')                 // พร้อมเพย์ยังอยู่
            ->assertSee('value="kbank"', false)       // ตัวเลือกกสิกร
            ->assertSee('ธนาคารกสิกรไทย')
            ->assertSee('232-3-77534-9')
            ->assertSee('จันทราพยากรณ์')
            ->assertSee('คัดลอกเลขบัญชี')
            // โลโก้จริง + เสียงอธิบายเรื่องเศษสตางค์ (ไฟล์ต้องอยู่จริง ไม่ใช่ลิงก์ตาย)
            ->assertSee('images/banks/kbank.svg', false)
            ->assertSee('images/banks/promptpay.png', false)
            ->assertSee('media/topup/why-satang.mp3', false);

        foreach (['images/banks/kbank.svg', 'images/banks/promptpay.png', 'images/banks/thai-qr-payment.png', 'media/topup/why-satang.mp3'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }

    /** ตัวจับ SMS ปิด = ไม่มีเศษสตางค์ → ห้ามเปิดเสียงอธิบายเรื่องสตางค์ให้ลูกค้างง */
    public function test_satang_narration_is_hidden_when_sms_matching_is_off(): void
    {
        config(['smschecker.enabled' => false]);
        $user = $this->member();

        $this->actingAs($user)->get('/wallet/topup')
            ->assertOk()
            ->assertDontSee('media/topup/why-satang.mp3', false);
    }

    /** บัญชีไม่ได้เปิดตัวจับ SMS = Thaiprompt ไม่ส่งเลขบัญชี → ห้ามโชว์ช่องทางกสิกร (เงินเข้าแล้วบิลไม่ตัด) */
    public function test_topup_page_hides_kasikorn_when_the_account_is_not_sms_checked(): void
    {
        $user = $this->member(smsChecker: false);

        $this->actingAs($user)->get('/wallet/topup')
            ->assertOk()
            ->assertSee('0811111111')
            ->assertDontSee('value="kbank"', false)
            ->assertDontSee('232-3-77534-9');
    }

    public function test_kasikorn_topup_gets_a_unique_amount_and_an_sms_credit_lands_in_the_wallet(): void
    {
        $user = $this->member();

        $this->actingAs($user)->post('/wallet/topup', ['amount' => 100, 'method' => 'kbank'])
            ->assertRedirect();

        $tx = WalletTransaction::where('user_id', $user->id)->latest('id')->firstOrFail();
        // ภายในต้องเป็น promptpay — ตัวจับ SMS จับคู่เฉพาะ method นี้
        $this->assertSame('promptpay', $tx->method);
        $this->assertSame('kbank', $tx->meta['channel']);
        $this->assertSame('2323775349', $tx->meta['transfer']['account_number']);
        $this->assertNotSame(100.0, (float) $tx->amount, 'ยอดต้องมีเศษสตางค์ไว้จับคู่ SMS');

        // หน้าสถานะรายการโชว์บัญชีกสิกรพร้อมยอดที่ต้องโอน
        $payable = number_format((float) $tx->amount, 2, '.', '');
        $this->actingAs($user)->get(route('wallet.topup.show', $tx))
            ->assertOk()
            ->assertSee('โอนเข้าบัญชีกสิกรไทย')
            ->assertSee('232-3-77534-9')
            ->assertSee('฿'.number_format((float) $tx->amount, 2));

        // SMS กสิกรยอดตรงเข้ามา → เครดิตเข้าเอง
        $req = $this->notifyRequest($this->device('auto'), [
            'bank' => 'KBANK', 'type' => 'credit', 'amount' => $payable,
            'sender_or_receiver' => 'ลูกค้า', 'reference_number' => 'KBANK-WEB-1',
            'sms_timestamp' => (int) round(microtime(true) * 1000) + 1000,
        ]);
        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])
            ->assertOk()
            ->assertJsonPath('data.matched', true);

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame((float) $payable, app(WalletService::class)->balance($user));
    }

    public function test_kasikorn_topup_with_a_slip_is_auto_verified(): void
    {
        Storage::fake('local');
        $user = $this->member(extraFakes: [
            '*/juntra/payment/verify-slip' => Http::response(['data' => [
                'ok' => true, 'trans_ref' => 'TR'.Str::random(10), 'amount' => 100,
                'receiver_matches' => true, 'sender_name' => 'นางสาวทดสอบ', 'message' => '',
            ]], 200),
        ]);

        $this->actingAs($user)->post('/wallet/topup', [
            'amount' => 100, 'method' => 'kbank', 'slip' => UploadedFile::fake()->image('kbank.jpg'),
        ])->assertRedirect();

        $tx = WalletTransaction::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('promptpay_slip', $tx->method);
        $this->assertSame('kbank', $tx->meta['channel']);
        $this->assertSame('success', $tx->status);
        $this->assertSame(100.0, app(WalletService::class)->balance($user));
    }

    /** ฟอร์มเก่าค้างในแท็บ / แอดมินปิดตัวจับ SMS ระหว่างนั้น → ต้องบอกตรง ๆ ไม่สร้างรายการที่โอนไปแล้วไม่ตัด */
    public function test_kasikorn_is_refused_when_no_transfer_account_is_available(): void
    {
        $user = $this->member(smsChecker: false);

        $this->actingAs($user)->from('/wallet/topup')
            ->post('/wallet/topup', ['amount' => 100, 'method' => 'kbank'])
            ->assertRedirect('/wallet/topup')
            ->assertSessionHasErrors('method');

        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->count());
    }

    /** พร้อมเพย์เดิมยังเหมือนเดิม — แค่จดช่องทางไว้เพิ่ม */
    public function test_promptpay_topup_still_works_and_records_its_channel(): void
    {
        $user = $this->member();

        $this->actingAs($user)->post('/wallet/topup', ['amount' => 50, 'method' => 'promptpay'])
            ->assertRedirect();

        $tx = WalletTransaction::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('promptpay', $tx->method);
        $this->assertSame('promptpay', $tx->meta['channel']);
        $this->actingAs($user)->get(route('wallet.topup.show', $tx))
            ->assertOk()
            ->assertSee('พร้อมเพย์ (สแกน QR)');
    }
}
