<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\ChatSlipIntake;
use App\Services\Wallet\WalletService;
use App\Support\TopupAutoApprove;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🧾 (2026-11-10) สลิปที่ลูกค้าส่งในแชทเพจ (ระบบเดิม) → เติมกระเป๋าบนเว็บ
 *
 * เคสจริง: ลูกค้าเปิดบิล ฿60.59 บนเว็บ โอน ฿100 แต่ส่งสลิปไปในแชทเพจ แทนการแนบบนเว็บ
 *          → บอทเก็บรูปเฉย ๆ ไม่ตรวจ แอดมินต้องเติมมือ (เติมแค่ ฿60.59)
 *
 * Thaiprompt ถูกจำลองเป็นเซิร์ฟเวอร์ที่มีสถานะจริง (พักสลิป · ผูกสลิปเข้าบิลได้ครั้งเดียว · รายงานผลครั้งเดียว)
 */
class ChatSlipIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    private const REF = '016314231549BPM04567';

    private const PSID = '28490000000000001';

    private User $user;

    /** สถานะฝั่ง Thaiprompt */
    private array $tp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', '9f1c-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'super-secret-value', 'thaiprompt', true);
        $this->user = User::factory()->create(['facebook_user_id' => self::PSID]);
        $this->parkSlip(100.00);
        $this->fakeThaiprompt();
    }

    // ─── Thaiprompt จำลอง ───────────────────────────────────────────

    private function owner(): string
    {
        return 'chat:facebook:' . self::PSID;
    }

    private function parkSlip(float $amount, string $psid = self::PSID): void
    {
        $this->tp = [
            'slip' => [
                'trans_ref' => self::REF, 'platform' => 'facebook', 'platform_user_id' => $psid,
                'chat_owner' => 'chat:facebook:' . $psid, 'ok' => true, 'error_code' => null,
                'amount' => $amount, 'sender_name' => 'นาย ทดสอบ', 'receiver_account' => 'xxx-x-x7534-x',
                'sending_bank' => '004', 'receiving_bank' => '004',
                'trans_timestamp' => now()->utc()->toIso8601String(),
                'receiver_matches' => true, 'slip_age_ok' => true, 'used' => false, 'used_source' => null, 'used_by' => null,
            ],
            'claimed_by' => null,      // topup_ref ที่ผูกสลิปไว้
            'force_claim_conflict' => false,
            'resolved' => null,        // ['outcome','topup_ref','credited_amount']
            'down' => false,
        ];
    }

    private function payload(): array
    {
        return $this->tp['slip'] + [
            'status' => $this->tp['resolved']['outcome'] ?? 'parked',
            'topup_ref' => $this->tp['resolved']['topup_ref'] ?? null,
            'credited_amount' => $this->tp['resolved']['credited_amount'] ?? null,
        ];
    }

    private function fakeThaiprompt(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        $api = self::TP . '/api/v1/juntra/server';
        Http::fake([
            self::TP . '/oauth/token' => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
            $api . '/chat-slips/fetch' => function (HttpRequest $r) {
                if ($this->tp['down']) {
                    return Http::response('bad gateway', 502);
                }
                if ($r['trans_ref'] !== self::REF) {
                    return Http::response(['reason_code' => 'not_found'], 404);
                }

                return Http::response(['data' => $this->payload()]);
            },
            $api . '/chat-slips/pending' => fn () => Http::response(['data' => $this->tp['resolved'] ? [] : [$this->payload()]]),
            $api . '/chat-slips/resolve' => function (HttpRequest $r) {
                $want = ['outcome' => $r['outcome'], 'topup_ref' => $r['topup_ref'] ?? null, 'credited_amount' => $r['credited_amount'] ?? null];
                if ($this->tp['resolved'] !== null) {
                    $same = $this->tp['resolved']['outcome'] === $want['outcome'] && $this->tp['resolved']['topup_ref'] === $want['topup_ref'];

                    return $same ? Http::response(['data' => $this->payload()]) : Http::response(['data' => $this->payload()], 409);
                }
                $this->tp['resolved'] = $want;

                return Http::response(['data' => $this->payload()]);
            },
            $api . '/slips/claim' => function (HttpRequest $r) {
                if ($this->tp['force_claim_conflict']) {
                    return Http::response(['reason_code' => 'already_used', 'data' => ['platform' => 'facebook', 'user' => 'X', 'reading_id' => 7]], 409);
                }
                if ($this->tp['claimed_by'] === null && ($r['chat_owner'] ?? null) === $this->tp['slip']['chat_owner']) {
                    $this->tp['claimed_by'] = $r['topup_ref'];

                    return Http::response(['data' => ['claimed' => true, 'from_chat' => true]], 201);
                }
                if ($this->tp['claimed_by'] === $r['topup_ref']) {
                    return Http::response(['data' => ['claimed' => true, 'idempotent' => true]]);
                }

                return Http::response(['reason_code' => 'already_used', 'data' => ['platform' => 'juntraweb']], 409);
            },
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    // ─── helpers ───────────────────────────────────────────────────

    private function bill(float $payable, float $base): WalletTransaction
    {
        $tx = app(WalletService::class)->recordPendingTopup($this->user, $payable, null, 'promptpay');
        $tx->update(['meta' => array_merge((array) $tx->meta, ['base_amount' => $base, 'source' => 'chat'])]);

        return $tx->fresh();
    }

    private function ring(?string $ref = self::REF)
    {
        return $this->postJson('/api/server/chat-slips/ping', ['trans_ref' => $ref]);
    }

    private function balance(): float
    {
        return app(WalletService::class)->balance($this->user);
    }

    // ─── เคสจริง + การจับคู่บิล ─────────────────────────────────────

    public function test_the_real_case_slip_of_100_in_chat_credits_the_60_59_bill_at_once(): void
    {
        $small = $this->bill(39.80, 39);
        $big   = $this->bill(60.59, 60);

        $this->ring()->assertOk()
            ->assertJsonPath('outcome', 'credited')
            ->assertJsonPath('topup_ref', $big->reference_code)
            ->assertJsonPath('credited', 60.59);

        $big = $big->fresh();
        $this->assertSame('success', $big->status);
        $this->assertSame(60.59, $this->balance());
        $this->assertSame(TopupAutoApprove::SLIP_CHAT, data_get($big->meta, 'auto_approved.by'));
        $this->assertSame('อนุมัติอัตโนมัติโดย SlipOK (สลิปจากแชทเพจ)', data_get($big->meta, 'auto_approved.label'));
        $this->assertSame(self::REF, $big->bank_reference);
        $this->assertEquals(100.0, (float) data_get($big->meta, 'slip_amount'), 'ยอดจริงในสลิปเก็บไว้ให้แอดมินเห็นส่วนที่โอนเกิน');
        $this->assertSame('pending', $small->fresh()->status, 'สลิปใบเดียว จ่ายได้บิลเดียว');

        // Thaiprompt รู้ผล: ผูกสลิปเข้าบิลนี้ + รายงานว่าเติมแล้ว
        $this->assertSame($big->reference_code, $this->tp['claimed_by']);
        $this->assertSame('credited', $this->tp['resolved']['outcome']);
        $this->assertSame('60.59', $this->tp['resolved']['credited_amount']);
    }

    public function test_a_chat_slip_short_only_by_the_satang_credits_what_was_paid(): void
    {
        $this->parkSlip(39.00);
        $tx = $this->bill(39.80, 39);

        $this->ring()->assertOk()->assertJsonPath('outcome', 'credited')->assertJsonPath('credited', 39);

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(39.0, $this->balance());
    }

    public function test_a_chat_slip_below_the_bill_goes_to_the_admin_queue(): void
    {
        $this->parkSlip(30.00);
        $tx = $this->bill(39.80, 39);

        $this->ring()->assertOk()->assertJsonPath('outcome', 'review')->assertJsonPath('topup_ref', $tx->reference_code);

        $tx = $tx->fresh();
        $this->assertSame('pending', $tx->status);
        $this->assertSame(0.0, $this->balance());
        $this->assertSame('review', data_get($tx->meta, 'slip_check.decision'));
        $this->assertSame(self::REF, $tx->bank_reference, 'ผูกเลขไว้ให้รอบหน้ารู้ว่าทำแล้ว');
        $this->assertSame('review', $this->tp['resolved']['outcome']);
    }

    // ─── กันเติมซ้ำ ─────────────────────────────────────────────────

    public function test_ringing_twice_and_the_sweeper_never_credit_twice(): void
    {
        $tx = $this->bill(60.59, 60);

        $this->ring()->assertJsonPath('outcome', 'credited');
        $this->ring()->assertJsonPath('outcome', 'credited');
        $this->artisan('wallet:sweep-chat-slips')->assertSuccessful();

        $this->assertSame(60.59, $this->balance());
        $this->assertSame(1, WalletTransaction::where('type', 'topup')->where('status', 'success')->count());
        $this->assertSame('success', $tx->fresh()->status);
    }

    public function test_a_lost_report_is_repaired_on_the_next_pass_without_crediting_again(): void
    {
        $tx = $this->bill(60.59, 60);

        // เติมแล้ว แต่รายงานผลกลับไม่ถึง Thaiprompt
        app(ChatSlipIntake::class)->process(self::REF);
        $this->tp['resolved'] = null; // จำลองว่า Thaiprompt ไม่ได้รับรายงาน — สลิปยังพักอยู่

        $this->artisan('wallet:sweep-chat-slips')->assertSuccessful();

        $this->assertSame(60.59, $this->balance());
        $this->assertSame('credited', $this->tp['resolved']['outcome']);
        $this->assertSame($tx->reference_code, $this->tp['resolved']['topup_ref']);
    }

    public function test_a_slip_already_used_elsewhere_is_refused(): void
    {
        $tx = $this->bill(60.59, 60);
        $this->tp['force_claim_conflict'] = true;

        $this->ring()->assertOk()->assertJsonPath('outcome', 'duplicate');

        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, $this->balance());
        $this->assertSame('duplicate', $this->tp['resolved']['outcome']);
    }

    // ─── ไม่มีบิล / ไม่เจอลูกค้า / สวิตช์ ───────────────────────────────

    public function test_no_pending_bill_opens_an_admin_item_for_the_slip_amount_without_crediting(): void
    {
        $this->ring()->assertOk()->assertJsonPath('outcome', 'review');

        $tx = WalletTransaction::where('user_id', $this->user->id)->where('type', 'topup')->sole();
        $this->assertSame('pending', $tx->status);
        $this->assertSame('100.00', (string) $tx->amount);
        $this->assertSame('chat_slip', data_get($tx->meta, 'source'));
        $this->assertSame('review', data_get($tx->meta, 'slip_check.decision'));
        $this->assertSame(0.0, $this->balance());
        $this->assertSame($tx->reference_code, $this->tp['claimed_by'], 'สลิปถูกผูกกับรายการนี้ ใช้ที่อื่นไม่ได้');
    }

    public function test_no_linked_web_account_is_reported_for_the_admin(): void
    {
        $this->parkSlip(100.00, '999999');
        $this->bill(60.59, 60);

        $this->ring()->assertOk()->assertJsonPath('outcome', 'no_user');

        $this->assertSame(0.0, $this->balance());
        $this->assertNull($this->tp['claimed_by']);
        $this->assertSame('no_user', $this->tp['resolved']['outcome']);
    }

    public function test_slip_switch_off_sends_the_chat_slip_to_the_admin(): void
    {
        TopupAutoApprove::set(TopupAutoApprove::SLIP, false);
        $tx = $this->bill(60.59, 60);

        $this->ring()->assertOk()->assertJsonPath('outcome', 'review');

        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, $this->balance());
    }

    // ─── กริ่งปลอม / ต่อไม่ติด ───────────────────────────────────────

    public function test_a_forged_ring_or_a_down_thaiprompt_changes_nothing(): void
    {
        $tx = $this->bill(60.59, 60);

        $this->ring('NOTAREALREF123')->assertStatus(202)->assertJsonPath('outcome', 'later');
        $this->tp['down'] = true;
        $this->ring()->assertStatus(202)->assertJsonPath('outcome', 'later');
        $this->ring('bad ref!')->assertStatus(422);

        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, $this->balance());
        $this->assertNull($this->tp['resolved']);
    }

    public function test_the_sweeper_picks_up_a_slip_whose_ring_never_arrived(): void
    {
        $tx = $this->bill(60.59, 60);

        $this->artisan('wallet:sweep-chat-slips')->assertSuccessful();

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(60.59, $this->balance());
    }
}
