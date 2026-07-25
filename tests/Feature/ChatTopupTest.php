<?php

namespace Tests\Feature;

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
 * เติมเงินจบในห้องแชท — โฟลว์เดียวกับที่บอท FB/LINE ให้ลูกค้า
 * "สแกนจ่ายครั้งเดียวจบ" โดยไม่ต้องเดินออกจากบทสนทนา
 *
 * สิ่งที่ล็อกไว้:
 *   - ยอดที่ต้องโอนมีเศษสตางค์ไม่ซ้ำ (ตัวจับคู่ SMS ธนาคารพึ่งค่านี้)
 *   - ยังไม่ตั้ง PromptPay ต้องบอกตรง ๆ ไม่ใช่ปล่อย QR ว่างให้ลูกค้างง
 *   - คนอื่นดูสถานะ/แนบสลิปรายการของเราไม่ได้
 *   - สลิปใบเดียวใช้ซ้ำสองรายการไม่ได้
 */
class ChatTopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // กันเทสต์ยิงเน็ตจริง — เคยทำให้เทสต์เดียวรอ timeout จริง 20 วินาที
        // ไม่ตั้ง catch-all ตรงนี้ เพราะ Http::fake ตัวที่ลงทะเบียน "ก่อน"
        // และตรง pattern จะชนะเสมอ catch-all ใน setUp จึงบัง stub เฉพาะของแต่ละเทสต์
        Http::preventStrayRequests();
    }

    /** จำลองว่าระบบตรวจสลิปใช้ไม่ได้ → ต้องตกไปให้แอดมินตรวจมือ */
    private function slipUnavailable(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
    }

    private function member(): User
    {
        Setting::put('promptpay_id', '0812345678', 'pricing', false);
        Setting::put('promptpay_name', 'ร้านแม่หมอ', 'pricing', false);
        Cache::flush();

        return User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
    }

    public function test_creates_topup_with_qr_and_unique_amount(): void
    {
        $user = $this->member();

        $res = $this->actingAs($user)
            ->postJson('/chat/topup', ['amount' => 100])
            ->assertOk()
            ->assertJsonStructure(['id', 'reference_code', 'base_amount', 'payable', 'qr', 'qr_payload', 'auto_confirm']);

        $payable = (float) $res->json('payable');

        $this->assertSame(100.0, (float) $res->json('base_amount'));
        // เศษสตางค์คือกุญแจจับคู่ SMS — ถ้าเท่ายอดกลมแปลว่ากลไกไม่ทำงาน
        $this->assertNotSame(100.0, $payable, 'ยอดที่ต้องโอนต้องมีเศษสตางค์ไม่ซ้ำ');
        $this->assertGreaterThan(100.0, $payable);
        $this->assertStringStartsWith('data:image/svg', (string) $res->json('qr'));

        $this->assertDatabaseHas('wallet_transactions', [
            'id'     => $res->json('id'),
            'type'   => 'topup',
            'status' => 'pending',
        ]);
    }

    public function test_reports_clearly_when_promptpay_is_not_configured(): void
    {
        $user = User::factory()->create(['thaiprompt_token' => 'tok-x']);
        Setting::put('promptpay_id', '', 'pricing', false);
        Cache::flush();

        $this->actingAs($user)
            ->postJson('/chat/topup', ['amount' => 100])
            ->assertStatus(503)
            ->assertJsonPath('reason_code', 'promptpay_unset');
    }

    /** หน้าแชท poll สถานะ — พอเงินเข้าต้องบอกว่า paid พร้อมยอดคงเหลือใหม่ */
    public function test_status_flips_to_paid_after_confirmation(): void
    {
        $user = $this->member();

        $id = $this->actingAs($user)
            ->postJson('/chat/topup', ['amount' => 50])
            ->assertOk()->json('id');

        $this->actingAs($user)->getJson("/chat/topup/{$id}")
            ->assertOk()
            ->assertJsonPath('paid', false);

        $tx = WalletTransaction::findOrFail($id);
        app(WalletService::class)->confirmTopupAuto($tx, ['test' => true]);

        $res = $this->actingAs($user)->getJson("/chat/topup/{$id}")->assertOk();
        $this->assertTrue($res->json('paid'));
        $this->assertEqualsWithDelta((float) $tx->amount, (float) $res->json('balance'), 0.01);
    }

    public function test_other_users_cannot_read_or_pay_someone_elses_topup(): void
    {
        $this->slipUnavailable();
        $owner = $this->member();
        $id = $this->actingAs($owner)->postJson('/chat/topup', ['amount' => 50])->json('id');

        $stranger = User::factory()->create(['thaiprompt_token' => 'tok-y']);

        $this->actingAs($stranger)->getJson("/chat/topup/{$id}")->assertForbidden();

        Storage::fake('local');
        $this->actingAs($stranger)
            ->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('slip.jpg')])
            ->assertForbidden();
    }

    public function test_slip_upload_attaches_to_the_pending_topup(): void
    {
        Storage::fake('local');
        $this->slipUnavailable();
        $user = $this->member();

        $id = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');

        $this->actingAs($user)
            ->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('slip.jpg')])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull(WalletTransaction::find($id)->slip_path);
    }

    /* ══════════ บัญชีรับเงิน — ต้องเป็นบัญชีเดียวกับแม่หมอ ══════════
       เจ้าของกำหนด (2026-07-25): ใช้ค่าเดิมของดูดวงแม่หมอชุดเดียวกัน
       ถ้าเว็บใช้คนละบัญชี ตัวตรวจสลิปจะเห็นว่าปลายทางไม่ใช่บัญชีเรา
       แล้วปฏิเสธทั้งที่ลูกค้าโอนถูก */

    public function test_qr_uses_the_maemor_account_not_the_local_setting(): void
    {
        // ตั้งค่าท้องถิ่นไว้คนละเบอร์ เพื่อพิสูจน์ว่าไม่ได้ถูกใช้
        Setting::put('promptpay_id', '0899999999', 'pricing', false);
        Setting::put('promptpay_name', 'บัญชีเว็บ (ไม่ควรถูกใช้)', 'pricing', false);
        Cache::flush();

        Http::fake(['*/juntra/payment/account' => Http::response(['data' => [
            'promptpay_id' => '0811111111',
            'account_name' => 'แม่หมอจันทรา',
            'bank_name'    => 'กสิกรไทย',
        ]], 200)]);

        $user = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);

        $res = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 100])->assertOk();

        $this->assertSame('แม่หมอจันทรา', $res->json('promptpay_name'));
        // เลข PromptPay ฝังอยู่ใน EMVCo payload — 0811111111 → 0066811111111
        $this->assertStringContainsString('0066811111111', (string) $res->json('qr_payload'));
        $this->assertStringNotContainsString('0066899999999', (string) $res->json('qr_payload'));
    }

    /** upstream ล่ม = ต้องยังเติมเงินได้ด้วยค่าที่ตั้งไว้เอง ไม่ใช่ตายทั้งระบบ */
    public function test_falls_back_to_local_account_when_maemor_is_unreachable(): void
    {
        $this->slipUnavailable();          // ทุก endpoint รวมถึง /payment/account = 503
        $user = $this->member();           // member() ตั้ง promptpay_id ท้องถิ่นไว้

        $res = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 100])->assertOk();

        $this->assertStringContainsString('0066812345678', (string) $res->json('qr_payload'));
    }

    /* ══════════ ตรวจสลิปอัตโนมัติ (SlipOK ผ่าน Thaiprompt) ══════════
       เส้นทางนี้แตะเงินโดยตรง — ทุกด่านต้องมีเทสต์ ไม่งั้นสลิปปลอม/สลิปคนอื่น/
       สลิปยอดน้อย/สลิปซ้ำ จะกลายเป็นเครดิตฟรี */

    private function fakeSlipOk(array $override = []): void
    {
        Http::fake(['*/juntra/payment/verify-slip' => Http::response(['data' => array_merge([
            'ok'               => true,
            'trans_ref'        => 'TR'.Str::random(10),
            'amount'           => 50.07,
            'receiver_matches' => true,
            'sender_name'      => 'นางสาวทดสอบ',
            'message'          => '',
        ], $override)], 200)]);
    }

    /** ผ่านครบทุกด่าน → เครดิตเข้าทันที ไม่ต้องรอแอดมิน */
    public function test_valid_slip_credits_the_wallet_immediately(): void
    {
        Storage::fake('local');
        $user = $this->member();
        $id = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');
        $payable = (float) WalletTransaction::find($id)->amount;

        $this->fakeSlipOk(['amount' => $payable]);

        $res = $this->actingAs($user)
            ->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('ok.jpg')])
            ->assertOk();

        $this->assertTrue($res->json('paid'));
        $this->assertSame('success', WalletTransaction::find($id)->status);
        $this->assertEqualsWithDelta($payable, (float) $res->json('balance'), 0.01);
    }

    /** โอนเข้าบัญชีคนอื่น → ห้ามเครดิต */
    public function test_slip_paid_to_another_account_is_not_credited(): void
    {
        Storage::fake('local');
        $user = $this->member();
        $id = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');

        $this->fakeSlipOk(['receiver_matches' => false]);

        $res = $this->actingAs($user)
            ->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('other.jpg')])
            ->assertOk();

        $this->assertFalse($res->json('paid'));
        $this->assertSame('pending', WalletTransaction::find($id)->status);
    }

    /** โอนน้อยกว่ายอดที่ต้องโอน → ห้ามเครดิตเต็ม */
    public function test_underpaid_slip_is_not_credited(): void
    {
        Storage::fake('local');
        $user = $this->member();
        $id = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 500])->json('id');

        $this->fakeSlipOk(['amount' => 100.0]);

        $res = $this->actingAs($user)
            ->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('short.jpg')])
            ->assertOk();

        $this->assertFalse($res->json('paid'));
        $this->assertSame('pending', WalletTransaction::find($id)->status);
        $this->assertSame(0.0, (float) app(WalletService::class)->balance($user));
    }

    /**
     * เลขอ้างอิงธนาคารเดิม = การโอนครั้งเดิม
     * (ถ่ายสลิปใหม่/ครอปใหม่ hash จะไม่ซ้ำ ตัวกัน hash จึงไม่พอ)
     */
    public function test_same_bank_reference_cannot_credit_twice(): void
    {
        Storage::fake('local');
        $user = $this->member();

        $first  = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');
        $second = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');

        $this->fakeSlipOk(['trans_ref' => 'TR-SAME-0001', 'amount' => 999]);

        $this->actingAs($user)
            ->postJson("/chat/topup/{$first}/slip", ['slip' => UploadedFile::fake()->image('a.jpg', 40, 40)])
            ->assertOk()->assertJsonPath('paid', true);

        // ถ่าย/ครอปสลิปใบเดิมใหม่ → ขนาดต่าง = hash ต่าง = ด่าน hash ไม่จับ
        // แต่เป็นการโอนครั้งเดียวกัน (trans_ref เดิม) จึงต้องถูกด่านนี้จับแทน
        $res = $this->actingAs($user)
            ->postJson("/chat/topup/{$second}/slip", ['slip' => UploadedFile::fake()->image('b.jpg', 80, 60)])
            ->assertOk();

        $this->assertFalse($res->json('paid'));
        $this->assertSame('pending', WalletTransaction::find($second)->status);
    }

    /** ระบบตรวจล่ม → ต้องส่งต่อให้แอดมิน ไม่ใช่ปฏิเสธสลิปลูกค้า */
    public function test_verifier_unavailable_falls_back_to_manual_review(): void
    {
        Storage::fake('local');
        $user = $this->member();
        $id = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');

        Http::fake(['*/juntra/payment/verify-slip' => Http::response([], 503)]);

        $res = $this->actingAs($user)
            ->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('x.jpg')])
            ->assertOk();

        $this->assertFalse($res->json('paid'));
        $this->assertSame('pending', WalletTransaction::find($id)->status);
        $this->assertNotNull(WalletTransaction::find($id)->slip_path, 'สลิปต้องยังถูกเก็บไว้ให้แอดมินดู');
    }

    /** สลิปใบเดียว = จ่ายครั้งเดียว ห้ามเอาไปเคลมสองรายการ */
    public function test_same_slip_cannot_be_reused_for_a_second_topup(): void
    {
        Storage::fake('local');
        $this->slipUnavailable();
        $user = $this->member();

        $first  = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');
        $second = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');

        $slip = UploadedFile::fake()->image('slip.jpg');
        $this->actingAs($user)->postJson("/chat/topup/{$first}/slip", ['slip' => $slip])->assertOk();

        // ไฟล์เนื้อหาเดียวกัน → hash เดียวกัน → ต้องถูกปฏิเสธ
        $again = UploadedFile::fake()->image('slip.jpg');
        $this->actingAs($user)
            ->postJson("/chat/topup/{$second}/slip", ['slip' => $again])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'duplicate_slip');
    }
}
