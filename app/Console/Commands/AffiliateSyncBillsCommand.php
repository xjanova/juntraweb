<?php

namespace App\Console\Commands;

use App\Models\AffiliateBill;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Affiliate\MaeMorAffiliate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 🌙 ส่งบิลคำทำนายของเว็บ/แอพไปให้ผังแม่หมอแจกค่าแนะนำ — และดึงค่าแนะนำคืนเมื่อคืนเงินลูกค้า
 *
 * เจ้าของสั่ง (2026-09-21): เว็บไม่คำนวณค่าแนะนำเอง ทุกบิลต้องไปคำนวณที่ผังแม่หมอ
 *
 * ทุกนาที:
 *   1. เก็บตกรหัสเชิญที่ค้าง (สมัครแล้วแต่ Thaiprompt ล่มตอนนั้น) → เข้าผังใต้ผู้เชิญ
 *   2. บิลใหม่ที่เกิน --delay นาที → ลงสมุด (รอให้พ้นช่วงที่ไพ่อ่านไม่สำเร็จแล้วระบบคืนเงินเอง
 *      ไม่งั้นแจกค่าแนะนำแล้วต้องดึงคืนทันที — ผู้แนะนำอาจถอนไปแล้ว)
 *   3. บิลก่อนเปิดระบบ → ลงสมุดแบบนับสิทธิ์อย่างเดียว (ไม่มีค่าแนะนำ) ครั้งเดียว
 *   4. ส่งบิลที่รอส่ง/ส่งไม่ผ่าน (ถอยเวลาเพิ่มขึ้นเรื่อย ๆ)
 *   5. บิลที่ส่งแล้วแต่ลูกค้าได้เงินคืนภายหลัง → ให้แม่หมอดึงค่าแนะนำคืน (บิลประวัติ = ถอนสิทธิ์ที่นับไว้)
 */
class AffiliateSyncBillsCommand extends Command
{
    protected $signature = 'affiliate:sync-bills
        {--delay=15 : ส่งบิลที่เกิดมาแล้วอย่างน้อยกี่นาที}
        {--since= : บิลตั้งแต่วันที่นี้ได้ค่าแนะนำ (ค่าเริ่มต้น = วันที่เปิดระบบ) — บิลก่อนหน้าส่งแบบนับสิทธิ์อย่างเดียว ไม่มีค่าแนะนำ}
        {--limit=100 : จำนวนสูงสุดต่อรอบของแต่ละขั้น}';

    protected $description = 'Send paid reading bills to the Mae Mor (Thaiprompt) affiliate tree and claw back refunded ones';

    public function handle(MaeMorAffiliate $affiliate): int
    {
        // รอบอัตโนมัติ (ทุกนาที) กับปุ่ม "ส่งตอนนี้" ในหลังบ้านต้องไม่ทำงานซ้อนกัน
        $lock = Cache::lock('affiliate:sync-bills', 300);
        if (! $lock->get()) {
            $this->info('มีรอบส่งอื่นกำลังทำงานอยู่ — ข้าม');

            return self::SUCCESS;
        }

        try {
            return $this->syncOnce($affiliate);
        } finally {
            $lock->release();
        }
    }

    private function syncOnce(MaeMorAffiliate $affiliate): int
    {
        // deploy ดึงโค้ดก่อน migrate เสร็จ — รอบที่ตรงช่วงนั้นข้ามไปเฉย ๆ ไม่ต้องพังทุกนาที
        if (! Schema::hasTable('affiliate_bills')) {
            $this->warn('ยังไม่มีตาราง affiliate_bills (รอ migrate) — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $since = $this->since();
        if ($since === null) {
            $this->warn('affiliate_sync_since ยังไม่ถูกตั้ง (ยังไม่ได้ migrate?) — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        $enrolled = $this->retryPendingReferrals($affiliate, $limit);
        $queued = $this->discover($since, max(1, (int) $this->option('delay')), $limit);
        // deploy ดึงโค้ดก่อน migrate เสร็จ — คอลัมน์ยังไม่มี = ข้ามบิลประวัติรอบนี้ (รอบหน้าเก็บเอง)
        $history = Schema::hasColumn('affiliate_bills', 'history_only') ? $this->discoverHistory($since, $limit) : 0;
        [$sent, $failed, $skipped] = $this->sendDue($affiliate, $limit);
        $voided = $this->voidRefunded($affiliate, $limit);

        $this->info("enrolled {$enrolled} · queued {$queued} · history {$history} · sent {$sent} · failed {$failed} · skipped {$skipped} · voided {$voided}");

        return self::SUCCESS;
    }

    private function since(): ?Carbon
    {
        $raw = $this->option('since') ?: Setting::get('affiliate_sync_since');

        return $raw ? Carbon::parse($raw) : null;
    }

    /** อีกฝั่งล่ม/ยังไม่พร้อม — หยุดรอบนี้ ไม่ยิงซ้ำทีละรายการ */
    private static function isOutage(string $status): bool
    {
        return $status === 'unavailable' || $status === 'unsupported';
    }

    /** ลูกค้าที่มีรหัสเชิญค้าง แต่ยังไม่อยู่ในผัง (ตอนสมัคร Thaiprompt ต่อไม่ได้) */
    private function retryPendingReferrals(MaeMorAffiliate $affiliate, int $limit): int
    {
        $n = 0;
        User::query()
            ->whereNotNull('pending_referral_code')
            ->whereNull('maemor_member_code')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (User $user) use ($affiliate, &$n) {
                $status = $affiliate->ensureMember($user)['status'];
                if ($status === 'ok') {
                    $n++;
                }

                // Thaiprompt ล่ม/ยังไม่พร้อม — คนที่เหลือก็ไม่ผ่าน ไว้รอบหน้า
                return ! self::isOutage($status);
            });

        return $n;
    }

    /**
     * บิลคำทำนายที่จ่ายแล้วและพ้นช่วงคืนเงินอัตโนมัติ → ลงสมุดรอส่ง
     *
     * บิล = การตัดเงินที่ reference_type = 'reading' (ทุกการซื้อคำทำนายบนเว็บและแอพ)
     *   ค่าแชท (chat_message) ไม่นับ — เจ้าของสั่ง
     */
    private function discover(Carbon $since, int $delayMinutes, int $limit): int
    {
        $rows = WalletTransaction::query()
            ->where('type', 'debit')
            ->where('reference_type', 'reading')
            ->where('status', 'success')
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', now()->subMinutes($delayMinutes))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('affiliate_bills')
                ->whereColumn('affiliate_bills.wallet_transaction_id', 'wallet_transactions.id'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $tx) {
            AffiliateBill::firstOrCreate(
                ['wallet_transaction_id' => $tx->id],
                [
                    'user_id' => $tx->user_id,
                    'amount' => abs((float) $tx->amount),
                    'product' => Str::limit((string) ($tx->description ?: 'reading'), 60, ''),
                    'status' => AffiliateBill::STATUS_PENDING,
                ],
            );
        }

        return $rows->count();
    }

    /**
     * 🌙 (2026-09-23) บิลที่จ่ายก่อนเปิดระบบค่าแนะนำ → ส่งแบบนับสิทธิ์อย่างเดียว ไม่แจกค่าแนะนำ
     *
     * เจ้าของสั่ง: "ผู้เชิญต้องเคยเปิดบิลที่ชำระบิลแล้ว จึงจะได้รับค่าคอมทุกช่องทาง"
     *   ลูกค้าที่ซื้อบนเว็บก่อนเปิดระบบก็ "เคย" แล้ว แต่ผังแม่หมอไม่เคยได้บิลเหล่านั้น — ไม่ส่งไป
     *   ค่าแนะนำจากทีมของเขาจะเข้ากระเป๋ากลางทั้งที่เขามีสิทธิ์ · บิลที่คืนเงินไปแล้วไม่นับ ไม่ส่ง
     *   (ไม่จ่ายค่าแนะนำย้อนหลัง — เจ้าของสั่ง 2026-09-21 · แม่หมอตัดสินจาก history_only)
     */
    private function discoverHistory(Carbon $since, int $limit): int
    {
        $rows = WalletTransaction::query()
            ->where('type', 'debit')
            ->where('reference_type', 'reading')
            ->where('status', 'success')
            ->where('created_at', '<', $since)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('affiliate_bills')
                ->whereColumn('affiliate_bills.wallet_transaction_id', 'wallet_transactions.id'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $tx) {
            AffiliateBill::firstOrCreate(
                ['wallet_transaction_id' => $tx->id],
                [
                    'user_id' => $tx->user_id,
                    'amount' => abs((float) $tx->amount),
                    'product' => Str::limit((string) ($tx->description ?: 'reading'), 60, ''),
                    'history_only' => true,
                    'status' => AffiliateBill::STATUS_PENDING,
                ],
            );
        }

        return $rows->count();
    }

    /** @return array{0: int, 1: int, 2: int} [sent, failed, skipped] */
    private function sendDue(MaeMorAffiliate $affiliate, int $limit): array
    {
        $bills = AffiliateBill::with(['walletTransaction', 'user'])
            // รอส่ง · หรือส่งไม่ผ่านเพราะอีกฝั่งล่มและถึงเวลาลองใหม่ (failed ที่ next_attempt_at ว่าง = ถูกปฏิเสธ รอแอดมิน)
            ->where(fn ($q) => $q->where('status', AffiliateBill::STATUS_PENDING)
                ->orWhere(fn ($q2) => $q2->where('status', AffiliateBill::STATUS_FAILED)
                    ->whereNotNull('next_attempt_at')
                    ->where('next_attempt_at', '<=', now())))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $tally = [AffiliateBill::STATUS_SENT => 0, AffiliateBill::STATUS_FAILED => 0, AffiliateBill::STATUS_SKIPPED => 0];
        foreach ($bills as $bill) {
            $status = $affiliate->sendBill($bill);
            $tally[$bill->status] = ($tally[$bill->status] ?? 0) + 1;
            if (self::isOutage($status)) {
                break; // บิลที่เหลือยังไม่ถูกลอง — ไม่เสียโควตาการลองเพราะอีกฝั่งล่ม
            }
        }

        return [$tally[AffiliateBill::STATUS_SENT], $tally[AffiliateBill::STATUS_FAILED], $tally[AffiliateBill::STATUS_SKIPPED]];
    }

    /**
     * ลูกค้าได้เงินคืนภายหลัง → ดึงค่าแนะนำคืน
     *
     * รวมบิลที่ "เคยยิงแล้วแต่ไม่ได้คำตอบชัด" (หมดเวลา/ถูกปฏิเสธ) ด้วย — แม่หมออาจบันทึกไปแล้ว
     */
    private function voidRefunded(MaeMorAffiliate $affiliate, int $limit): int
    {
        $bills = AffiliateBill::with('walletTransaction')
            ->where(fn ($q) => $q->where('status', AffiliateBill::STATUS_SENT)
                ->orWhere(fn ($q2) => $q2->whereIn('status', [AffiliateBill::STATUS_PENDING, AffiliateBill::STATUS_FAILED])
                    ->where('attempts', '>', 0)))
            ->whereHas('walletTransaction', fn ($q) => $q->where('status', 'refunded'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $n = 0;
        foreach ($bills as $bill) {
            $status = $affiliate->voidBill($bill);
            if ($status === 'voided') {
                $n++;
            } elseif (self::isOutage($status)) {
                break;
            }
        }

        return $n;
    }
}
