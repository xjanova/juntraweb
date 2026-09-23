<?php

namespace Tests\Feature;

use App\Filament\Pages\MaeMorCommissions;
use App\Filament\Pages\MaeMorSettings;
use App\Filament\Pages\MaeMorTree;
use App\Filament\Resources\AffiliateBillResource\Pages\ListAffiliateBills;
use App\Models\AffiliateBill;
use App\Models\Setting;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 🌙 หลังบ้านจันทรา → ผังแม่หมอ: ทุกปุ่มต้องสั่งให้แม่หมอทำ (ไม่คำนวณเอง) และบอกว่าแอดมินคนไหนสั่ง
 *
 * เจ้าของสั่ง (2026-09-21): เว็บใครเว็บมัน — หลังบ้านจันทราจัดการเฉพาะค่าแนะนำจากบิลจันทรา
 *   อัตรา % ตั้งที่ Thaiprompt ที่เดียว (หน้านี้ดูได้อย่างเดียว)
 */
class MaeMorAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    private const ROW = [
        'id' => 55, 'level' => 1, 'amount' => 9.9, 'commission_type' => 'percent', 'commission_rate' => 10,
        'status' => 'pending', 'user' => ['id' => 9, 'name' => 'ผู้เชิญ', 'email' => 'a@b.c'],
        'from_user' => ['id' => 10, 'name' => 'ลูกค้าเบอร์โทร'],
        'reading' => ['id' => 77, 'bill_reference' => 'JW-501', 'source' => 'juntra', 'amount' => 99],
        'created_at' => '2026-09-21T10:00:00+07:00',
    ];

    /** ผู้เชิญไม่ active → แม่หมอโอนส่วนนั้นเข้ากระเป๋ากลาง (บัญชีกลางบน prod ไม่มีชื่อ) */
    private const CENTRAL_ROW = [
        'id' => 57, 'level' => 1, 'amount' => 3.9, 'commission_type' => 'percent', 'commission_rate' => 10,
        'status' => 'paid', 'user' => ['id' => 1, 'name' => '', 'email' => 'root@thaiprompt.test'],
        'from_user' => ['id' => 13, 'name' => 'ลูกค้าของผู้เชิญที่ไม่ active'],
        'reading' => ['id' => 78, 'bill_reference' => 'JW-502', 'source' => 'juntra', 'amount' => 39],
        'notes' => '[CENTRAL_FALLBACK:sponsor_inactive] ค่าแนะนำดูดวง L1 (สายตรง) 3.9 บาท — ผู้แนะนำไม่ active (ไม่ roll up)',
        'created_at' => '2026-09-21T11:00:00+07:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', '9f1c-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'super-secret-value', 'thaiprompt', true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Http::fake(function (Request $r) {
            $url = $r->url();

            return match (true) {
                str_contains($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_contains($url, '/admin/overview') => Http::response(['data' => [
                    'commissions' => ['pending_amount' => 9.9, 'pending_count' => 1],
                    'juntra_bills' => ['paid_count' => 1, 'paid_amount' => 99, 'voided_count' => 0],
                ]]),
                str_contains($url, '/admin/commissions/pay') => Http::response(['data' => ['count' => 1, 'errors' => []]]),
                str_contains($url, '/admin/commissions/55/reject') => Http::response(['data' => ['status' => 'rejected']]),
                str_contains($url, '/admin/commissions/manual') => Http::response(['data' => ['id' => 56]], 201),
                str_contains($url, '/admin/commissions') => Http::response([
                    'data' => [self::ROW, self::CENTRAL_ROW], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 2],
                ]),
                str_contains($url, '/admin/settings') => Http::response(['data' => [
                    'fortune_affiliate_enabled' => true, 'fortune_central_fallback_enabled' => true,
                    'fortune_juntra_l1_percent' => 12.5, 'fortune_juntra_l2_enabled' => true, 'fortune_juntra_l2_percent' => 5,
                ]]),
                str_contains($url, '/admin/users/9/stats') => Http::response([
                    'user' => ['name' => 'ผู้เชิญ'], 'totals' => ['this_month' => 9.9, 'all_time' => 9.9, 'reversed' => 0],
                    'mlm' => ['member_code' => 'INVITE01', 'total_team_members' => 1, 'direct_referrals' => 1],
                ]),
                str_contains($url, '/admin/users/9/tree') => Http::response(['tree' => [
                    'id' => 3, 'user_id' => 9, 'name' => 'ผู้เชิญ', 'total_team_members' => 1, 'direct_referrals' => 1,
                    'children' => [
                        ['id' => 4, 'user_id' => 10, 'name' => 'ลูกค้าเบอร์โทร', 'is_juntra' => true, 'children' => []],
                        ['id' => 5, 'user_id' => 11, 'name' => 'สมาชิกบอทแม่หมอ', 'is_juntra' => false, 'children' => []],
                    ],
                ]]),
                // ลูกค้าที่สมัครโดยไม่มีผู้เชิญ — ตำแหน่งที่จันทราสร้าง อยู่ใต้ผู้แนะนำเริ่มต้นที่หลังบ้านนี้เปิดผังไม่ได้
                str_contains($url, '/admin/users/12/stats') => Http::response([
                    'user' => ['name' => 'ลูกค้าไม่มีผู้เชิญ'], 'totals' => ['this_month' => 0, 'all_time' => 0, 'reversed' => 0],
                    'mlm' => ['member_code' => 'NOREF12', 'total_team_members' => 0, 'direct_referrals' => 0],
                ]),
                str_contains($url, '/admin/users/12/tree') => Http::response(['tree' => [
                    'id' => 6, 'user_id' => 12, 'name' => 'ลูกค้าไม่มีผู้เชิญ', 'is_juntra' => true, 'children' => [],
                ]]),
                str_contains($url, '/admin/members/6/move') => Http::response(['data' => [
                    'member_id' => 6, 'old_sponsor_id' => 77, 'new_sponsor_id' => 3,
                ]]),
                str_contains($url, '/admin/users') => Http::response(['data' => [['id' => 9, 'name' => 'ผู้เชิญ', 'email' => 'a@b.c', 'juntra_user_id' => 42]]]),
                default => Http::response(null, 404),
            };
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'แอดมินจันทรา']);
    }

    public function test_commissions_page_shows_live_rows_and_pays_through_the_tree(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(MaeMorCommissions::class)
            ->assertSee('JW-501')
            ->assertSee('ลูกค้าเบอร์โทร')
            ->set('selected', ['55'])
            ->call('paySelected')
            ->assertNotified();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/commissions/pay')
            && $r['ids'] === [55]
            && $r['actor']['juntra_user_id'] === $admin->id
            && $r['actor']['name'] === 'แอดมินจันทรา');
    }

    public function test_reject_action_sends_the_reason_and_actor(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(MaeMorCommissions::class)
            ->callAction('reject', ['reason' => 'บิลซ้ำ'], arguments: ['id' => 55])
            ->assertNotified();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/commissions/55/reject')
            && $r['reason'] === 'บิลซ้ำ' && isset($r['actor']['juntra_user_id']));
    }

    /** อัตราตั้งที่ Thaiprompt ที่เดียว — หน้านี้แสดงค่าที่ใช้อยู่ และพาไปหน้าตั้งค่าของ Thaiprompt */
    public function test_settings_page_only_shows_the_rates_set_at_thaiprompt(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(MaeMorSettings::class)
            ->assertSee('12.5%')
            ->assertSee('5%')
            ->assertSee(self::TP.'/admin/fortune/commissions/manage')
            ->assertDontSee('บันทึก');

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && str_contains($r->url(), '/admin/settings'));
    }

    public function test_settings_page_says_so_when_the_tree_is_unreachable(): void
    {
        Setting::put('thaiprompt_client_id', '', 'thaiprompt'); // ต่อแม่หมอไม่ได้
        $this->actingAs($this->admin());

        Livewire::test(MaeMorSettings::class)
            ->assertSet('rates', [])
            ->assertSee('ยังเชื่อมผังแม่หมอไม่ได้')
            ->assertDontSee('12.5%');
    }

    /** สร้างรายการเอง: ใส่เลขบิลจันทรา — ลูกค้าเจ้าของบิลให้ Thaiprompt หาเอง (ไม่ให้แอดมินพิมพ์ id ผิดคน) */
    public function test_manual_commission_is_created_against_a_juntra_bill_number(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(MaeMorCommissions::class)
            ->callAction('createManual', ['bill_id' => 501, 'user_id' => 9, 'level' => 1, 'amount' => 4.5, 'notes' => 'ชดเชย'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/commissions/manual')
            && $r['bill_id'] === 501 && $r['user_id'] === 9
            && ! isset($r['fortune_reading_id']) && ! isset($r['from_user_id'])
            && isset($r['actor']['juntra_user_id']));
    }

    public function test_tree_page_shows_a_members_line(): void
    {
        $this->actingAs($this->admin());

        $page = Livewire::test(MaeMorTree::class)
            ->assertSee('ผู้เชิญ')
            ->assertSee('ลูกค้า #42')
            ->call('show', 9)
            ->assertSee('INVITE01')
            ->assertSee('ลูกค้าเบอร์โทร')
            ->assertSee('สมาชิกบอทแม่หมอ');

        // ปุ่มย้ายสายขึ้นเฉพาะลูกค้าจันทรา (สมาชิกบอทจัดการที่หลังบ้านแม่หมอ) — ต้นผังนี้ไม่ใช่ลูกค้าจันทรา
        $this->assertSame(1, substr_count($page->html(), 'ย้ายสาย'));
    }

    /**
     * 🔧 (2026-09-23) ลูกค้าที่สมัครโดยไม่มีผู้เชิญอยู่ใต้ผู้แนะนำเริ่มต้น — หลังบ้านจันทราเปิดผังนั้นไม่ได้
     *   เดิมซ่อนปุ่มย้ายสายที่ต้นผัง ลูกค้ากลุ่มนี้จึงย้ายจากหลังบ้านจันทราไม่ได้เลย ทั้งที่แม่หมออนุญาต
     */
    public function test_a_juntra_customer_at_the_root_of_the_view_can_be_moved(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $page = Livewire::test(MaeMorTree::class)
            ->call('show', 12)
            ->assertSee('NOREF12');
        $this->assertSame(1, substr_count($page->html(), 'ย้ายสาย'), 'ต้นผังที่เป็นลูกค้าจันทราต้องมีปุ่มย้ายสาย');

        $page->callAction('move', ['new_sponsor_member_id' => 3, 'notes' => 'ลูกค้าบอกว่าผู้เชิญคือคนนี้'], arguments: ['member' => 6, 'name' => 'ลูกค้าไม่มีผู้เชิญ'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/members/6/move')
            && (int) $r['new_sponsor_member_id'] === 3
            && $r['actor']['juntra_user_id'] === $admin->id);
    }

    /** ผู้เชิญไม่ active → ส่วนของเขาเข้ากระเป๋ากลาง — แอดมินต้องเห็นว่าเข้ากระเป๋ากลางเพราะอะไร ไม่ใช่ช่องผู้รับว่าง */
    public function test_central_wallet_rows_say_why_the_inviter_was_not_paid(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(MaeMorCommissions::class)
            ->assertSee('JW-502')
            ->assertSee('กระเป๋ากลาง')
            ->assertSee('ผู้แนะนำไม่ active ตามเกณฑ์รักษายอดของแม่หมอ');
    }

    public function test_central_fallback_reason_reads_only_the_mae_mor_tag(): void
    {
        $this->assertNull(MaeMorCommissions::centralFallbackReason(null));
        $this->assertNull(MaeMorCommissions::centralFallbackReason('ค่าแนะนำดูดวง L1 (สายตรง) 9.9 บาท'));
        $this->assertNull(MaeMorCommissions::centralFallbackReason('[สร้างด้วยมือ] ชดเชย'));
        $this->assertSame('ลูกค้าไม่มีผู้แนะนำ', MaeMorCommissions::centralFallbackReason('[CENTRAL_FALLBACK:no_referrer] ค่าแนะนำดูดวง L1'));
        // ถูกดึงคืนภายหลัง หมายเหตุต่อท้ายเพิ่ม — ป้ายเดิมยังอยู่
        $this->assertSame('ไม่มีผู้รับชั้นหลาน', MaeMorCommissions::centralFallbackReason('[CENTRAL_FALLBACK:no_grandparent] L2 | ⛔ REVERSED: void approval บิล #9'));
        // เหตุผลใหม่ที่ยังไม่รู้จัก — แสดงรหัสตรง ๆ ดีกว่าเงียบ
        $this->assertSame('some_new_reason', MaeMorCommissions::centralFallbackReason('[CENTRAL_FALLBACK:some_new_reason] L1'));
    }

    public function test_failed_bills_can_be_queued_again(): void
    {
        $this->actingAs($this->admin());
        $user = User::factory()->create();
        $wallet = app(WalletService::class);
        $wallet->credit($user, 100, 'เติม');
        $tx = $wallet->debit($user, 39, 'ดูดวงเชิงลึก', ['reference_type' => 'reading']);
        $bill = AffiliateBill::create([
            'wallet_transaction_id' => $tx->id, 'user_id' => $user->id, 'amount' => 39, 'product' => 'ดูดวงเชิงลึก',
            'status' => AffiliateBill::STATUS_FAILED, 'attempts' => 1, 'next_attempt_at' => null, 'last_error' => 'rejected · HTTP 422',
        ]);

        Livewire::test(ListAffiliateBills::class)
            ->assertCanSeeTableRecords([$bill])
            ->callTableAction('resend', $bill);

        $bill->refresh();
        $this->assertSame(AffiliateBill::STATUS_PENDING, $bill->status);
        $this->assertNull($bill->next_attempt_at);
        $this->assertSame(1, $bill->attempts, 'ครั้งที่เคยยิงต้องคงไว้ — ใช้ตัดสินการสั่งยกเลิกเมื่อคืนเงิน');
    }

    public function test_pages_are_admin_only(): void
    {
        $this->actingAs(User::factory()->create()); // ลูกค้าทั่วไป (role ค่าเริ่มต้น)

        $this->assertFalse(MaeMorCommissions::canAccess());
        $this->assertFalse(MaeMorSettings::canAccess());
        $this->assertFalse(MaeMorTree::canAccess());
    }
}
