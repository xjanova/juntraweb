<?php

namespace Tests\Feature;

use App\Models\AffiliateBill;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🌙 affiliate:sync-bills — บิลคำทำนายเว็บ+แอพ → ผังแม่หมอแจกค่าแนะนำ
 *
 * เจ้าของสั่ง (2026-09-21): เว็บไม่คำนวณค่าแนะนำเอง ทุกบิลไปคำนวณที่ผังแม่หมอ
 *   ค่าแชทไม่นับ · คืนเงินลูกค้า = ดึงค่าแนะนำคืน · แม่หมอล่มห้ามทำบิลหาย
 */
class AffiliateSyncBillsTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    /** สถานะที่ปลายทาง /bills ตอบ */
    private int $billsStatus = 201;

    private int $voidStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', '9f1c-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'super-secret-value', 'thaiprompt', true);
        Setting::put('affiliate_sync_since', now()->subDay()->toIso8601String(), 'affiliate');

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/oauth/token')) {
                return Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]);
            }
            if (preg_match('#/affiliate/bills/\d+/void$#', $url)) {
                return Http::response(['data' => ['voided' => true]], $this->voidStatus);
            }
            if (str_ends_with($url, '/affiliate/bills')) {
                if ($this->billsStatus >= 400) {
                    return Http::response(['reason_code' => $this->billsStatus === 422 ? null : 'busy', 'message' => 'x'], $this->billsStatus);
                }

                // แม่หมอไม่แจกค่าแนะนำจากบิลก่อนเปิดระบบ (history_only)
                $history = ! empty($request->data()['history_only']);

                return Http::response(['data' => [
                    'bill_reference' => 'JW-' . $request['bill_id'],
                    'member_code' => 'MLMBUYER01',
                    'history_only' => $history,
                    'commissions' => $history ? [] : [['level' => 1, 'amount' => 9.9], ['level' => 2, 'amount' => 4.95]],
                ]], $this->billsStatus);
            }
            if (str_contains($url, '/affiliate/accounts')) {
                return Http::response(['data' => ['member_code' => 'MLMBUYER01', 'enrolled_now' => true]], 201);
            }

            return Http::response(null, 404);
        });
    }

    public function test_paid_reading_is_sent_once_it_is_past_the_refund_window(): void
    {
        [$user, $tx] = $this->bill(99, 'เปิดไพ่: Celtic Cross', ageMinutes: 20);

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $bill = AffiliateBill::where('wallet_transaction_id', $tx->id)->firstOrFail();
        $this->assertSame(AffiliateBill::STATUS_SENT, $bill->status);
        $this->assertSame('JW-' . $tx->id, $bill->bill_reference);
        $this->assertEquals(14.85, (float) $bill->commission_total);
        $this->assertSame('MLMBUYER01', $user->fresh()->maemor_member_code);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/affiliate/bills')
            && (int) $r['bill_id'] === $tx->id
            && (int) $r['user_ref'] === $user->id
            && $r['amount'] === '99.00'
            && $r['product'] === 'เปิดไพ่: Celtic Cross'
            && ! isset($r['history_only'])); // บิลหลังเปิดระบบ = แจกค่าแนะนำตามปกติ

        // รอบถัดไปไม่ส่งซ้ำ
        $this->artisan('affiliate:sync-bills')->assertSuccessful();
        Http::assertSentCount(2); // token + bills หนึ่งครั้ง
    }

    public function test_fresh_bills_wait_for_the_refund_window(): void
    {
        $this->bill(39, 'ดูดวงเชิงลึก', ageMinutes: 3);

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $this->assertSame(0, AffiliateBill::count());
    }

    public function test_chat_charges_are_never_sent(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);
        $wallet->credit($user, 100, 'เติมเงิน');
        $tx = $wallet->debit($user, 2, 'AI chat message', ['reference_type' => 'chat_message']);
        $this->age($tx, 30);

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $this->assertSame(0, AffiliateBill::count());
        Http::assertNothingSent();
    }

    /**
     * บิลก่อนเปิดระบบ — ไม่มีค่าแนะนำย้อนหลัง (เจ้าของสั่ง 2026-09-21) แต่ต้องบอกแม่หมอว่าลูกค้า "เคยมีบิลที่ชำระแล้ว"
     *   (เจ้าของสั่ง 2026-09-23: ผู้เชิญต้องเคยมีบิลที่ชำระแล้วจึงได้ค่าแนะนำ) · บิลที่คืนเงินไปแล้วไม่นับ ไม่ส่ง
     */
    public function test_bills_before_launch_are_sent_only_to_count_as_paid(): void
    {
        [, $old] = $this->bill(99, 'เปิดไพ่', ageMinutes: 60 * 48);
        [, $refunded] = $this->bill(39, 'ดูดวงเชิงลึก', ageMinutes: 60 * 50);
        app(WalletService::class)->refund($refunded, 'อ่านไม่สำเร็จ');

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $bill = AffiliateBill::where('wallet_transaction_id', $old->id)->firstOrFail();
        $this->assertTrue($bill->history_only);
        $this->assertSame(AffiliateBill::STATUS_SENT, $bill->status);
        $this->assertEquals(0, (float) $bill->commission_total);
        $this->assertFalse(AffiliateBill::where('wallet_transaction_id', $refunded->id)->exists());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/affiliate/bills')
            && (int) $r['bill_id'] === $old->id && ($r->data()['history_only'] ?? null) === true);

        // รอบถัดไปไม่ส่งซ้ำ
        $this->artisan('affiliate:sync-bills')->assertSuccessful();
        $this->assertSame(1, AffiliateBill::count());
    }

    public function test_refund_before_sending_is_skipped(): void
    {
        [, $tx] = $this->bill(99, 'เปิดไพ่', ageMinutes: 20);
        AffiliateBill::create([
            'wallet_transaction_id' => $tx->id, 'user_id' => $tx->user_id,
            'amount' => 99, 'product' => 'เปิดไพ่', 'status' => AffiliateBill::STATUS_PENDING,
        ]);
        app(WalletService::class)->refund($tx, 'อ่านไม่สำเร็จ');

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $this->assertSame(AffiliateBill::STATUS_SKIPPED, AffiliateBill::value('status'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/affiliate/'));
    }

    public function test_refund_after_sending_claws_the_commission_back(): void
    {
        [, $tx] = $this->bill(99, 'เปิดไพ่', ageMinutes: 20);
        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        app(WalletService::class)->refund($tx->fresh(), 'แอดมินคืนเงิน');
        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $bill = AffiliateBill::firstOrFail();
        $this->assertSame(AffiliateBill::STATUS_VOIDED, $bill->status);
        $this->assertNotNull($bill->voided_at);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/affiliate/bills/{$tx->id}/void"));
    }

    public function test_outage_keeps_the_bill_and_backs_off_without_burning_the_rest(): void
    {
        $this->billsStatus = 503;
        [, $first] = $this->bill(99, 'เปิดไพ่ 1', ageMinutes: 30);
        [, $second] = $this->bill(39, 'เปิดไพ่ 2', ageMinutes: 25);

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $a = AffiliateBill::where('wallet_transaction_id', $first->id)->firstOrFail();
        $b = AffiliateBill::where('wallet_transaction_id', $second->id)->firstOrFail();
        $this->assertSame(AffiliateBill::STATUS_FAILED, $a->status);
        $this->assertSame(1, $a->attempts);
        $this->assertTrue($a->next_attempt_at->isFuture());
        $this->assertSame(0, $b->attempts, 'หยุดรอบนี้ทันทีเมื่ออีกฝั่งล่ม');

        // กลับมาได้ → ส่งสำเร็จเมื่อถึงเวลาลองใหม่
        $this->billsStatus = 201;
        $this->travel(3)->minutes();
        $this->artisan('affiliate:sync-bills')->assertSuccessful();
        $this->assertSame(AffiliateBill::STATUS_SENT, $a->fresh()->status);
        $this->assertSame(AffiliateBill::STATUS_SENT, $b->fresh()->status);
    }

    public function test_rejected_bill_stops_retrying_and_waits_for_an_admin(): void
    {
        $this->billsStatus = 422;
        $this->bill(99, 'เปิดไพ่', ageMinutes: 20);

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $bill = AffiliateBill::firstOrFail();
        $this->assertSame(AffiliateBill::STATUS_FAILED, $bill->status);
        $this->assertSame(1, $bill->attempts);
        $this->assertNull($bill->next_attempt_at, 'ถูกปฏิเสธ = ไม่ลองเอง รอแอดมิน');
        $this->assertSame(1, AffiliateBill::needsAdmin()->count());

        // รอบถัดไปต้องไม่ยิงซ้ำเอง
        $this->artisan('affiliate:sync-bills')->assertSuccessful();
        $this->assertSame(1, AffiliateBill::firstOrFail()->attempts);
    }

    /**
     * ยิงบิลไปแล้วแต่คำตอบหาย (แม่หมออาจบันทึกและแจกค่าแนะนำไปแล้ว) แล้วลูกค้าได้เงินคืน —
     * ต้องสั่งยกเลิกที่แม่หมอเสมอ ข้ามเฉย ๆ = ค่าแนะนำของบิลที่คืนเงินแล้วค้างที่ผู้แนะนำตลอดไป
     */
    public function test_refund_after_an_unanswered_send_still_voids_at_the_tree(): void
    {
        $this->billsStatus = 503;
        [, $tx] = $this->bill(99, 'เปิดไพ่', ageMinutes: 20);
        $this->artisan('affiliate:sync-bills')->assertSuccessful();
        $this->assertSame(1, AffiliateBill::firstOrFail()->attempts);

        app(WalletService::class)->refund($tx->fresh(), 'แอดมินคืนเงิน');
        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/affiliate/bills/{$tx->id}/void"));
        $this->assertSame(AffiliateBill::STATUS_VOIDED, AffiliateBill::firstOrFail()->status);
    }

    public function test_pending_referral_is_retried_until_the_tree_answers(): void
    {
        $user = User::factory()->create(['pending_referral_code' => 'MLMINVITE1']);

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->pending_referral_code);
        $this->assertSame('MLMBUYER01', $user->maemor_member_code);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/affiliate/accounts') && $r['referral_code'] === 'MLMINVITE1');
    }

    /** @return array{0: User, 1: WalletTransaction} */
    private function bill(float $amount, string $label, int $ageMinutes): array
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);
        $wallet->credit($user, 500, 'เติมเงิน');
        $tx = $wallet->debit($user, $amount, $label, ['reference_type' => 'reading']);
        $this->age($tx, $ageMinutes);

        return [$user, $tx->fresh()];
    }

    private function age(WalletTransaction $tx, int $minutes): void
    {
        WalletTransaction::whereKey($tx->id)->update(['created_at' => now()->subMinutes($minutes)]);
    }
}
