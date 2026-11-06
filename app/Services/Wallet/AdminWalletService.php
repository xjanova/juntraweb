<?php

namespace App\Services\Wallet;

use App\Exceptions\WalletAdminException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletAdminAdjustment;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * แอดมินจัดการเครดิตในวอลเลตของผู้ใช้จันทรา — เติม / หัก / คืนเงิน / ย้อนรายการ / โอนระหว่างบัญชี
 *
 * แตะเฉพาะวอลเลตของจันทราเอง (ตาราง wallets) — ห้ามเขียนกลับไปที่กระเป๋า Thaiprompt
 * (จันทราอ่านยอด Thaiprompt ได้อย่างเดียวผ่าน JuntraMlmReadService)
 *
 * กติกาทุกเส้นทาง (แบบเดียวกับ Thaiprompt PR #2662):
 *  1. เฉพาะ role admin — editor เข้าแผงได้แต่แตะเงินไม่ได้
 *  2. ต้องมีเหตุผลทุกครั้ง
 *  3. DB transaction + lockForUpdate แถววอลเลต (โอน = lock สองใบเรียงตาม id กัน deadlock)
 *  4. idempotency_key ต่อวอลเลต — ส่งฟอร์มเดิมซ้ำได้ผลเดิม ไม่แตะเงินซ้ำ
 *     (เช็คหลัง lock + unique index สำรองกรณีชนกันพร้อมกันเป๊ะ)
 *  5. expected_balance — ยอดที่แอดมินเห็นตอนยืนยัน ถ้ายอดจริงเปลี่ยนไปแล้วให้ยืนยันใหม่
 *  6. ห้ามยอดติดลบ — หักไม่พอ = ปฏิเสธทั้งรายการ (ไม่หักเท่าที่มีแบบเงียบ ๆ)
 *  7. แอดมินแตะวอลเลตของตัวเองไม่ได้ (จันทราไม่มี super admin → ต้องให้แอดมินอีกคนทำ)
 *  8. ย้อนรายการได้ครั้งเดียว และห้ามย้อน "รายการที่เป็นการย้อน/คืนของรายการอื่น"
 *  9. ทุกรายการลง wallet_admin_adjustments (ใคร/เมื่อไร/ยอดก่อน-หลัง/เหตุผล/IP/UA)
 *
 * ยอดเงินยังลง wallet_transactions แบบมีเครื่องหมาย + balance_after เสมอ
 * → wallet:reconcile (SUM(amount) = balance) ยังถูกต้อง
 *
 * ค่าที่คืน: WalletTransaction ที่ wasRecentlyCreated = true เมื่อทำรายการใหม่,
 * false เมื่อเป็นการส่งซ้ำแล้วได้รายการเดิมกลับมา
 */
class AdminWalletService
{
    /** เพดานต่อรายการ (กันพิมพ์ศูนย์เกิน) */
    public const MAX_AMOUNT = '100000.00';

    /** method ที่ใช้ระบุว่ารายการนี้แอดมินทำด้วยมือ */
    public const METHOD = 'admin';

    public function __construct(private readonly WalletService $wallets)
    {
    }

    /**
     * เติม (credit) / หัก (debit) / คืนเงินแบบระบุยอดเอง (refund) ให้ผู้ใช้
     *
     * @param  string  $direction  credit | debit | refund
     * @param  string|null  $expectedBalance  ยอดที่แอดมินเห็นตอนยืนยัน (null = ไม่ตรวจ)
     */
    public function adjust(
        User $target,
        string $direction,
        string|int|float $amount,
        string $reason,
        User $admin,
        ?string $idempotencyKey = null,
        string|int|float|null $expectedBalance = null,
    ): WalletTransaction {
        if (! in_array($direction, ['credit', 'debit', 'refund'], true)) {
            throw new WalletAdminException('ประเภทรายการไม่ถูกต้อง');
        }
        $this->assertAdmin($admin);
        $this->assertTargetUsable($target);
        $amount = $this->normalizeAmount($amount);
        $reason = $this->normalizeReason($reason);
        $idempotencyKey = $this->normalizeKey($idempotencyKey);
        $wallet = $this->wallets->getOrCreate($target);

        return $this->run($idempotencyKey, function () use ($wallet, $direction, $amount, $reason, $admin, $idempotencyKey, $expectedBalance) {
            $locked = $this->lockWallet($wallet->id);
            if ($replay = $this->findReplay($locked->id, $idempotencyKey)) {
                return $replay;
            }
            $this->assertMayTouch($locked, $admin);
            $this->assertExpectedBalance($locked, $expectedBalance);

            [$type, $label] = match ($direction) {
                'credit' => ['adjustment', 'แอดมินเติมเครดิต'],
                'debit'  => ['adjustment', 'แอดมินหักเครดิต'],
                'refund' => ['refund', 'แอดมินคืนเงิน'],
            };

            return $this->writeEntry($locked, $admin, [
                'action'          => $direction,
                'credit'          => $direction !== 'debit',
                'type'            => $type,
                'amount'          => $amount,
                'reason'          => $reason,
                'description'     => $label . ': ' . $reason,
                'idempotency_key' => $idempotencyKey,
            ]);
        }, fn () => $this->entryFor($wallet->id, $idempotencyKey));
    }

    /**
     * คืนเงินของ "รายการหักค่าบริการ" ที่ระบุ — คืนบางส่วนได้ แต่รวมแล้วไม่เกินยอดที่หักไป
     * คืนครบเมื่อไร รายการหักจะถูกตั้งเป็น refunded (ระบบคืนเงินค่าคำทำนายจะไม่คืนซ้ำ)
     */
    public function refundDebit(
        WalletTransaction $debit,
        string|int|float $amount,
        string $reason,
        User $admin,
        ?string $idempotencyKey = null,
        string|int|float|null $expectedBalance = null,
    ): WalletTransaction {
        $this->assertAdmin($admin);
        $amount = $this->normalizeAmount($amount);
        $reason = $this->normalizeReason($reason);
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->run($idempotencyKey, function () use ($debit, $amount, $reason, $admin, $idempotencyKey, $expectedBalance) {
            $locked = $this->lockWallet((int) $debit->wallet_id);
            if ($replay = $this->findReplay($locked->id, $idempotencyKey)) {
                return $replay;
            }
            $this->assertMayTouch($locked, $admin);
            $this->assertTargetUsable($locked->user()->withTrashed()->first());
            $this->assertExpectedBalance($locked, $expectedBalance);

            // อ่านรายการหักใหม่หลัง lock — สถานะอาจเปลี่ยนระหว่างรอ
            $original = WalletTransaction::whereKey($debit->id)->lockForUpdate()->first();
            if (! $original || $original->type !== 'debit' || $original->status !== 'success' || $original->balance_after === null) {
                throw new WalletAdminException('คืนเงินได้เฉพาะรายการหักค่าบริการที่สำเร็จและยังไม่ถูกคืนเต็มจำนวน');
            }

            $charged   = $this->abs((string) $original->amount);
            $refunded  = $this->refundedSoFar($original);
            $remaining = bcsub($charged, $refunded, 2);
            if (bccomp($amount, $remaining, 2) > 0) {
                throw new WalletAdminException(
                    'คืนเกินยอดที่หักไป — รายการนี้หัก ฿' . $this->fmt($charged)
                    . ' คืนไปแล้ว ฿' . $this->fmt($refunded) . ' คืนได้อีกไม่เกิน ฿' . $this->fmt($remaining)
                );
            }

            $tx = $this->writeEntry($locked, $admin, [
                'action'                  => 'refund',
                'credit'                  => true,
                'type'                    => 'refund',
                'amount'                  => $amount,
                'reason'                  => $reason,
                'description'             => 'คืนเงิน: ' . ($original->description ?: 'รายการ #' . $original->id) . ' — ' . $reason,
                'reference_type'          => 'wallet_transaction',
                'reference_id'            => $original->id,
                'original_transaction_id' => $original->id,
                'idempotency_key'         => $idempotencyKey,
            ]);

            if (bccomp(bcadd($refunded, $amount, 2), $charged, 2) >= 0) {
                $original->update(['status' => 'refunded']);
            }

            return $tx;
        }, fn () => $this->entryFor((int) $debit->wallet_id, $idempotencyKey));
    }

    /**
     * ย้อนรายการที่ขยับเงินไปแล้ว (สร้างรายการกลับทิศ ไม่ลบรายการเดิม)
     *
     * - ย้อนได้ครั้งเดียวต่อรายการ
     * - ห้ามย้อนรายการที่เป็นการย้อน/คืน/เรียกคืนของรายการอื่น (กันย้อนกลับไปกลับมา)
     * - ห้ามย้อนขาใดขาหนึ่งของการโอน (เงินจะเกิด/หายฝั่งเดียว) — ให้โอนกลับแทน
     * - ย้อนรายการเงินเข้าแล้วยอดไม่พอ = ปฏิเสธ (ไม่ยอมติดลบ)
     */
    public function rollback(
        WalletTransaction $transaction,
        string $reason,
        User $admin,
        ?string $idempotencyKey = null,
        string|int|float|null $expectedBalance = null,
    ): WalletTransaction {
        $this->assertAdmin($admin);
        $reason = $this->normalizeReason($reason);
        $idempotencyKey = $this->normalizeKey($idempotencyKey);

        return $this->run($idempotencyKey, function () use ($transaction, $reason, $admin, $idempotencyKey, $expectedBalance) {
            $locked = $this->lockWallet((int) $transaction->wallet_id);
            if ($replay = $this->findReplay($locked->id, $idempotencyKey)) {
                return $replay;
            }
            $this->assertMayTouch($locked, $admin);
            $this->assertTargetUsable($locked->user()->withTrashed()->first());
            $this->assertExpectedBalance($locked, $expectedBalance);

            $original = WalletTransaction::whereKey($transaction->id)->lockForUpdate()->first();
            if (! $original || $original->status !== 'success' || $original->balance_after === null) {
                throw new WalletAdminException('ย้อนได้เฉพาะรายการที่สำเร็จและขยับยอดเงินไปแล้วเท่านั้น');
            }
            if (! in_array($original->type, ['topup', 'debit', 'refund', 'adjustment'], true)) {
                throw new WalletAdminException('รายการประเภทนี้ย้อนไม่ได้');
            }
            $origAction = data_get($original->meta, 'admin_action');
            if (in_array($origAction, ['transfer_out', 'transfer_in'], true)) {
                throw new WalletAdminException('รายการโอนย้อนฝั่งเดียวไม่ได้ (เงินจะเกิด/หายฝั่งเดียว) — ให้ใช้ "โอนเครดิต" โอนกลับแทน');
            }
            if ($origAction === 'rollback' || $original->reference_type === 'wallet_transaction') {
                throw new WalletAdminException('รายการนี้เป็นการย้อน/คืนเงินของรายการอื่นอยู่แล้ว ย้อนซ้ำไม่ได้ — ถ้าต้องแก้ ให้ใช้เติม/หักเครดิตแทน');
            }
            if (WalletAdminAdjustment::where('action', 'rollback')->where('original_transaction_id', $original->id)->exists()) {
                throw new WalletAdminException('รายการนี้ถูกย้อนไปแล้ว');
            }
            if ($original->type === 'debit' && bccomp($this->refundedSoFar($original), '0', 2) > 0) {
                throw new WalletAdminException('รายการหักนี้มีการคืนเงินไปบางส่วนแล้ว — ใช้ "คืนเงิน" คืนส่วนที่เหลือแทนการย้อน');
            }

            $origAmount = (string) $original->amount;
            $wasCredit  = bccomp($origAmount, '0', 2) > 0;
            $amount     = $this->abs($origAmount);
            if (bccomp($amount, '0', 2) === 0) {
                throw new WalletAdminException('รายการนี้ไม่ได้ขยับยอดเงิน ไม่ต้องย้อน');
            }

            $tx = $this->writeEntry($locked, $admin, [
                'action'                  => 'rollback',
                'credit'                  => ! $wasCredit,
                // คืนค่าบริการที่หักไป = "คืนเครดิต" ในประวัติของลูกค้า · อย่างอื่น = ปรับยอด
                'type'                    => $original->type === 'debit' ? 'refund' : 'adjustment',
                'amount'                  => $amount,
                'reason'                  => $reason,
                'description'             => 'ย้อนรายการ #' . $original->id . ' (' . $original->typeLabel() . '): ' . $reason,
                'reference_type'          => 'wallet_transaction',
                'reference_id'            => $original->id,
                'original_transaction_id' => $original->id,
                'idempotency_key'         => $idempotencyKey,
                'meta'                    => ['original_type' => $original->type, 'original_amount' => $origAmount],
            ]);

            // เติมเงิน/หักค่าบริการที่ถูกย้อน = refunded (ความหมายเดียวกับ reverseTopup/refund เดิม
            // ระบบคืนเงินค่าคำทำนายและสถิติรายได้จะเห็นตรงกัน)
            if (in_array($original->type, ['topup', 'debit'], true)) {
                $original->update(['status' => 'refunded']);
            }

            return $tx;
        }, fn () => $this->entryFor((int) $transaction->wallet_id, $idempotencyKey));
    }

    /**
     * โอนเครดิตจากวอลเลตหนึ่งไปอีกวอลเลตโดยแอดมิน (เช่น ลูกค้ามีสองบัญชี)
     *
     * @return array{out: WalletTransaction, in: WalletTransaction|null, replayed: bool}
     */
    public function transfer(
        User $from,
        User $to,
        string|int|float $amount,
        string $reason,
        User $admin,
        ?string $idempotencyKey = null,
        string|int|float|null $expectedBalance = null,
    ): array {
        $this->assertAdmin($admin);
        if ((int) $from->id === (int) $to->id) {
            throw new WalletAdminException('โอนเข้าบัญชีเดียวกันไม่ได้');
        }
        $this->assertTargetUsable($from);
        $this->assertTargetUsable($to);
        $amount = $this->normalizeAmount($amount);
        $reason = $this->normalizeReason($reason);
        $idempotencyKey = $this->normalizeKey($idempotencyKey);
        $fromWallet = $this->wallets->getOrCreate($from);
        $toWallet   = $this->wallets->getOrCreate($to);

        return $this->run($idempotencyKey, function () use ($fromWallet, $toWallet, $amount, $reason, $admin, $idempotencyKey, $expectedBalance, $from, $to) {
            // lock สองใบเรียงตาม id เสมอ กัน deadlock กับการโอนสวนทาง
            $locked = Wallet::whereIn('id', [$fromWallet->id, $toWallet->id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $src = $locked->get($fromWallet->id);
            $dst = $locked->get($toWallet->id);
            if (! $src || ! $dst) {
                throw new WalletAdminException('ไม่พบวอลเลต');
            }

            if ($this->findReplay($src->id, $idempotencyKey)) {
                return $this->transferReplay($src->id, $dst->id, $idempotencyKey);
            }

            $this->assertMayTouch($src, $admin);
            $this->assertMayTouch($dst, $admin);
            $this->assertExpectedBalance($src, $expectedBalance);

            $out = $this->writeEntry($src, $admin, [
                'action'          => 'transfer_out',
                'credit'          => false,
                'type'            => 'adjustment',
                'amount'          => $amount,
                'reason'          => $reason,
                'description'     => 'แอดมินโอนเครดิตไปบัญชีอื่น: ' . $reason,
                'reference_type'  => 'user',
                'reference_id'    => $to->id,
                'idempotency_key' => $idempotencyKey,
                'meta'            => ['counterpart_user_id' => $to->id],
            ]);
            $in = $this->writeEntry($dst, $admin, [
                'action'          => 'transfer_in',
                'credit'          => true,
                'type'            => 'adjustment',
                'amount'          => $amount,
                'reason'          => $reason,
                'description'     => 'แอดมินโอนเครดิตเข้าจากบัญชีอื่น: ' . $reason,
                'reference_type'  => 'user',
                'reference_id'    => $from->id,
                'idempotency_key' => $idempotencyKey,
                'meta'            => ['counterpart_user_id' => $from->id, 'transfer_out_transaction_id' => $out->id],
            ]);

            return ['out' => $out, 'in' => $in, 'replayed' => false];
        }, fn () => $this->transferReplay($fromWallet->id, $toWallet->id, $idempotencyKey));
    }

    /** ยอดที่คืนให้รายการหักนี้ไปแล้ว (ระบบคืนเอง / แอดมินคืน / แอดมินย้อน) — นับที่เดียวกับ WalletService::refund() */
    public function refundedSoFar(WalletTransaction $debit): string
    {
        return $this->wallets->refundedSoFar($debit);
    }

    // ───────────────────────────── ภายใน ─────────────────────────────

    /**
     * รันใน DB transaction — ถ้าชน unique index ของ idempotency_key (กดซ้ำพร้อมกันเป๊ะ)
     * ถือว่าเป็นการส่งซ้ำ แล้วคืนผลของรายการแรก ผ่าน $replay (รูปเดียวกับที่ $work คืน —
     * โอน = array, อย่างอื่น = WalletTransaction) · หาผลเดิมไม่เจอ = โยน error เดิมต่อ
     *
     * @param  callable(): mixed  $replay  คืน null เมื่อหาผลเดิมไม่เจอ
     */
    private function run(?string $idempotencyKey, callable $work, callable $replay): mixed
    {
        try {
            return DB::transaction($work);
        } catch (QueryException $e) {
            $dup = in_array((string) ($e->errorInfo[1] ?? ''), ['1062', '19'], true)
                || str_contains($e->getMessage(), 'waa_idem_wallet_unique');
            if ($idempotencyKey && $dup && ($first = $replay()) !== null) {
                return $first;
            }
            throw $e;
        }
    }

    /** ผลเดิมของการโอนที่ใช้โทเคนนี้ (ทั้งสองขา) — null ถ้ายังไม่มีขาออก */
    private function transferReplay(int $srcWalletId, int $dstWalletId, ?string $idempotencyKey): ?array
    {
        $out = $this->entryFor($srcWalletId, $idempotencyKey);
        if (! $out) {
            return null;
        }

        return ['out' => $out, 'in' => $this->entryFor($dstWalletId, $idempotencyKey), 'replayed' => true];
    }

    private function lockWallet(int $walletId): Wallet
    {
        $wallet = Wallet::whereKey($walletId)->lockForUpdate()->first();
        if (! $wallet) {
            throw new WalletAdminException('ไม่พบวอลเลต');
        }

        return $wallet;
    }

    /**
     * หาผลเดิมของโทเคนนี้บนวอลเลตนี้ (เรียกหลัง lock แล้ว)
     *
     * protected เพื่อให้เทสต์จำลอง "อีกคำขอเพิ่ง commit หลังเราเช็ค" ได้ (ทางสำรอง unique index ใน run())
     */
    protected function findReplay(int $walletId, ?string $idempotencyKey): ?WalletTransaction
    {
        return $this->entryFor($walletId, $idempotencyKey);
    }

    /** รายการเงินที่บันทึกด้วยโทเคนนี้บนวอลเลตนี้ (wasRecentlyCreated = false) */
    private function entryFor(int $walletId, ?string $idempotencyKey): ?WalletTransaction
    {
        if (! $idempotencyKey) {
            return null;
        }
        $tx = WalletAdminAdjustment::where('idempotency_key', $idempotencyKey)
            ->where('wallet_id', $walletId)
            ->first()?->walletTransaction;
        if ($tx) {
            $tx->wasRecentlyCreated = false;
        }

        return $tx;
    }

    /** เงินจริง = role admin เท่านั้น (canAccessPanel ปล่อย editor เข้าด้วย — ด่านจริงอยู่ตรงนี้) */
    private function assertAdmin(User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new WalletAdminException('เฉพาะแอดมินเท่านั้นที่จัดการเงินในวอลเลตได้');
        }
    }

    /** แอดมินแตะวอลเลตของตัวเองไม่ได้ — กันเติมเครดิตให้ตัวเอง */
    private function assertMayTouch(Wallet $wallet, User $admin): void
    {
        if ((int) $wallet->user_id === (int) $admin->id) {
            throw new WalletAdminException('แอดมินจัดการเงินในวอลเลตของตัวเองไม่ได้ — ให้แอดมินคนอื่นทำแทน');
        }
    }

    private function assertTargetUsable(?User $user): void
    {
        if (! $user) {
            throw new WalletAdminException('ไม่พบบัญชีผู้ใช้');
        }
        if (method_exists($user, 'trashed') && $user->trashed()) {
            throw new WalletAdminException('บัญชีนี้ถูกลบแล้ว — กู้คืนบัญชีก่อนจึงจะจัดการเงินได้');
        }
    }

    private function assertExpectedBalance(Wallet $locked, string|int|float|null $expected): void
    {
        if ($expected === null || $expected === '') {
            return;
        }
        if (! is_numeric($expected)) {
            throw new WalletAdminException('ยอดอ้างอิงไม่ถูกต้อง กรุณาเปิดหน้าต่างใหม่แล้วลองอีกครั้ง');
        }
        $expected = number_format(round((float) $expected, 2), 2, '.', '');
        if (bccomp((string) $locked->balance, $expected, 2) !== 0) {
            throw new WalletAdminException(
                'ยอดเงินในวอลเลตเปลี่ยนไประหว่างที่คุณกำลังยืนยัน (ตอนนี้ ฿' . $this->fmt((string) $locked->balance)
                . ') — ยังไม่ได้ทำรายการ กรุณาเปิดหน้าต่างใหม่แล้วยืนยันอีกครั้ง'
            );
        }
    }

    private function normalizeAmount(string|int|float $amount): string
    {
        $raw = is_string($amount) ? trim(str_replace(',', '', $amount)) : $amount;
        if (! is_numeric($raw) || ! is_finite((float) $raw)) {
            throw new WalletAdminException('จำนวนเงินไม่ถูกต้อง');
        }
        $norm = number_format(round((float) $raw, 2), 2, '.', '');
        if (bccomp($norm, '0', 2) <= 0) {
            throw new WalletAdminException('จำนวนเงินต้องมากกว่า 0');
        }
        if (bccomp($norm, self::MAX_AMOUNT, 2) > 0) {
            throw new WalletAdminException('จำนวนเงินเกินเพดานต่อรายการ (฿' . $this->fmt(self::MAX_AMOUNT) . ')');
        }

        return $norm;
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 3) {
            throw new WalletAdminException('กรุณาระบุเหตุผล (อย่างน้อย 3 ตัวอักษร)');
        }

        return mb_substr($reason, 0, 500);
    }

    private function normalizeKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        if (strlen($key) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            throw new WalletAdminException('โทเคนฟอร์มไม่ถูกต้อง กรุณาเปิดหน้าต่างใหม่');
        }

        return $key;
    }

    /**
     * เขียนรายการเงิน 1 ขาบนวอลเลตที่ lock แล้ว: ธุรกรรม + ยอดวอลเลต + บันทึกตรวจสอบ
     *
     * @param  array{action: string, credit: bool, type: string, amount: string, reason: string, description: string, reference_type?: string|null, reference_id?: int|null, original_transaction_id?: int|null, idempotency_key?: string|null, meta?: array}  $entry
     */
    private function writeEntry(Wallet $locked, User $admin, array $entry): WalletTransaction
    {
        $amount = $entry['amount'];
        $before = number_format((float) $locked->balance, 2, '.', '');
        $after  = $entry['credit'] ? bcadd($before, $amount, 2) : bcsub($before, $amount, 2);

        if (bccomp($after, '0', 2) < 0) {
            throw new WalletAdminException(
                'ยอดเงินไม่พอ (คงเหลือ ฿' . $this->fmt($before) . ' · ต้องการหัก ฿' . $this->fmt($amount) . ') — ไม่ยอมให้ยอดติดลบ'
            );
        }

        $locked->balance = $after;
        $locked->save();

        $tx = WalletTransaction::create([
            'user_id'        => $locked->user_id,
            'wallet_id'      => $locked->id,
            'type'           => $entry['type'],
            'status'         => 'success',
            'amount'         => $entry['credit'] ? $amount : '-' . $amount,
            'balance_after'  => $after,
            'description'    => mb_substr($entry['description'], 0, 255),
            'reference_type' => $entry['reference_type'] ?? null,
            'reference_id'   => $entry['reference_id'] ?? null,
            'method'         => self::METHOD,
            'approved_by'    => $admin->id,
            'approved_at'    => now(),
            'meta'           => array_merge($entry['meta'] ?? [], [
                'reason'       => $entry['reason'],
                'admin_action' => $entry['action'],
                'admin_id'     => $admin->id,
            ]),
        ]);

        // IP/UA ของคำขอที่แอดมินกดยืนยัน (Livewire update request ของ Filament)
        $request = request();
        WalletAdminAdjustment::create([
            'wallet_id'               => $locked->id,
            'user_id'                 => $locked->user_id,
            'admin_id'                => $admin->id,
            'admin_name'              => mb_substr((string) $admin->name, 0, 255),
            'action'                  => $entry['action'],
            'amount'                  => $amount,
            'balance_before'          => $before,
            'balance_after'           => $after,
            'reason'                  => $entry['reason'],
            'wallet_transaction_id'   => $tx->id,
            'original_transaction_id' => $entry['original_transaction_id'] ?? null,
            'idempotency_key'         => $entry['idempotency_key'] ?? null,
            'ip_address'              => $request?->ip(),
            'user_agent'              => $request ? (mb_substr((string) $request->userAgent(), 0, 1000) ?: null) : null,
            'meta'                    => $entry['meta'] ?? null,
        ]);

        $tx->wasRecentlyCreated = true;

        return $tx;
    }

    private function abs(string $amount): string
    {
        return ltrim(number_format((float) $amount, 2, '.', ''), '-');
    }

    private function fmt(string $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
