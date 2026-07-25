<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
        $user = $this->member();

        $id = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');

        $this->actingAs($user)
            ->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('slip.jpg')])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull(WalletTransaction::find($id)->slip_path);
    }

    /** สลิปใบเดียว = จ่ายครั้งเดียว ห้ามเอาไปเคลมสองรายการ */
    public function test_same_slip_cannot_be_reused_for_a_second_topup(): void
    {
        Storage::fake('local');
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
