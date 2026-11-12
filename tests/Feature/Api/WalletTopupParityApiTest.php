<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\VoicePremium;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🏦 (2026-11-12) กระเป๋าเงินในแอพ = กติกาเดียวกับหน้า /wallet/topup (สัญญา API ข้อ B)
 *
 * สิ่งที่ล็อกไว้:
 *   - /v1/wallet บอกราคาครบ (รวมแพ็กเกจอ่านเบื้องหลัง พรีเมียมเสียง ไพ่ลึก) และกติกาเติมเงินชุดเดียวกับเว็บ
 *   - เลือกกสิกร = ภายในเป็น promptpay + จด meta.channel/transfer เหมือนเว็บ · ไม่มีบัญชี = 422 ไม่สร้างรายการ
 *   - X-App-Channel: play ไม่เห็นพร้อมเพย์/QR/เลขบัญชี และสร้างบิลนอก Google Play ไม่ได้
 */
class WalletTopupParityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // ไม่ได้ทดสอบการจองยอดเศษสตางค์ข้ามเว็บ (TopupKbankChannelTest ครอบแล้ว)
        config(['smschecker.enabled' => false]);
    }

    /** รูปแบบเดียวกับที่ Thaiprompt ตอบ /juntra/payment/account อยู่บน prod */
    private function member(bool $smsChecker = true): User
    {
        Http::fake(['*/juntra/payment/account' => Http::response(['data' => [
            'promptpay_id'        => '0811111111',
            'promptpay_type'      => 'phone',
            'account_name'        => 'จันทราพยากรณ์',
            'account_number'      => '2323775349',
            'bank_code'           => 'KBANK',
            'bank_name'           => 'ธนาคารกสิกรไทย',
            'sms_checker_enabled' => $smsChecker,
        ]], 200)]);
        Cache::flush();

        $user = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_wallet_carries_every_price_and_the_web_topup_rules(): void
    {
        $this->member();

        $res = $this->getJson('/api/v1/wallet')->assertOk();

        foreach (['tarot_single', 'tarot_quick', 'tarot_kunsai', 'voice_premium', 'deep', 'chat_message'] as $key) {
            $this->assertArrayHasKey($key, $res->json('data.pricing'), $key);
        }
        $this->assertEquals(VoicePremium::price(), $res->json('data.pricing.voice_premium'));

        $res->assertJsonPath('data.topup.bundles', [50, 100, 200, 500, 1000, 2000])
            ->assertJsonPath('data.topup.min', 20)
            ->assertJsonPath('data.topup.max', 50000)
            ->assertJsonPath('data.topup.channels', ['promptpay', 'kbank'])
            ->assertJsonPath('data.topup.kbank.bank', 'ธนาคารกสิกรไทย')
            ->assertJsonPath('data.topup.kbank.name', 'จันทราพยากรณ์')
            ->assertJsonPath('data.topup.kbank.number', '2323775349')
            ->assertJsonPath('data.topup.kbank.display_number', '232-3-77534-9');
    }

    public function test_kbank_is_not_offered_when_the_account_is_not_sms_checked(): void
    {
        $this->member(smsChecker: false);

        $this->getJson('/api/v1/wallet')->assertOk()
            ->assertJsonPath('data.topup.channels', ['promptpay'])
            ->assertJsonPath('data.topup.kbank', null);
    }

    public function test_play_channel_gets_no_topup_block(): void
    {
        $this->member();

        $res = $this->getJson('/api/v1/wallet', ['X-App-Channel' => 'play'])->assertOk();

        $this->assertArrayNotHasKey('topup', $res->json('data'));
        $this->assertArrayHasKey('pricing', $res->json('data'));
    }

    public function test_kbank_topup_is_stored_like_the_web_and_returns_the_account_card(): void
    {
        $user = $this->member();

        $res = $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100, 'method' => 'kbank'])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'kbank')
            ->assertJsonPath('data.kbank.number', '2323775349');

        $tx = WalletTransaction::findOrFail($res->json('data.id'));
        $this->assertSame($user->id, $tx->user_id);
        // ภายในต้องเป็น promptpay — ตัวจับ SMS จับคู่เฉพาะ method นี้
        $this->assertSame('promptpay', $tx->method);
        $this->assertSame('kbank', $tx->meta['channel']);
        $this->assertSame('2323775349', $tx->meta['transfer']['account_number']);

        $this->getJson('/api/v1/wallet/topup/'.$tx->id)->assertOk()
            ->assertJsonPath('data.channel', 'kbank')
            ->assertJsonPath('data.kbank.display_number', '232-3-77534-9')
            ->assertJsonPath('data.promptpay.id', '0811111111');
    }

    public function test_old_client_without_a_method_still_gets_promptpay(): void
    {
        $user = $this->member();

        $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 50])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'promptpay')
            ->assertJsonPath('data.promptpay.id', '0811111111');

        $tx = WalletTransaction::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('promptpay', $tx->meta['channel']);
    }

    public function test_kbank_without_an_account_is_refused_and_creates_nothing(): void
    {
        $user = $this->member(smsChecker: false);

        $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100, 'method' => 'kbank'])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'kbank_unavailable');

        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->count());
    }

    public function test_unknown_method_is_a_validation_error(): void
    {
        $this->member();

        $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100, 'method' => 'manual'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
    }

    public function test_play_channel_cannot_open_a_bill_or_see_its_account(): void
    {
        $user = $this->member();
        $play = ['X-App-Channel' => 'play'];

        $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100], $play)
            ->assertForbidden()
            ->assertJsonPath('reason_code', 'play_billing_only');
        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->count());

        // บิลที่เปิดจากแอพช่องอื่นไว้ก่อน — แอพ Play เห็นสถานะได้ แต่ไม่เห็น QR/เลขบัญชี และแนบสลิปไม่ได้
        $tx = $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100, 'method' => 'kbank'])
            ->assertCreated()->json('data.id');

        $this->getJson('/api/v1/wallet/topup/'.$tx, $play)->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.promptpay', null)
            ->assertJsonPath('data.kbank', null);

        $this->post('/api/v1/wallet/topup/'.$tx.'/slip', ['slip' => UploadedFile::fake()->image('s.jpg')], $play + ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('reason_code', 'play_billing_only');
    }
}
