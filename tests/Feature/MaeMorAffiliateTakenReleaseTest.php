<?php

namespace Tests\Feature;

use App\Models\AffiliateBill;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Affiliate\MaeMorAffiliate;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 🌙 (2026-11-03 FB web-only · J3) ผังแม่หมอ: 409 thaiprompt_taken ต้องลองใหม่ได้ + ลบบัญชีต้องปลดการผูก
 *
 * - Thaiprompt ไม่สร้างตัวตนเงาใต้ผู้แนะนำกลางให้แล้ว แต่ตอบ 409 thaiprompt_taken เมื่อบัญชี Thaiprompt
 *   ยังถูกบัญชีจันทราอื่นถือไว้ → ฝั่งนี้ห้ามถือเป็น "ปฏิเสธ" (ห้ามล้างรหัสเชิญ ห้ามทิ้งบิลรอแอดมิน)
 * - ลบบัญชี = บอกแม่หมอให้ปลด (POST affiliate/accounts/{ref}/release) · ไม่สำเร็จ = sync-bills ลองซ้ำจนได้
 * - ส่ง page_code (สาขาเพจ) ไปด้วยเมื่อมี
 */
class MaeMorAffiliateTakenReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    /** สถานะ + เนื้อหาที่ปลายทางแต่ละเส้นตอบ (เปลี่ยนได้ระหว่างเทสต์) */
    private array $accounts = [201, ['data' => ['member_code' => 'MLMBUYER01', 'enrolled_now' => true]]];

    private array $bills = [201, null];

    private array $release = [200, ['data' => ['released' => true, 'already_released' => false, 'linked_via' => 'sso']]];

    protected function setUp(): void
    {
        parent::setUp();

        // ตัวรันเทสต์ของ worktree ชี้ base_path ไป repo หลัก (migration ใหม่ยังไม่อยู่ที่นั่น) — CI มีคอลัมน์แล้ว ข้ามเอง
        if (! Schema::hasColumn('users', 'maemor_released_at')) {
            (require __DIR__ . '/../../database/migrations/2026_11_03_000001_add_maemor_released_at_to_users.php')->up();
        }
        // ค่าที่จำไว้ต่อโปรเซส อาจถูกเทสต์คลาสอื่นตั้งไว้ก่อนคอลัมน์ถูกเพิ่ม
        (new \ReflectionProperty(MaeMorAffiliate::class, 'hasReleasedColumn'))->setValue(null, null);

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', '9f1c-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'super-secret-value', 'thaiprompt', true);
        Setting::put('affiliate_sync_since', now()->subDay()->toIso8601String(), 'affiliate');

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/oauth/token')) {
                return Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]);
            }
            if (preg_match('#/affiliate/accounts/\d+/release$#', $url)) {
                return Http::response($this->release[1], $this->release[0]);
            }
            if (str_ends_with($url, '/affiliate/accounts')) {
                if (in_array((int) $request['user_ref'], $this->takenRefs, true)) {
                    return Http::response(self::taken(), 409);
                }

                return Http::response($this->accounts[1], $this->accounts[0]);
            }
            if (str_ends_with($url, '/affiliate/bills')) {
                [$status, $body] = $this->bills;
                // ตอบตามลูกค้า: user_ref ที่อยู่ใน takenRefs ได้ 409 เสมอ (เส้น /accounts ก็เช่นกัน)
                if (in_array((int) $request['user_ref'], $this->takenRefs, true)) {
                    return Http::response(self::taken(), 409);
                }

                return Http::response($body ?? ['data' => [
                    'bill_reference' => 'JW-' . $request['bill_id'],
                    'member_code' => 'MLMBUYER01',
                    'commissions' => [['level' => 1, 'amount' => 9.9]],
                ]], $status);
            }

            return Http::response(null, 404);
        });
    }

    /** user_ref ที่ปลายทาง /accounts และ /bills ตอบ 409 thaiprompt_taken */
    private array $takenRefs = [];

    private static function taken(): array
    {
        return [
            'success' => false,
            'reason_code' => 'thaiprompt_taken',
            'message' => 'บัญชี Thaiprompt นี้ผูกกับบัญชีจันทราอื่นอยู่',
            'data' => ['reason_code' => 'thaiprompt_taken'],
        ];
    }

    /* ======================= 409 thaiprompt_taken ======================= */

    /** เข้าผังได้ 409 taken → สถานะ 'taken' ไม่ใช่ 'rejected' และรหัสเชิญยังอยู่ (ลองใหม่หลังปลดได้) */
    public function test_taken_on_enrol_is_retryable_and_keeps_the_referral_code(): void
    {
        $u = User::factory()->create(['thaiprompt_user_id' => 777, 'pending_referral_code' => 'MLMABCD1234']);
        $this->accounts = [409, self::taken()];

        $result = app(MaeMorAffiliate::class)->ensureMember($u);

        $this->assertSame(MaeMorAffiliate::STATUS_TAKEN, $result['status']);
        $this->assertNotSame('rejected', $result['status']);
        $this->assertSame('MLMABCD1234', $u->fresh()->pending_referral_code);

        // บัญชีเก่าปลดแล้ว → ส่งซ้ำผ่าน รหัสเชิญถูกใช้ตามปกติ
        $this->accounts = [201, ['data' => ['member_code' => 'MLMBUYER01', 'enrolled_now' => true]]];
        $this->assertSame('ok', app(MaeMorAffiliate::class)->ensureMember($u->fresh())['status']);
        $this->assertNull($u->fresh()->pending_referral_code);
        $this->assertSame('MLMBUYER01', $u->fresh()->maemor_member_code);
    }

    /** ปฏิเสธจริง (422) ยังเป็น 'rejected' ตามเดิม — 409 อื่นที่ไม่ใช่ taken ก็เช่นกัน */
    public function test_other_rejections_keep_their_old_meaning(): void
    {
        $u = User::factory()->create(['pending_referral_code' => 'MLMABCD1234']);
        $this->accounts = [409, ['reason_code' => 'bill_conflict', 'message' => 'x']];

        $this->assertSame('rejected', app(MaeMorAffiliate::class)->ensureMember($u)['status']);
        $this->assertNull($u->fresh()->pending_referral_code);
    }

    /** sync-bills เก็บตกรหัสเชิญ: taken ไม่หยุดรอบ ลูกค้าคนถัดไปยังเข้าผังได้ และรหัสของคน taken ยังค้างไว้ */
    public function test_pending_referral_retry_does_not_stop_on_taken(): void
    {
        $taken = User::factory()->create(['thaiprompt_user_id' => 777, 'pending_referral_code' => 'MLMAAAA0001']);
        $next = User::factory()->create(['pending_referral_code' => 'MLMBBBB0002']);
        $this->takenRefs = [$taken->id];

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $this->assertSame('MLMAAAA0001', $taken->fresh()->pending_referral_code);
        $this->assertNull($taken->fresh()->maemor_member_code);
        $this->assertNull($next->fresh()->pending_referral_code);
        $this->assertSame('MLMBUYER01', $next->fresh()->maemor_member_code);
    }

    /**
     * บิลได้ 409 taken → failed แต่ลองใหม่เอง (มี next_attempt_at · ไม่ตกไปกองรอแอดมิน)
     *   และไม่หยุดรอบ — บิลของลูกค้าคนอื่นในรอบเดียวกันส่งได้ · พอบัญชีเก่าปลดแล้ว บิลเดิมส่งผ่าน
     */
    public function test_taken_bill_is_retried_and_does_not_block_other_bills(): void
    {
        [$takenUser, $takenTx] = $this->bill(99, 'เปิดไพ่: Celtic Cross', ageMinutes: 30, attrs: ['thaiprompt_user_id' => 777]);
        [, $otherTx] = $this->bill(39, 'ดูดวงเชิงลึก', ageMinutes: 30);
        $this->takenRefs = [$takenUser->id];

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $bill = AffiliateBill::where('wallet_transaction_id', $takenTx->id)->firstOrFail();
        $this->assertSame(AffiliateBill::STATUS_FAILED, $bill->status);
        $this->assertNotNull($bill->next_attempt_at, 'taken ต้องลองใหม่เอง ไม่ใช่รอแอดมิน');
        $this->assertStringContainsString('thaiprompt_taken', (string) $bill->last_error);
        $this->assertStringStartsWith('taken', (string) $bill->last_error);
        $this->assertFalse(AffiliateBill::needsAdmin()->whereKey($bill->id)->exists());
        $this->assertSame(AffiliateBill::STATUS_SENT, AffiliateBill::where('wallet_transaction_id', $otherTx->id)->value('status'));

        // บัญชีเก่าปลดแล้ว → รอบที่ถึงเวลาส่งผ่าน
        $this->takenRefs = [];
        $this->travel(31)->minutes();
        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $bill->refresh();
        $this->assertSame(AffiliateBill::STATUS_SENT, $bill->status);
        $this->assertSame('JW-' . $takenTx->id, $bill->bill_reference);
    }

    /** sendBill ตรง ๆ คืน 'taken' (ผู้เรียกจะไม่ถือเป็นล่ม/ปฏิเสธ) */
    public function test_send_bill_reports_taken(): void
    {
        [$u, $tx] = $this->bill(99, 'เปิดไพ่', ageMinutes: 30, attrs: ['thaiprompt_user_id' => 777]);
        $this->takenRefs = [$u->id];
        $bill = AffiliateBill::create([
            'wallet_transaction_id' => $tx->id, 'user_id' => $u->id, 'amount' => 99,
            'product' => 'เปิดไพ่', 'status' => AffiliateBill::STATUS_PENDING,
        ]);

        $this->assertSame(MaeMorAffiliate::STATUS_TAKEN, app(MaeMorAffiliate::class)->sendBill($bill->load('walletTransaction', 'user')));
        $this->assertSame(1, $bill->fresh()->attempts);
    }

    /* ============================ page_code ============================ */

    public function test_page_code_is_sent_when_known_and_omitted_otherwise(): void
    {
        $u = User::factory()->create();
        app(MaeMorAffiliate::class)->ensureMember($u, 'branch_b<script>');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/affiliate/accounts')
            && $r['page_code'] === 'branch_bscript');

        $v = User::factory()->create();
        app(MaeMorAffiliate::class)->ensureMember($v);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/affiliate/accounts')
            && (int) $r['user_ref'] === $v->id && ! array_key_exists('page_code', $r->data()));

        // บิลที่การซื้อจดสาขาไว้ใน meta → ส่ง page_code · ไม่จด = ไม่ส่ง (Thaiprompt ใช้สาขาแรกของลูกค้า)
        [, $withPage] = $this->bill(99, 'เปิดไพ่', ageMinutes: 30, meta: ['page_code' => 'BRANCH-B']);
        [, $noPage] = $this->bill(39, 'ดูดวง', ageMinutes: 30);
        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/affiliate/bills')
            && (int) $r['bill_id'] === $withPage->id && $r['page_code'] === 'BRANCH-B');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/affiliate/bills')
            && (int) $r['bill_id'] === $noPage->id && ! array_key_exists('page_code', $r->data()));
    }

    /* ========================= ลบบัญชี → ปลด ========================= */

    public function test_account_deletion_releases_the_mapping(): void
    {
        [$u, $token] = $this->userWithToken(['thaiprompt_user_id' => 777]);

        $this->withToken($token)->postJson('/api/v1/account/delete', ['password' => 'secret-pass-1'])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/affiliate/accounts/' . $u->id . '/release')
            && $r['reason'] === 'account_deleted');
        $gone = User::withTrashed()->find($u->id);
        $this->assertNotNull($gone->deleted_at);
        $this->assertNull($gone->thaiprompt_user_id);
        $this->assertNotNull($gone->maemor_released_at);
    }

    /** หน้าเว็บ (ProfileController) ใช้เส้นเดียวกัน */
    public function test_web_profile_deletion_releases_too(): void
    {
        [$u] = $this->userWithToken();

        $this->actingAs($u)->delete('/profile', ['password' => 'secret-pass-1'])->assertRedirect('/');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/affiliate/accounts/' . $u->id . '/release'));
        $this->assertNotNull(User::withTrashed()->find($u->id)->maemor_released_at);
    }

    /** Thaiprompt ล่มตอนลบ → ลบได้ตามปกติ · ค้างไว้แล้ว sync-bills ปลดให้ภายหลัง */
    public function test_release_failure_never_blocks_deletion_and_is_retried(): void
    {
        [$u, $token] = $this->userWithToken(['thaiprompt_user_id' => 777]);
        $this->release = [503, ['reason_code' => 'busy', 'message' => 'x']];

        $this->withToken($token)->postJson('/api/v1/account/delete', ['password' => 'secret-pass-1'])->assertOk();

        $gone = User::withTrashed()->find($u->id);
        $this->assertNotNull($gone->deleted_at);
        $this->assertNull($gone->maemor_released_at);

        // ยังล่มอยู่ → รอบนี้ยังไม่ปลด
        $this->artisan('affiliate:sync-bills')->assertSuccessful();
        $this->assertNull(User::withTrashed()->find($u->id)->maemor_released_at);

        // กลับมาแล้ว → ปลดสำเร็จ และรอบถัดไปไม่ยิงซ้ำ
        $this->release = [200, ['data' => ['released' => true, 'already_released' => false, 'linked_via' => 'sso']]];
        $this->artisan('affiliate:sync-bills')->assertSuccessful();
        $this->assertNotNull(User::withTrashed()->find($u->id)->maemor_released_at);

        $before = $this->releaseCalls();
        $this->artisan('affiliate:sync-bills')->assertSuccessful();
        $this->assertSame($before, $this->releaseCalls());
    }

    /** Thaiprompt ยังไม่ deploy เส้น release (404 ไม่มี reason_code) → ยังค้างไว้ ไม่ปิดงานเอง */
    public function test_release_endpoint_missing_keeps_it_pending(): void
    {
        $u = $this->deletedUser();
        $this->release = [404, null];

        $this->assertSame('unsupported', app(MaeMorAffiliate::class)->releaseMember($u));
        $this->assertNull(User::withTrashed()->find($u->id)->maemor_released_at);
    }

    /** แม่หมอไม่รู้จัก user_ref นี้ (ไม่เคยเข้าผัง) = ไม่มีอะไรต้องปลด → จบ */
    public function test_unknown_account_counts_as_released(): void
    {
        $u = $this->deletedUser();
        $this->release = [404, ['reason_code' => 'unknown_account', 'data' => ['released' => false, 'reason_code' => 'unknown_account']]];

        $this->assertSame('released', app(MaeMorAffiliate::class)->releaseMember($u));
        $this->assertNotNull(User::withTrashed()->find($u->id)->maemor_released_at);
    }

    /** บัญชีที่ลบก่อนมีเส้นนี้ก็ถูกเก็บตก · บัญชีที่ยังใช้งานอยู่ไม่ถูกปลดเด็ดขาด */
    public function test_sync_releases_old_deletions_but_never_live_accounts(): void
    {
        $old = $this->deletedUser();
        $live = User::factory()->create(['thaiprompt_user_id' => 888]);
        // soft delete จากทางอื่น (ไม่ใช่ AccountDeletion) — ไม่ใช่ลูกค้าที่ขอลบ ห้ามปลด
        $other = User::factory()->create();
        $other->delete();

        $this->artisan('affiliate:sync-bills')->assertSuccessful();

        $this->assertNotNull(User::withTrashed()->find($old->id)->maemor_released_at);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/affiliate/accounts/' . $live->id . '/release'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/affiliate/accounts/' . $other->id . '/release'));
    }

    /* ============================ helpers ============================ */

    private function userWithToken(array $attrs = []): array
    {
        $u = User::factory()->create(array_merge(['password' => Hash::make('secret-pass-1')], $attrs));

        return [$u, $u->createToken('phone-a')->plainTextToken];
    }

    /** บัญชีที่ถูกลบแบบ AccountDeletion ไปแล้ว (อีเมล deleted+{id}) แต่ยังไม่ได้ปลด */
    private function deletedUser(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['email' => 'deleted+' . $u->id . '@deleted.local', 'thaiprompt_user_id' => null])->save();
        $u->delete();

        return $u;
    }

    private function releaseCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/release'))->count();
    }

    private function bill(float $amount, string $label, int $ageMinutes, array $attrs = [], ?array $meta = null): array
    {
        $user = User::factory()->create($attrs);
        $wallet = app(WalletService::class);
        $wallet->credit($user, 500, 'เติมเงิน');
        $tx = $wallet->debit($user, $amount, $label, array_filter(['reference_type' => 'reading', 'meta' => $meta]));
        WalletTransaction::whereKey($tx->id)->update(['created_at' => now()->subMinutes($ageMinutes)]);

        return [$user, $tx->fresh()];
    }
}
