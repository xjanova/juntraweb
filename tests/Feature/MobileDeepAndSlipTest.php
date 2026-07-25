<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ให้แอพตามเว็บให้ทัน — Deep 39฿ + ตรวจสลิปอัตโนมัติ + หมวดคำถาม
 *
 * ทุกอย่างต้องใช้กติกาชุดเดียวกับเว็บ ไม่ใช่เขียนใหม่ (session นี้เจอมาแล้วว่า
 * พอมีของสองชุด มัน drift เสมอ และรอบนั้นทำให้ผู้ใช้แอพยิง AI ฟรีไม่จำกัด)
 */
class MobileDeepAndSlipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function member(float $credit = 100): User
    {
        Setting::put('pricing_deep', '39', 'pricing', false);
        Setting::put('promptpay_id', '0812345678', 'pricing', false);
        Cache::flush();

        $u = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
        if ($credit > 0) {
            app(WalletService::class)->credit($u, $credit, 'seed');
        }

        return $u;
    }

    /* ── Deep 39฿ ─────────────────────────────────────────── */

    public function test_deep_show_returns_price_and_balance(): void
    {
        $user = $this->member();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/deep')
            ->assertOk()
            ->assertJsonPath('data.cost', 39)
            ->assertJsonPath('data.max_questions', 3);
    }

    public function test_deep_charges_once_and_returns_the_reading(): void
    {
        $user = $this->member();
        Http::fake(['*/juntra/fortune/deep' => Http::response(['data' => [
            'reading' => '**ภาพรวม** ดวงงานกำลังเปิดค่ะ', 'ai_provider' => 'openai',
        ]], 200)]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/deep', [
                'birth_date' => '1990-08-12',
                'questions'  => ['ปีนี้การงานเป็นอย่างไร'],
            ])
            ->assertStatus(201);

        $this->assertStringContainsString('ดวงงานกำลังเปิด', $res->json('data.reading'));
        $this->assertEqualsWithDelta(61.0, (float) $res->json('data.balance'), 0.01);
        $this->assertSame(1, Reading::where('type', 'deep')->count());
    }

    /** ไม่ได้คำทำนาย = ไม่มีสินค้า → ต้องคืนเงินเต็ม */
    public function test_deep_refunds_when_upstream_is_down(): void
    {
        $user = $this->member();
        Http::fake(['*/juntra/fortune/deep' => Http::response([], 503)]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/deep', ['questions' => ['ปีนี้เป็นอย่างไร']])
            ->assertStatus(503)
            ->assertJsonPath('reason_code', 'upstream_unavailable');

        $this->assertSame(0, Reading::where('type', 'deep')->count());
        $this->assertEqualsWithDelta(100.0, app(WalletService::class)->balance($user), 0.01);
    }

    public function test_deep_returns_402_without_charging_when_credit_is_low(): void
    {
        $user = $this->member(credit: 10);
        Http::fake(['*/juntra/fortune/deep' => Http::response(['data' => ['reading' => 'x']], 200)]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/deep', ['questions' => ['ปีนี้เป็นอย่างไร']])
            ->assertStatus(402)
            ->assertJsonPath('reason_code', 'insufficient_funds');

        $this->assertEqualsWithDelta(10.0, app(WalletService::class)->balance($user), 0.01);
    }

    /** ส่งซ้ำด้วย Idempotency-Key เดิม (เน็ตกระตุก) ต้องตัดเงินรอบเดียว */
    public function test_deep_double_send_with_same_key_charges_once(): void
    {
        $user = $this->member();
        Http::fake(['*/juntra/fortune/deep' => Http::response(['data' => ['reading' => 'คำทำนาย']], 200)]);

        $payload = ['questions' => ['ปีนี้เป็นอย่างไร']];
        $headers = ['Idempotency-Key' => 'deep-key-1'];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/deep', $payload, $headers)->assertStatus(201);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/deep', $payload, $headers)->assertStatus(409);

        $this->assertSame(1, Reading::where('type', 'deep')->count());
        $this->assertEqualsWithDelta(61.0, app(WalletService::class)->balance($user), 0.01);
    }

    /* ── ตรวจสลิปอัตโนมัติฝั่งแอพ ─────────────────────────── */

    public function test_mobile_slip_upload_credits_when_verification_passes(): void
    {
        Storage::fake('local');
        $user = $this->member(credit: 0);

        $tx = app(WalletService::class)->recordPendingTopup($user, 100.07, null, 'promptpay');

        Http::fake(['*/juntra/payment/verify-slip' => Http::response(['data' => [
            'ok' => true, 'trans_ref' => 'TR-MOBILE-1', 'amount' => 100.07,
            'receiver_matches' => true,
        ]], 200)]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/wallet/topup/{$tx->id}/slip", ['slip' => UploadedFile::fake()->image('s.jpg')])
            ->assertOk();

        $this->assertTrue($res->json('data.paid'));
        $this->assertSame('success', WalletTransaction::find($tx->id)->status);
        $this->assertEqualsWithDelta(100.07, (float) $res->json('data.balance'), 0.01);
    }

    /** ระบบตรวจล่ม → เก็บสลิปไว้ให้แอดมิน ไม่ปฏิเสธลูกค้า */
    public function test_mobile_slip_falls_back_to_manual_when_verifier_is_down(): void
    {
        Storage::fake('local');
        $user = $this->member(credit: 0);
        $tx = app(WalletService::class)->recordPendingTopup($user, 50.03, null, 'promptpay');

        Http::fake(['*' => Http::response([], 503)]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/wallet/topup/{$tx->id}/slip", ['slip' => UploadedFile::fake()->image('s.jpg')])
            ->assertOk();

        $this->assertFalse($res->json('data.paid'));
        $this->assertSame('pending', WalletTransaction::find($tx->id)->status);
        $this->assertNotNull(WalletTransaction::find($tx->id)->slip_path);
    }

    public function test_mobile_slip_rejects_payment_to_another_account(): void
    {
        Storage::fake('local');
        $user = $this->member(credit: 0);
        $tx = app(WalletService::class)->recordPendingTopup($user, 50.03, null, 'promptpay');

        Http::fake(['*/juntra/payment/verify-slip' => Http::response(['data' => [
            'ok' => true, 'trans_ref' => 'TR-X', 'amount' => 999, 'receiver_matches' => false,
        ]], 200)]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/wallet/topup/{$tx->id}/slip", ['slip' => UploadedFile::fake()->image('s.jpg')])
            ->assertOk();

        $this->assertFalse($res->json('data.paid'));
        $this->assertSame('pending', WalletTransaction::find($tx->id)->status);
    }

    /* ── รูปทรง response ที่แอพพึ่งพา ─────────────────────── */

    /**
     * การ์ดเติมเงินในแอพอ่าน id / payable_amount / promptpay.qr_payload
     * ถ้าคีย์ใดหาย: QR ว่าง หรือ poll สถานะไม่ทำงาน (เงินเข้าแล้วแต่จอไม่เปลี่ยน)
     * เคยพลาดมาแล้วตอน wallet_screen อ่าน qr_payload ผิดชั้น
     */
    public function test_mobile_topup_payload_has_the_keys_the_app_reads(): void
    {
        $user = $this->member();

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => [
                'id', 'payable_amount', 'auto_confirm',
                'transaction' => ['id', 'status'],
                'promptpay'   => ['id', 'name', 'qr_payload', 'qr_svg'],
            ]]);

        $this->assertSame($res->json('data.id'), $res->json('data.transaction.id'));
        $this->assertNotEmpty($res->json('data.promptpay.qr_payload'));
        // ยอดต้องมีเศษสตางค์ ไม่งั้นตัวจับคู่ SMS ทำงานไม่ได้
        $this->assertNotSame(100.0, (float) $res->json('data.payable_amount'));
    }

    /** QR ของแอพต้องใช้บัญชีของแม่หมอเหมือนเว็บ ไม่ใช่ค่าที่ตั้งในเว็บ */
    public function test_mobile_topup_uses_the_maemor_account(): void
    {
        Setting::put('promptpay_id', '0899999999', 'pricing', false);
        Cache::flush();

        Http::fake(['*/juntra/payment/account' => Http::response(['data' => [
            'promptpay_id' => '0811111111', 'account_name' => 'แม่หมอจันทรา',
        ]], 200)]);

        $user = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100])
            ->assertStatus(201);

        $this->assertStringContainsString('0066811111111', (string) $res->json('data.promptpay.qr_payload'));
        $this->assertSame('แม่หมอจันทรา', $res->json('data.promptpay.name'));
    }

    /* ── หมวดคำถาม ────────────────────────────────────────── */

    public function test_topics_endpoint_matches_the_web_catalogue(): void
    {
        $user = $this->member();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/chat/topics')->assertOk();

        $topics = $res->json('data');
        $this->assertCount(5, $topics);
        $this->assertSame(
            \App\Support\ChatSuggestions::topics(),
            $topics,
            'หมวดคำถามในแอพต้องเป็นชุดเดียวกับเว็บ',
        );
    }
}
