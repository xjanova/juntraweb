<?php

namespace App\Services\SmsPayment;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

/**
 * ยอดเศษสตางค์ไม่ซ้ำ "ข้ามสองเว็บ" สำหรับการเติมเงินแบบ PromptPay
 *
 * ทำไมต้องมี: มือถือเครื่องเดียว (แอพ SMS Checker) รับ SMS ของบัญชีธนาคารเดียว
 * ที่ทั้งเว็บจันทราและบอทแม่หมอ (Thaiprompt) ใช้ร่วมกัน ถ้าต่างคนต่างสุ่มเศษสตางค์
 * เอง ยอด ฿100.37 อาจรออยู่ทั้งสองฝั่งพร้อมกัน → เงินก้อนเดียวถูกยืนยันสองเว็บ
 * จึงให้ Thaiprompt เป็น "ตัวจองกลาง" ตัวเดียว (สัญญา section C)
 *
 * ลำดับงาน (ยอดที่ลูกค้าเห็น/สแกนต้องเป็นยอดสุดท้ายเสมอ):
 *   1. เลือกยอดไม่ซ้ำ "ในเครื่อง" ก่อน แล้วสร้างรายการ pending ด้วยยอดนั้นทันที
 *      (commit เลย — คำขอที่เข้ามาพร้อมกันจะเห็นและไม่เลือกยอดซ้ำ)
 *   2. ขอจองยอดจาก Thaiprompt ด้วย ref = reference_code, external_id = id รายการ
 *   3. ได้ยอดจอง → เปลี่ยนยอดของรายการเป็นยอดจอง (ใต้ lock + เช็คว่ายัง pending)
 *      ไม่ได้ (ต่อไม่ติด/ไม่ได้ตั้งค่า/พูลเต็ม) → ใช้ยอดในเครื่องจากข้อ 1 ต่อ
 *   ผู้เรียก (controller) ต้องอ่านยอดจาก $tx->amount ที่คืนไป ห้ามคำนวณเองก่อนหน้า
 *
 * การคืนยอด: ทุกครั้งที่รายการออกจากสถานะ pending (SMS/สลิป/แอดมินอนุมัติ, ปฏิเสธ,
 * ลูกค้ายกเลิก, หมดอายุ) TopupReservationObserver จะเรียก releaseFor() หลัง commit
 * ตัวคืนยิงแบบ defer (หลังส่ง response) และไม่โยนเสมอ — คืนไม่สำเร็จก็หมดอายุเอง
 * ห้ามให้การคืนยอดไปขวางหรือทำให้เส้นทางเงินล้ม
 *
 * meta ที่เขียนลงรายการ:
 *   base_amount      ยอดฐานที่ลูกค้าเลือก
 *   amount_reserved  true = ยอดนี้จองกับ Thaiprompt แล้ว · false = ยอดในเครื่อง (fallback)
 *   upa_id / upa_ref / upa_expires_at  ข้อมูลการจอง (เฉพาะเมื่อ amount_reserved = true)
 */
class AmountReservation
{
    /** Thaiprompt เพิ่งตอบช้าจน timeout — ข้ามการจองชั่วคราว ไม่ให้ลูกค้ารอหน้า QR ครั้งละ 10 วิ */
    public const DOWN_KEY = 'smschecker:upa_reserve_down';

    /** ยอดที่จองได้ชนกับรายการ fallback ในเครื่อง → ลองจองใหม่ได้อีกกี่ครั้ง */
    private const MAX_ATTEMPTS = 3;

    /** การจองที่ใช้เวลานานกว่านี้แล้วยังล้ม = ถือว่าปลายทางล่ม (ไม่ใช่แค่ 409 พูลเต็ม) */
    private const SLOW_FAILURE_SECONDS = 3.0;

    public function __construct(
        private WalletService $wallet,
        private SmsCheckerService $sms,
        private JuntraServerClient $thaiprompt,
    ) {}

    /**
     * สร้างรายการเติมเงิน PromptPay ที่รอชำระ พร้อมยอดที่ต้องโอนแบบไม่ซ้ำข้ามเว็บ
     *
     * @throws \RuntimeException|\App\Exceptions\DuplicateSlipException  จาก recordPendingTopup (เพดาน pending / สลิปซ้ำ)
     */
    public function createPendingTopup(User $user, float $base, ?string $slipPath = null, ?string $slipHash = null): WalletTransaction
    {
        if (! config('smschecker.enabled')) {
            // ไม่ใช้ตัวจับ SMS = ไม่ต้องมีเศษสตางค์ (พฤติกรรมเดิม)
            return $this->wallet->recordPendingTopup($user, $base, $slipPath, 'promptpay', $slipHash);
        }

        $local = $this->sms->uniqueAmountFor($base);
        $tx    = $this->wallet->recordPendingTopup($user, $local, $slipPath, 'promptpay', $slipHash);

        return $this->finalize($tx, $base, $this->reserveFor($tx, $base));
    }

    /**
     * คืนยอดที่จองไว้ของรายการนี้ (best-effort, หลังส่ง response)
     *
     * @param  'used'|'cancelled'  $status
     */
    public function releaseFor(array $meta, ?string $referenceCode, string $status, ?int $txId = null): void
    {
        if (empty($meta['amount_reserved'])) {
            return; // ยอดในเครื่อง ไม่มีอะไรต้องคืน
        }
        $id  = isset($meta['upa_id']) ? (int) $meta['upa_id'] : null;
        $ref = (string) ($meta['upa_ref'] ?? $referenceCode ?? '');

        $this->releaseLater($id ?: null, $ref !== '' ? $ref : null, $status, $txId);
    }

    /* ============================ internals ============================ */

    /**
     * @return array{id:int,unique_amount:string,base_amount:string,expires_at:?string,ref:string}|null
     */
    private function reserveFor(WalletTransaction $tx, float $base): ?array
    {
        if (! $this->thaiprompt->isConfigured() || Cache::has(self::DOWN_KEY)) {
            return null;
        }

        $ttl      = $this->ttlMinutes();
        $rejected = [];
        $chosen   = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            // ref เดิมซ้ำ = Thaiprompt คืนการจองเดิม (idempotent) → รอบถัดไปต้องใช้ ref ใหม่
            $ref     = $attempt === 1 ? (string) $tx->reference_code : $tx->reference_code . '-R' . $attempt;
            $started = microtime(true);
            $r       = $this->thaiprompt->reserveAmount($base, $ref, (int) $tx->id, $ttl);

            if ($r === null) {
                if (microtime(true) - $started >= self::SLOW_FAILURE_SECONDS) {
                    Cache::put(self::DOWN_KEY, 1, now()->addSeconds(60));
                }
                break;
            }
            $r['ref'] = $ref;

            // ห้ามเชื่อตัวเลขจากต้นทางโดยไม่ตรวจ — ยอดต้องเป็น "บาทเดิม + 0.01..0.99"
            if (! $this->saneAmount($r['unique_amount'], $base)) {
                Log::warning('AmountReservation: upstream returned an out-of-range amount', [
                    'tx_id' => $tx->id, 'base' => $base, 'got' => $r['unique_amount'],
                ]);
                $rejected[] = $r;
                break;
            }

            // ยอดจองชนกับรายการ fallback ในเครื่องที่ยังรออยู่ (สร้างตอน Thaiprompt ล่ม)
            // → ถือไว้ก่อน (ตัวจองจะไม่ให้เลขเดิมซ้ำ) แล้วขอใหม่
            if ($this->takenLocally($r['unique_amount'], (int) $tx->id)) {
                $rejected[] = $r;
                continue;
            }

            $chosen = $r;
            break;
        }

        foreach ($rejected as $r) {
            $this->releaseLater($r['id'], $r['ref'], 'cancelled', (int) $tx->id);
        }

        return $chosen;
    }

    /**
     * เขียนยอดสุดท้าย + meta ลงรายการ ใต้ lock และเฉพาะเมื่อยัง pending
     * (ถ้ารายการถูกยืนยัน/ยกเลิกไปแล้วระหว่างรอ Thaiprompt ห้ามแก้ยอดทับ)
     */
    private function finalize(WalletTransaction $tx, float $base, ?array $r): WalletTransaction
    {
        $extra = ['base_amount' => $base, 'amount_reserved' => $r !== null];
        if ($r !== null) {
            $extra += [
                'upa_id'         => (int) $r['id'],
                'upa_ref'        => $r['ref'],
                'upa_expires_at' => $r['expires_at'],
            ];
        }

        $applied = DB::transaction(function () use ($tx, $r, $extra) {
            $locked = WalletTransaction::whereKey($tx->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending') {
                return false;
            }
            $locked->update(array_filter([
                'amount' => $r !== null ? $r['unique_amount'] : null,
                'meta'   => array_merge((array) $locked->meta, $extra),
            ], fn ($v) => $v !== null));

            return true;
        });

        if (! $applied && $r !== null) {
            Log::warning('AmountReservation: top-up left pending before it could be re-priced', ['tx_id' => $tx->id]);
            $this->releaseLater((int) $r['id'], $r['ref'], 'cancelled', (int) $tx->id);
        }

        return $tx->fresh();
    }

    /** ยอดนี้มีรายการเติมเงินอื่นที่ยังรอชำระ (ไม่หมดอายุ) ใช้อยู่แล้วหรือไม่ */
    private function takenLocally(string $amount, int $exceptId): bool
    {
        return WalletTransaction::where('type', 'topup')
            ->where('status', 'pending')
            ->where('id', '!=', $exceptId)
            ->where('amount', number_format((float) $amount, 2, '.', ''))
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    private function saneAmount(string $amount, float $base): bool
    {
        $baht  = (int) floor($base);
        $cents = (int) round(((float) $amount) * 100) - $baht * 100;

        return $cents >= 1 && $cents <= 99;
    }

    /** อายุการจอง = อายุรายการ pending (สัญญาจำกัด 5..2880 นาที) */
    private function ttlMinutes(): int
    {
        $hours = (int) config('pricing.topup_pending_ttl_hours', 48);

        return $hours > 0 ? max(5, min(2880, $hours * 60)) : 2880;
    }

    private function releaseLater(?int $id, ?string $ref, string $status, ?int $txId): void
    {
        if (! $id && ! $ref) {
            return;
        }
        $client = $this->thaiprompt;

        defer(function () use ($client, $id, $ref, $status, $txId) {
            try {
                if (! $client->releaseAmount($id, $ref, $status)) {
                    Log::info('AmountReservation: release not confirmed — reservation will expire on its own', [
                        'tx_id' => $txId, 'upa_id' => $id, 'status' => $status,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('AmountReservation: release threw', ['tx_id' => $txId, 'err' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }, always: true);
    }
}
