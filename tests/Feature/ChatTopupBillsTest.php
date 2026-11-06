<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 💸 (2026-11-06 เจ้าของสั่ง) "การเลือกเติมเงินในแชทยังมีปัญหา ตรวจสอบหน่อย … ต้องรับเงินได้ไม่ผิดพลาด สำคัญ"
 *
 * สิ่งที่ล็อกไว้:
 *   - บิล QR ค้างได้ใบเดียวต่อคน: ขอยอดเท่าเดิม/น้อยกว่า = ได้ใบเดิม · ยอดมากกว่า = 409 ให้เลือก (ไม่ยกเลิกเอง)
 *   - ยกเลิกบิลในแชทได้ (บิลค้างเคยบล็อกโต๊ะไพ่ 48 ชม.) · บิลที่แนบสลิปแล้วยกเลิก/เปิด QR ซ้ำไม่ได้
 *   - สลิปยอดเกินบิล QR ยอดเฉพาะ = ให้แอดมินดู (เงินก้อนนั้นอาจเป็นของบิลอื่น — SMS มาทีหลังจะเครดิตซ้ำ)
 *   - เพดานบิลค้างไม่นับบิลหมดอายุ
 */
class ChatTopupBillsTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['smschecker.enabled' => true, 'chat_rooms.persona_upstream' => true]);
    }

    private function member(): User
    {
        Setting::put('promptpay_id', '0812345678', 'pricing', false);
        Setting::put('promptpay_name', 'ร้านแม่หมอ', 'pricing', false);
        Setting::put('tarot_quick_visible', '1', 'tarot', false);   // ห้องบุษบา (spread quick) เปิดอยู่
        Cache::flush();

        return User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
    }

    private function fakeSlipOk(array $override = []): void
    {
        Http::fake(['*/juntra/payment/verify-slip' => Http::response(['data' => array_merge([
            'ok' => true, 'trans_ref' => 'TR-'.Str::random(10), 'amount' => 0,
            'receiver_matches' => true, 'trans_timestamp' => now()->toIso8601String(),
            'sender_name' => 'นางสาวทดสอบ', 'message' => '',
        ], $override)], 200)]);
    }

    public function test_one_open_bill_per_customer_same_or_smaller_amount_gets_the_same_qr(): void
    {
        $user = $this->member();
        $a = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 100])->assertOk();
        $this->assertNotEmpty($a->json('cancel_url'));

        $b = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 100])->assertOk();
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame($a->json('payable'), $b->json('payable'));
        $this->assertTrue($b->json('reused'));

        $c = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->assertOk();
        $this->assertSame($a->json('id'), $c->json('id'));
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'topup')->count());
    }

    public function test_a_bigger_amount_never_stacks_a_second_bill_and_never_cancels_the_first_by_itself(): void
    {
        $user = $this->member();
        $a = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->assertOk();

        $this->actingAs($user)->postJson('/chat/topup', ['amount' => 200])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'pending_bill')
            ->assertJsonPath('bill.id', $a->json('id'))
            ->assertJsonPath('bill.reference_code', $a->json('reference_code'));

        $this->assertSame('pending', WalletTransaction::find($a->json('id'))->status, 'ห้ามยกเลิกบิลเดิมเอง — ลูกค้าอาจโอนไปแล้ว');
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'topup')->count());
    }

    public function test_the_customer_can_cancel_an_unpaid_bill_and_then_open_a_new_one(): void
    {
        $user = $this->member();
        $a = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->assertOk();

        $this->actingAs($user)->get('/chat?room=busaba')->assertOk()->assertViewHas('pendingBill', fn ($b) => $b !== null && $b['cancel_url'] !== '');

        $this->actingAs($user)->postJson(route('chat.topup.cancel', ['tx' => $a->json('id')]))
            ->assertOk()->assertJson(['ok' => true, 'status' => 'cancelled']);
        $this->assertSame('cancelled', WalletTransaction::find($a->json('id'))->status);

        // บิลหายจากโต๊ะ — การ์ดเปิดไพ่กลับมา
        $this->actingAs($user)->get('/chat?room=busaba')->assertOk()->assertViewHas('pendingBill', null);

        // ยกเลิกซ้ำ = 410 (ไม่มีอะไรต้องยกเลิก) · ออกบิลใหม่ยอดใหม่ได้
        $this->actingAs($user)->postJson(route('chat.topup.cancel', ['tx' => $a->json('id')]))->assertStatus(410);
        $b = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 200])->assertOk();
        $this->assertNotSame($a->json('id'), $b->json('id'));

        // บิลของคนอื่นยกเลิกไม่ได้
        $this->actingAs($this->member())->postJson(route('chat.topup.cancel', ['tx' => $b->json('id')]))->assertForbidden();
        $this->assertSame('pending', WalletTransaction::find($b->json('id'))->status);
    }

    /** โอนแล้ว SMS เข้ามาแล้ว (ยังไม่ผูกบิล) → ห้ามยกเลิก ไม่งั้นเงินก้อนนี้หาบิลไม่เจอ */
    public function test_a_bill_whose_bank_sms_already_arrived_cannot_be_cancelled(): void
    {
        $user = $this->member();
        $a = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->assertOk();
        $tx = WalletTransaction::find($a->json('id'));
        \App\Models\SmsPaymentNotification::create([
            'type' => 'credit', 'status' => 'pending', 'bank' => 'KBANK',
            'amount' => number_format((float) $tx->amount, 2, '.', ''),
            'sms_timestamp' => now()->addMinute(), 'device_id' => 'dev-1',
        ]);

        $this->actingAs($user)->postJson(route('chat.topup.cancel', ['tx' => $tx->id]))
            ->assertStatus(409)->assertJsonPath('reason_code', 'payment_seen');
        $this->assertSame('pending', $tx->fresh()->status);
    }

    public function test_a_paid_bill_cannot_be_cancelled(): void
    {
        $user = $this->member();
        $a = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->assertOk();
        app(WalletService::class)->confirmTopupAuto(WalletTransaction::find($a->json('id')), ['source' => 'sms']);

        $this->actingAs($user)->postJson(route('chat.topup.cancel', ['tx' => $a->json('id')]))
            ->assertStatus(410)->assertJson(['paid' => true]);
        $this->assertSame('success', WalletTransaction::find($a->json('id'))->status);
    }

    public function test_a_bill_with_a_slip_is_not_reshown_as_a_qr_nor_cancellable_and_does_not_hold_the_table(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response([], 503)]);   // ตรวจสลิปไม่ได้ → รอแอดมิน
        $user = $this->member();
        $id = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');
        $this->actingAs($user)->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('s.jpg')])->assertOk();

        $this->actingAs($user)->getJson(route('chat.topup.resume', ['tx' => $id]))
            ->assertStatus(409)->assertJsonPath('reason_code', 'has_slip');
        $this->actingAs($user)->postJson(route('chat.topup.cancel', ['tx' => $id]))
            ->assertStatus(409)->assertJsonPath('reason_code', 'has_slip');
        $this->assertSame('pending', WalletTransaction::find($id)->status);
        $this->assertNotNull(WalletTransaction::find($id)->slip_path, 'สลิปของลูกค้าต้องยังอยู่ให้แอดมินดู');

        $this->actingAs($user)->getJson(route('chat.topup.status', ['tx' => $id]))->assertOk()->assertJson(['has_slip' => true, 'paid' => false]);
        $this->actingAs($user)->get('/chat?room=busaba')->assertOk()->assertViewHas('pendingBill', null);
    }

    public function test_an_overpaid_slip_on_a_unique_amount_bill_waits_for_an_admin_instead_of_crediting(): void
    {
        Storage::fake('local');
        $user = $this->member();
        $id = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');
        $payable = (float) WalletTransaction::find($id)->amount;

        // ยอดในสลิปเกินบิล — อาจเป็นเงินของบิลอื่นที่ยอดเท่านี้พอดี
        $this->fakeSlipOk(['amount' => $payable + 50.21]);
        $res = $this->actingAs($user)->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('over.jpg')])->assertOk();

        $this->assertFalse($res->json('paid'));
        $this->assertSame('review', $res->json('decision'));
        $this->assertSame('pending', WalletTransaction::find($id)->status);
        $this->assertSame(0.0, (float) app(WalletService::class)->balance($user));
    }

    public function test_an_exact_slip_still_credits_at_once(): void
    {
        Storage::fake('local');
        $user = $this->member();
        $id = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->json('id');
        $payable = (float) WalletTransaction::find($id)->amount;

        $this->fakeSlipOk(['amount' => $payable]);
        $this->actingAs($user)->postJson("/chat/topup/{$id}/slip", ['slip' => UploadedFile::fake()->image('ok.jpg')])
            ->assertOk()->assertJsonPath('paid', true);
        $this->assertEqualsWithDelta($payable, (float) app(WalletService::class)->balance($user), 0.001);
    }

    public function test_expired_bills_do_not_count_toward_the_pending_cap_nor_hold_the_table(): void
    {
        config(['pricing.max_pending_topups' => 2]);
        $user = $this->member();
        $wallet = app(WalletService::class);
        foreach ([1, 2] as $i) {
            $wallet->recordPendingTopup($user, 50 + $i / 100, null, 'promptpay')->update(['expires_at' => now()->subHour()]);
        }
        $this->assertSame(0, $wallet->pendingTopupCount($user));

        $this->actingAs($user)->get('/chat?room=busaba')->assertOk()->assertViewHas('pendingBill', null);
        $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->assertOk();

        $this->actingAs($user)->getJson(route('chat.topup.status', ['tx' => WalletTransaction::where('user_id', $user->id)->oldest('id')->value('id')]))
            ->assertOk()->assertJson(['expired' => true, 'paid' => false]);
    }

    public function test_the_chat_page_ships_the_bill_cancel_and_poll_helpers(): void
    {
        $user = $this->member();
        $this->actingAs($user)->postJson('/chat/topup', ['amount' => 50])->assertOk();
        $html = $this->actingAs($user)->get('/chat?room=busaba')->assertOk()->getContent();

        $this->assertStringContainsString('cancelHoldBill()', $html);
        $this->assertStringContainsString('ยกเลิกบิล', $html);
        $this->assertStringContainsString('pollTopup(', $html);
        $this->assertStringContainsString('visibilitychange', $html);
    }
}
