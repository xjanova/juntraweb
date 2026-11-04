<?php

namespace Tests\Feature;

use App\Exceptions\WalletAdminException;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\WalletTransactionResource\Pages\ListWalletTransactions;
use App\Models\User;
use App\Models\WalletAdminAdjustment;
use App\Models\WalletTransaction;
use App\Services\Wallet\AdminWalletService;
use App\Services\Wallet\WalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * แอดมินจัดการเครดิตในวอลเลตของจันทรา (AdminWalletService + ปุ่มใน Filament)
 *
 * ตรึงกติกาเงินทุกข้อ: เหตุผลบังคับ · audit (ใคร/ยอดก่อน-หลัง/IP/UA) · กันกดซ้ำ ·
 * ยอดอ้างอิงต้องตรง · ย้อนได้ครั้งเดียว/ห้ามย้อนของที่เป็นการย้อน · แตะวอลเลตตัวเองไม่ได้ ·
 * ห้ามติดลบ · บัญชีแยก (wallet:reconcile ต้องผ่านทุกครั้ง)
 */
class AdminWalletManagementTest extends TestCase
{
    use RefreshDatabase;

    private AdminWalletService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AdminWalletService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'แอดมินทดสอบ']);
    }

    /** สมาชิกที่มียอดตั้งต้น (เติมผ่าน WalletService จริง ให้ ledger ตรง) */
    private function member(float $balance = 0): User
    {
        $u = User::factory()->create(['role' => 'member']);
        if ($balance > 0) {
            app(WalletService::class)->credit($u, $balance, 'ยอดตั้งต้นของเทสต์');
        }

        return $u;
    }

    private function balance(User $u): string
    {
        return number_format((float) $u->wallet()->first()?->balance, 2, '.', '');
    }

    private function assertLedgerReconciles(): void
    {
        $this->assertSame(0, Artisan::call('wallet:reconcile'), Artisan::output());
    }

    // ───────────────────────── เติม / หัก / คืนเงิน ─────────────────────────

    public function test_credit_writes_ledger_row_and_full_audit_trail(): void
    {
        $admin = $this->admin();
        $u = $this->member(100);

        $tx = $this->svc->adjust($u, 'credit', '50', 'ชดเชยระบบล่ม', $admin, 'key-credit-1', '100.00');

        $this->assertTrue($tx->wasRecentlyCreated);
        $this->assertSame('150.00', $this->balance($u));
        $this->assertSame('adjustment', $tx->type);
        $this->assertSame('admin', $tx->method);
        $this->assertSame('50.00', (string) $tx->amount);
        $this->assertSame($admin->id, $tx->approved_by);

        $audit = WalletAdminAdjustment::sole();
        $this->assertSame('credit', $audit->action);
        $this->assertSame($admin->id, $audit->admin_id);
        $this->assertSame('แอดมินทดสอบ', $audit->admin_name);
        $this->assertSame($u->id, $audit->user_id);
        $this->assertSame('100.00', (string) $audit->balance_before);
        $this->assertSame('150.00', (string) $audit->balance_after);
        $this->assertSame('50.00', (string) $audit->amount);
        $this->assertSame('ชดเชยระบบล่ม', $audit->reason);
        $this->assertSame($tx->id, $audit->wallet_transaction_id);
        $this->assertNotEmpty($audit->ip_address);
        $this->assertNotEmpty($audit->user_agent);
        $this->assertLedgerReconciles();
    }

    public function test_admin_credit_is_not_counted_as_topup_revenue(): void
    {
        $u = $this->member();
        $this->svc->adjust($u, 'credit', 80, 'โปรโมชัน', $this->admin(), 'k-rev');

        $this->assertSame(0, WalletTransaction::where('type', 'topup')->where('method', 'admin')->count());
    }

    public function test_debit_reduces_balance_and_refuses_to_go_negative(): void
    {
        $admin = $this->admin();
        $u = $this->member(30);

        $this->svc->adjust($u, 'debit', '20', 'แก้ยอดเกิน', $admin, 'k-d1');
        $this->assertSame('10.00', $this->balance($u));

        try {
            $this->svc->adjust($u, 'debit', '10.01', 'หักเกินยอด', $admin, 'k-d2');
            $this->fail('ควรปฏิเสธเมื่อยอดไม่พอ');
        } catch (WalletAdminException $e) {
            $this->assertStringContainsString('ไม่ยอมให้ยอดติดลบ', $e->getMessage());
        }
        // ไม่หักเท่าที่มีแบบเงียบ ๆ และไม่มี audit ของรายการที่ถูกปฏิเสธ
        $this->assertSame('10.00', $this->balance($u));
        $this->assertSame(1, WalletAdminAdjustment::count());
        $this->assertLedgerReconciles();
    }

    public function test_refund_credits_as_refund_type(): void
    {
        $u = $this->member(10);
        $tx = $this->svc->adjust($u, 'refund', 39, 'คำทำนายไม่ครบ', $this->admin(), 'k-ref');

        $this->assertSame('refund', $tx->type);
        $this->assertSame('49.00', $this->balance($u));
        $this->assertSame('refund', WalletAdminAdjustment::sole()->action);
    }

    public function test_reason_is_required(): void
    {
        $u = $this->member(10);
        foreach (['', '   ', 'ab'] as $reason) {
            try {
                $this->svc->adjust($u, 'credit', 5, $reason, $this->admin());
                $this->fail('ควรบังคับเหตุผล');
            } catch (WalletAdminException $e) {
                $this->assertStringContainsString('เหตุผล', $e->getMessage());
            }
        }
        $this->assertSame('10.00', $this->balance($u));
        $this->assertSame(0, WalletAdminAdjustment::count());
    }

    public function test_bad_amounts_are_refused(): void
    {
        $u = $this->member(10);
        $admin = $this->admin();
        foreach (['0', '-5', 'abc', '0.001', '100000.01', 'NAN'] as $amount) {
            try {
                $this->svc->adjust($u, 'credit', $amount, 'ทดสอบจำนวนเงิน', $admin);
                $this->fail("ควรปฏิเสธจำนวน {$amount}");
            } catch (WalletAdminException) {
            }
        }
        $this->assertSame('10.00', $this->balance($u));
    }

    public function test_same_idempotency_key_applies_once(): void
    {
        $admin = $this->admin();
        $u = $this->member(0);

        $first  = $this->svc->adjust($u, 'credit', 25, 'กดยืนยันสองที', $admin, 'double-tap-1', '0');
        // ส่งซ้ำ — แม้ expected_balance เดิมจะไม่ตรงแล้ว ก็ต้องได้ผลเดิม ไม่ใช่ error และไม่เติมซ้ำ
        $second = $this->svc->adjust($u, 'credit', 25, 'กดยืนยันสองที', $admin, 'double-tap-1', '0');

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame('25.00', $this->balance($u));
        $this->assertSame(1, WalletAdminAdjustment::count());
    }

    public function test_stale_expected_balance_is_refused(): void
    {
        $u = $this->member(100);
        // ระหว่างที่แอดมินเปิดหน้าต่างยืนยัน ลูกค้าจ่ายค่าดูดวงไป 39
        app(WalletService::class)->debit($u, 39, 'ดูดวง');

        $this->expectException(WalletAdminException::class);
        $this->expectExceptionMessage('ยอดเงินในวอลเลตเปลี่ยนไป');
        try {
            $this->svc->adjust($u, 'debit', 100, 'ยึดยอดคืน', $this->admin(), 'k-stale', '100.00');
        } finally {
            $this->assertSame('61.00', $this->balance($u));
            $this->assertSame(0, WalletAdminAdjustment::count());
        }
    }

    // ───────────────────────────── สิทธิ์ ─────────────────────────────

    public function test_admin_cannot_change_own_wallet(): void
    {
        $admin = $this->admin();
        app(WalletService::class)->credit($admin, 10, 'seed');

        $this->expectException(WalletAdminException::class);
        $this->expectExceptionMessage('วอลเลตของตัวเองไม่ได้');
        $this->svc->adjust($admin, 'credit', 1000, 'เติมให้ตัวเอง', $admin, 'k-self');
    }

    public function test_editor_and_member_cannot_move_money(): void
    {
        $u = $this->member(50);
        foreach (['editor', 'member'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            try {
                $this->svc->adjust($u, 'credit', 10, 'ไม่ควรผ่าน', $actor);
                $this->fail("{$role} ไม่ควรจัดการเงินได้");
            } catch (WalletAdminException $e) {
                $this->assertStringContainsString('เฉพาะแอดมิน', $e->getMessage());
            }
        }
        $this->assertSame('50.00', $this->balance($u));
    }

    public function test_deleted_account_cannot_be_adjusted(): void
    {
        $u = $this->member(20);
        $u->delete();

        $this->expectException(WalletAdminException::class);
        $this->svc->adjust($u, 'credit', 5, 'บัญชีที่ลบแล้ว', $this->admin());
    }

    // ───────────────────────── คืนเงินของรายการหัก ─────────────────────────

    public function test_partial_then_full_refund_of_a_debit_marks_it_refunded_and_blocks_double_refund(): void
    {
        $admin = $this->admin();
        $u = $this->member(200);
        $debit = app(WalletService::class)->debit($u, 139, 'ดูดวงไพ่ยิปซี');

        $this->svc->refundDebit($debit, 39, 'ไพ่ใบที่ 3 ขึ้นผิด', $admin, 'k-p1');
        $this->assertSame('success', $debit->fresh()->status);

        try {
            $this->svc->refundDebit($debit, '100.01', 'คืนเกิน', $admin, 'k-p2');
            $this->fail('คืนเกินยอดที่หักต้องถูกปฏิเสธ');
        } catch (WalletAdminException $e) {
            $this->assertStringContainsString('คืนได้อีกไม่เกิน ฿100.00', $e->getMessage());
        }

        $this->svc->refundDebit($debit, 100, 'คืนส่วนที่เหลือ', $admin, 'k-p3');
        $this->assertSame('refunded', $debit->fresh()->status);
        $this->assertSame('200.00', $this->balance($u));

        // ระบบคืนเงินค่าคำทำนายเดิมต้องไม่คืนซ้ำอีกรอบ
        $this->expectException(\RuntimeException::class);
        app(WalletService::class)->refund($debit->fresh(), 'ระบบคืนซ้ำ');
    }

    // ───────────────────────────── ย้อนรายการ ─────────────────────────────

    public function test_rollback_of_topup_claws_back_once_and_cannot_be_reversed_again(): void
    {
        $admin = $this->admin();
        $u = $this->member(0);
        $topup = app(WalletService::class)->credit($u, 300, 'เติมเงิน (อนุมัติผิดคน)');

        $rb = $this->svc->rollback($topup, 'อนุมัติผิดบัญชี', $admin, 'k-rb1', '300.00');
        $this->assertSame('-300.00', (string) $rb->amount);
        $this->assertSame('0.00', $this->balance($u));
        $this->assertSame('refunded', $topup->fresh()->status);

        $audit = WalletAdminAdjustment::where('action', 'rollback')->sole();
        $this->assertSame($topup->id, $audit->original_transaction_id);
        $this->assertSame('300.00', (string) $audit->balance_before);
        $this->assertSame('0.00', (string) $audit->balance_after);

        // ย้อนรายการเดิมซ้ำไม่ได้
        try {
            $this->svc->rollback($topup->fresh(), 'ย้อนซ้ำ', $admin, 'k-rb2');
            $this->fail('ย้อนซ้ำต้องถูกปฏิเสธ');
        } catch (WalletAdminException) {
        }
        // ย้อน "รายการย้อน" ไม่ได้
        try {
            $this->svc->rollback($rb, 'ย้อนของที่ย้อน', $admin, 'k-rb3');
            $this->fail('ย้อนรายการที่เป็นการย้อนต้องถูกปฏิเสธ');
        } catch (WalletAdminException $e) {
            $this->assertStringContainsString('ย้อนซ้ำไม่ได้', $e->getMessage());
        }
        $this->assertSame(1, WalletAdminAdjustment::where('action', 'rollback')->count());
        $this->assertLedgerReconciles();
    }

    public function test_rollback_of_an_admin_adjustment_works_once_even_though_status_stays_success(): void
    {
        $admin = $this->admin();
        $u = $this->member(0);
        $credit = $this->svc->adjust($u, 'credit', 70, 'เติมผิดจำนวน', $admin, 'k-a1');

        $this->svc->rollback($credit, 'ย้อนเติมผิด', $admin, 'k-a2');
        $this->assertSame('0.00', $this->balance($u));

        $this->expectException(WalletAdminException::class);
        $this->expectExceptionMessage('ถูกย้อนไปแล้ว');
        $this->svc->rollback($credit->fresh(), 'ย้อนอีกรอบ', $admin, 'k-a3');
    }

    public function test_rollback_of_debit_returns_money_as_refund(): void
    {
        $u = $this->member(100);
        $debit = app(WalletService::class)->debit($u, 59, 'แชทกับแม่หมอ');

        $rb = $this->svc->rollback($debit, 'ลูกค้ากดผิด', $this->admin(), 'k-rbd');

        $this->assertSame('refund', $rb->type);
        $this->assertSame('59.00', (string) $rb->amount);
        $this->assertSame('100.00', $this->balance($u));
        $this->assertSame('refunded', $debit->fresh()->status);
        $this->assertLedgerReconciles();
    }

    public function test_rollback_refuses_when_balance_already_spent(): void
    {
        $u = $this->member(0);
        $topup = app(WalletService::class)->credit($u, 100, 'เติมเงิน');
        app(WalletService::class)->debit($u, 80, 'ดูดวง');

        try {
            $this->svc->rollback($topup, 'ยอดไม่พอ', $this->admin(), 'k-spent');
            $this->fail('ย้อนแล้วติดลบต้องถูกปฏิเสธ');
        } catch (WalletAdminException $e) {
            $this->assertStringContainsString('ไม่ยอมให้ยอดติดลบ', $e->getMessage());
        }
        $this->assertSame('20.00', $this->balance($u));
        $this->assertSame('success', $topup->fresh()->status);
    }

    public function test_pending_topup_cannot_be_rolled_back(): void
    {
        $u = $this->member(0);
        $pending = app(WalletService::class)->recordPendingTopup($u, 100, null);

        $this->expectException(WalletAdminException::class);
        $this->svc->rollback($pending, 'ยังไม่อนุมัติ', $this->admin());
    }

    // ───────────────────────────── โอนเครดิต ─────────────────────────────

    public function test_transfer_moves_money_with_audit_on_both_sides_and_is_idempotent(): void
    {
        $admin = $this->admin();
        $a = $this->member(100);
        $b = $this->member(5);

        $res = $this->svc->transfer($a, $b, 40, 'ย้ายเครดิตบัญชีซ้ำ', $admin, 'k-tr1', '100.00');
        $this->assertFalse($res['replayed']);
        $this->assertSame('60.00', $this->balance($a));
        $this->assertSame('45.00', $this->balance($b));

        $again = $this->svc->transfer($a, $b, 40, 'ย้ายเครดิตบัญชีซ้ำ', $admin, 'k-tr1', '100.00');
        $this->assertTrue($again['replayed']);
        $this->assertSame($res['out']->id, $again['out']->id);
        $this->assertSame('60.00', $this->balance($a));
        $this->assertSame('45.00', $this->balance($b));

        $this->assertEqualsCanonicalizing(['transfer_out', 'transfer_in'], WalletAdminAdjustment::pluck('action')->all());

        // ย้อนขาเดียวของการโอนไม่ได้
        try {
            $this->svc->rollback($res['in'], 'ย้อนฝั่งรับ', $admin, 'k-tr2');
            $this->fail('ย้อนขาโอนต้องถูกปฏิเสธ');
        } catch (WalletAdminException $e) {
            $this->assertStringContainsString('โอนกลับแทน', $e->getMessage());
        }
        $this->assertLedgerReconciles();
    }

    public function test_transfer_rules(): void
    {
        $admin = $this->admin();
        $a = $this->member(10);
        $b = $this->member(0);

        foreach ([
            fn () => $this->svc->transfer($a, $b, 11, 'โอนเกินยอด', $admin),
            fn () => $this->svc->transfer($a, $a, 1, 'โอนให้ตัวเอง', $admin),
            fn () => $this->svc->transfer($a, $admin, 1, 'โอนเข้าแอดมินที่ทำรายการ', $admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('ควรถูกปฏิเสธ');
            } catch (WalletAdminException) {
            }
        }
        $this->assertSame('10.00', $this->balance($a));
        $this->assertSame('0.00', $this->balance($b));
        $this->assertSame(0, WalletAdminAdjustment::count());
    }

    // ─────────────────────────── หน้าแอดมิน (Filament) ───────────────────────────

    public function test_admin_adjusts_from_member_list_with_two_step_confirm_and_double_submit_applies_once(): void
    {
        $admin = $this->admin();
        $u = $this->member(100);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $data = ['direction' => 'debit', 'amount' => '30', 'reason' => 'คิดเงินซ้ำ', 'idempotency_key' => 'ui-key-1', 'expected_balance' => '100.00'];

        Livewire::test(ListUsers::class)
            ->callTableAction('adjustWallet', $u, data: $data)
            ->assertHasNoTableActionErrors();
        // กดซ้ำด้วยฟอร์มเดิม (โทเคนเดิม) — ต้องไม่หักซ้ำ
        Livewire::test(ListUsers::class)
            ->callTableAction('adjustWallet', $u, data: $data);

        $this->assertSame('70.00', $this->balance($u));
        $this->assertSame(1, WalletAdminAdjustment::count());
        $this->assertSame('debit', WalletAdminAdjustment::sole()->action);
    }

    public function test_member_list_requires_a_reason(): void
    {
        $admin = $this->admin();
        $u = $this->member(100);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListUsers::class)
            ->callTableAction('adjustWallet', $u, data: ['direction' => 'credit', 'amount' => '30', 'reason' => '', 'idempotency_key' => 'ui-key-2', 'expected_balance' => '100.00'])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame('100.00', $this->balance($u));
    }

    public function test_wallet_buttons_are_hidden_on_the_admins_own_row(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListUsers::class)->assertTableActionHidden('adjustWallet', $admin);
    }

    public function test_rollback_button_on_transaction_list(): void
    {
        $admin = $this->admin();
        $u = $this->member(0);
        $topup = app(WalletService::class)->credit($u, 120, 'เติมเงิน');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListWalletTransactions::class)
            ->assertTableActionVisible('rollback', $topup)
            ->callTableAction('rollback', $topup, data: ['reason' => 'อนุมัติผิด', 'idempotency_key' => 'ui-rb-1', 'expected_balance' => '120.00'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('0.00', $this->balance($u));
        $this->assertSame('refunded', $topup->fresh()->status);

        $rb = WalletTransaction::where('reference_id', $topup->id)->where('method', 'admin')->sole();
        Livewire::test(ListWalletTransactions::class)->assertTableActionHidden('rollback', $rb);
    }

    public function test_confirm_step_shows_balance_before_and_after(): void
    {
        $admin = $this->admin();
        $u = $this->member(100);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListUsers::class)
            ->mountTableAction('adjustWallet', $u)
            ->setTableActionData(['direction' => 'debit', 'amount' => '30', 'reason' => 'ตรวจหน้ายืนยัน'])
            ->assertSee('ยอดก่อน → หลัง', false)
            ->assertSee('฿100.00')
            ->assertSee('฿70.00');

        $this->assertSame('100.00', $this->balance($u), 'เปิดหน้ายืนยันเฉย ๆ ต้องไม่ขยับเงิน');
    }

    public function test_refund_and_transfer_buttons_on_transaction_list(): void
    {
        $admin = $this->admin();
        $a = $this->member(200);
        $b = $this->member(0);
        $debit = app(WalletService::class)->debit($a, 139, 'ดูดวงเชิงลึก');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListWalletTransactions::class)
            ->callTableAction('refundDebit', $debit, data: ['amount' => '139', 'reason' => 'ผลไม่ออก', 'idempotency_key' => 'ui-rf-1', 'expected_balance' => '61.00'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('200.00', $this->balance($a));
        $this->assertSame('refunded', $debit->fresh()->status);

        Livewire::test(ListWalletTransactions::class)
            ->callTableAction('transfer', data: ['from_user_id' => $a->id, 'to_user_id' => $b->id, 'amount' => '50', 'reason' => 'รวมบัญชี', 'idempotency_key' => 'ui-tr-1', 'expected_balance' => '200.00'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('150.00', $this->balance($a));
        $this->assertSame('50.00', $this->balance($b));
        $this->assertLedgerReconciles();
    }

    public function test_history_page_is_admin_only(): void
    {
        $admin = $this->admin();
        $u = $this->member(0);
        $this->svc->adjust($u, 'credit', 15, 'ของขวัญวันเกิด', $admin, 'k-hist');

        $this->actingAs($admin)->get('/admin/wallet-admin-adjustments')
            ->assertOk()->assertSee('ของขวัญวันเกิด')->assertSee('แอดมินทดสอบ');
        $this->actingAs($admin)->get('/admin/wallet-admin-adjustments/' . WalletAdminAdjustment::sole()->id)->assertOk();

        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get('/admin/wallet-admin-adjustments')->assertForbidden();
    }

    public function test_member_and_transaction_admin_pages_still_render(): void
    {
        $admin = $this->admin();
        $u = $this->member(50);
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->get('/admin/users/' . $u->id)->assertOk();
        $this->actingAs($admin)->get('/admin/wallet-transactions')->assertOk();
    }
}
