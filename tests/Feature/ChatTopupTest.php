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
use Tests\Feature\Concerns\SmsCheckerDeviceRequests;
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
    use SmsCheckerDeviceRequests;

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

    /**
     * ปุ่ม "สแกนจ่าย ฿X แล้วเปิดไพ่เลย" ส่งยอด = ที่ขาด (ปัดขึ้นเป็นบาท) ซึ่งไม่ใช่ยอดแพ็กเกจกลม ๆ
     * ล็อกว่า: รับยอดแปลก ๆ ได้ และเศษสตางค์ต้อง "บวกเพิ่ม" เสมอ
     * ถ้าวันไหนเศษถูกหักออก เงินเข้าแล้วยังเปิดไพ่ไม่ได้ ลูกค้าจ่ายแล้วติดค้าง
     */
    public function test_exact_shortfall_topup_always_covers_the_package(): void
    {
        $user = $this->member();
        $wallet = app(WalletService::class);
        $wallet->credit($user, 62.40, 'เครดิตเดิม');

        $price = 99.0;
        $shortfall = (int) ceil($price - 62.40);      // 37 — ตรงกับ shortfall() ฝั่ง JS

        $res = $this->actingAs($user)->postJson('/chat/topup', ['amount' => $shortfall])->assertOk();
        $this->assertSame((float) $shortfall, (float) $res->json('base_amount'));

        $wallet->confirmTopupAuto(WalletTransaction::findOrFail($res->json('id')), ['test' => true]);

        $balance = (float) $this->actingAs($user)->getJson('/chat/topup/'.$res->json('id'))->json('balance');
        $this->assertGreaterThanOrEqual($price, $balance, 'จ่ายตามยอดที่ขาดแล้วต้องพอเปิดแพ็กเกจ');
    }

    /**
     * 🧾 (2026-11-09) แตะแพ็กเกจบนโต๊ะตอนเครดิตไม่พอ ต้องไม่ออกบิลทันที — วางการ์ดให้กด "สแกนจ่าย" เองก่อน
     *   เคสจริง: ลูกค้าแตะ "เปิดไพ่ ฿39" ครั้งเดียว ได้บิล TUP-1ZZNSYZX ใน 10 วินาทีหลังเข้าห้อง
     *   บิลสร้างได้ทางเดียวคือ chooseAmount() ซึ่งเรียกจาก payAndOpen() — โต๊ะต้องไม่เรียก payAndOpen ตรง ๆ
     */
    public function test_table_package_without_credit_asks_before_creating_a_bill(): void
    {
        $user = $this->member();

        $html = $this->actingAs($user)->get('/chat')->assertOk()->getContent();

        preg_match('/openTable\(o\) \{(.*?)\n    \},/s', $html, $m);
        $this->assertNotEmpty($m, 'หา openTable() ในหน้าแชทไม่เจอ');
        $this->assertStringNotContainsString('payAndOpen', $m[1], 'แตะแพ็กเกจบนโต๊ะห้ามออกบิลทันที');
        $this->assertStringContainsString('this.confirmPay(o)', $m[1]);

        preg_match('/confirmPay\(o\) \{(.*?)\n    \},/s', $html, $c);
        $this->assertNotEmpty($c, 'หา confirmPay() ในหน้าแชทไม่เจอ');
        $this->assertStringNotContainsString('chooseAmount', $c[1], 'การ์ดยืนยันต้องไม่สร้างบิลเอง');
        $this->assertStringNotContainsString('payAndOpen', $c[1]);
        $this->assertStringContainsString("kind: 'confirm_pay'", $c[1]);
    }

    /** หน้าแชทต้องส่งขั้นต่ำ/สูงสุดเติมเงินให้ JS — ยอดที่ขาดต่ำกว่าขั้นต่ำต้องปัดขึ้น ไม่ใช่โดนปฏิเสธ */
    public function test_chat_page_offers_pay_and_open_with_topup_limits(): void
    {
        config(['pricing.min_topup' => 20, 'pricing.max_topup' => 50000]);
        $user = $this->member();

        $html = $this->actingAs($user)->get('/chat')->assertOk()->getContent();

        $this->assertStringContainsString('payAndOpen(m, o)', $html);
        $this->assertStringContainsString('minTopup: 20', $html);
        $this->assertStringContainsString('maxTopup: 50000', $html);
        $this->assertStringContainsString('หรือเติมเครดิตเก็บไว้หลายครั้ง', $html);

        // Alpine 3.15 ตั้ง disabled ให้ปุ่มเมื่อค่าเป็น undefined — offerBusy ไม่มีจนกว่าจะกด
        // ปุ่ม "เลือกไพ่เลย" ในแชทเลยกดไม่ได้ตั้งแต่ 2026-09-15 จนเจอ 2026-11-04 ต้องบังคับเป็น boolean เสมอ
        $this->assertStringNotContainsString(':disabled="m.offerBusy"', $html);
        $this->assertStringContainsString(':disabled="!!m.offerBusy"', $html);

        // ยอดต่ำกว่าขั้นต่ำ = ระบบไม่รับ → JS ต้องปัดขึ้นเป็น min_topup เสมอ
        $this->actingAs($user)->postJson('/chat/topup', ['amount' => 5])->assertStatus(422);
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

    /**
     * 🏦 (2026-11-09) การ์ดสแกนจ่ายในแชทแสดงเลขบัญชีให้โอนตรงด้วย แล้วเงินที่โอนเข้าบัญชีนั้นต้องเข้า wallet
     *   เจ้าของ: "มันแจ้งแต่ qrcode ให้แจ้งเลขบัญชีธนาคารด้วย" → "ต้องเป็นบัญชีกสิกร ไม่ใช่ไทยพาณิชย์"
     *   และ "ตรวจด้วยว่าเงินเข้าระบบ sms checker ... แล้วเงินเข้ากระเป๋าวอลเลตปกติไหม"
     *   บัญชีโอนตรง = บัญชีเดียวกับ QR จากฟิลด์เดิมของ /payment/account — SMS จับด้วยยอดเศษสตางค์
     */
    public function test_chat_bill_shows_the_transfer_account_and_a_transfer_to_it_lands_in_the_wallet(): void
    {
        // รูปแบบเดียวกับที่ Thaiprompt ตอบอยู่บน prod วันนี้ (ไม่ต้องรอฝั่งนั้นแก้)
        Http::fake(['*/juntra/payment/account' => Http::response(['data' => [
            'promptpay_id'        => '0811111111',
            'promptpay_type'      => 'phone',
            'account_name'        => 'จันทราพยากรณ์',
            'account_number'      => '2323775349',
            'bank_code'           => 'KBANK',
            'bank_name'           => 'ธนาคารกสิกรไทย',
            'sms_checker_enabled' => true,
        ]], 200)]);
        Cache::flush();
        $user = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);

        $res = $this->actingAs($user)->postJson('/chat/topup', ['amount' => 39])
            ->assertOk()
            ->assertJsonPath('transfer.bank_name', 'ธนาคารกสิกรไทย')
            ->assertJsonPath('transfer.account_number', '2323775349')
            ->assertJsonPath('transfer.display_number', '232-3-77534-9')
            ->assertJsonPath('transfer.account_name', 'จันทราพยากรณ์');
        $txId = (int) $res->json('id');
        $payable = number_format((float) $res->json('payable'), 2, '.', '');

        // รีเฟรชหน้าแล้วเปิดบิลเดิม = เลขบัญชีเดิมจากบิล ไม่ไปถาม Thaiprompt ใหม่
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs($user)->getJson(route('chat.topup.resume', ['tx' => $txId]))
            ->assertOk()
            ->assertJsonPath('transfer.account_number', '2323775349');

        // SMS กสิกรยอดตรงเข้ามา → บิลตัด เงินเข้า wallet
        $req = $this->notifyRequest($this->device('auto'), [
            'bank' => 'KBANK', 'type' => 'credit', 'amount' => $payable,
            'sender_or_receiver' => 'ลูกค้า', 'reference_number' => 'KBANK-REF-1',
            'sms_timestamp' => (int) round(microtime(true) * 1000) + 1000,
        ]);
        $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers'])
            ->assertOk()
            ->assertJsonPath('data.matched', true);

        $tx = WalletTransaction::findOrFail($txId);
        $this->assertSame('success', $tx->status);
        $this->assertSame((float) $payable, app(WalletService::class)->balance($user));

        // หน้าแชทเห็นว่าจ่ายแล้ว และประวัติ wallet มีรายการเติมเงินใบนี้
        $this->actingAs($user)->getJson(route('chat.topup.status', ['tx' => $txId]))
            ->assertOk()->assertJsonPath('paid', true);
        $this->actingAs($user)->get('/wallet')->assertOk()->assertSee($tx->reference_code);
    }

    /**
     * 🐛 (2026-11-09) บิลที่สร้างก่อนขึ้นเลขบัญชี (meta ไม่มี transfer) เปิดกลับมาแล้วเห็นแค่ QR
     *   เจ้าของ: "ทำไมในห้องแชทพยากรณ์ไม่เห็นมีบัญชีแม่หมอกสิกรมาด้วย เห็นมีแต่ qrcode" (บิล TUP-9DCWRR9W)
     *   → ยืมบัญชีปัจจุบันมาแสดง แต่เฉพาะเมื่อพร้อมเพย์ยังเป็นเบอร์เดียวกับในบิล
     */
    public function test_old_bill_without_saved_account_shows_the_current_transfer_account_of_the_same_promptpay(): void
    {
        $account = [
            'promptpay_id'        => '0811111111',
            'account_name'        => 'จันทราพยากรณ์',
            'account_number'      => '2323775349',
            'bank_code'           => 'KBANK',
            'bank_name'           => 'ธนาคารกสิกรไทย',
            'sms_checker_enabled' => true,
        ];
        // closure = เปลี่ยนเบอร์พร้อมเพย์กลางเทสต์ได้ (Http::fake ตัวแรกชนะเสมอ ลงซ้ำไม่มีผล)
        $promptpay = '0811111111';
        Http::fake(['*/juntra/payment/account' => function () use (&$account, &$promptpay) {
            return Http::response(['data' => array_merge($account, ['promptpay_id' => $promptpay])], 200);
        }]);
        Cache::flush();
        $user = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
        $txId = (int) $this->actingAs($user)->postJson('/chat/topup', ['amount' => 39])->assertOk()->json('id');

        // ทำให้เหมือนบิลเก่า: meta ไม่มีคีย์ transfer
        $tx = WalletTransaction::findOrFail($txId);
        $meta = (array) $tx->meta;
        unset($meta['transfer']);
        $tx->meta = $meta;
        $tx->save();

        $this->actingAs($user)->getJson(route('chat.topup.resume', ['tx' => $txId]))
            ->assertOk()
            ->assertJsonPath('promptpay_name', 'จันทราพยากรณ์')
            ->assertJsonPath('transfer.display_number', '232-3-77534-9')
            ->assertJsonPath('transfer.bank_name', 'ธนาคารกสิกรไทย');

        // พร้อมเพย์ปัจจุบันเปลี่ยนเบอร์ไปแล้ว = บัญชีนั้นไม่ใช่ของบิลนี้ → แสดงแค่ QR เดิม
        $promptpay = '0822222222';
        Cache::flush();
        $this->actingAs($user)->getJson(route('chat.topup.resume', ['tx' => $txId]))
            ->assertOk()
            ->assertJsonPath('transfer', null);
    }

    /** บัญชีที่ทำ QR ไม่ได้เปิดตัวจับ SMS = โอนแล้วบิลไม่ตัดเอง → ไม่โชว์เลขบัญชี แสดงแค่ QR */
    public function test_chat_bill_shows_only_the_qr_when_the_account_has_no_sms_checking(): void
    {
        Http::fake(['*/juntra/payment/account' => Http::response(['data' => [
            'promptpay_id'        => '0811111111',
            'account_name'        => 'จันทราพยากรณ์',
            'account_number'      => '2323775349',
            'bank_name'           => 'ธนาคารกสิกรไทย',
            'sms_checker_enabled' => false,
        ]], 200)]);
        Cache::flush();
        $user = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);

        $this->actingAs($user)->postJson('/chat/topup', ['amount' => 39])
            ->assertOk()
            ->assertJsonPath('transfer', null);
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
