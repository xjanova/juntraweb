<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateBill;
use App\Models\User;
use App\Services\Thaiprompt\JuntraServerClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 🌙 ผังแม่หมอ — ที่คำนวณและเก็บค่าแนะนำทั้งหมด (Thaiprompt) · เว็บนี้แค่ส่งบิลและแสดงผล
 *
 * เจ้าของสั่ง (2026-09-21): บิลที่เกิดบนเว็บและในแอพจันทราต้องส่งไปคำนวณปันผลที่ผังแม่หมอ
 *   ห้ามเพิ่มสูตรค่าแนะนำในเว็บนี้ — ของใหม่ = เส้นใหม่ที่ Thaiprompt + การแสดงผลที่นี่
 *
 * ลูกค้าทุกคนมีสายงานได้ ไม่ต้องผูก Thaiprompt: ตัวตนคือ users.id ของเว็บนี้ (user_ref)
 */
class MaeMorAffiliate
{
    /** ตรวจครั้งเดียวต่อโปรเซส ว่าคอลัมน์รหัสสมาชิก migrate แล้วหรือยัง */
    private static ?bool $hasMemberCodeColumn = null;

    public function __construct(private JuntraServerClient $server) {}

    /**
     * ให้ลูกค้าคนนี้อยู่ในผังแม่หมอ (idempotent) — ใช้รหัสเชิญที่ค้างไว้ถ้ายังไม่เคยเข้าผัง
     *
     * คำตอบชัดจาก Thaiprompt (สำเร็จ หรือปฏิเสธ) = ใช้รหัสเชิญไปแล้ว ล้างทิ้ง
     * ต่อไม่ได้ = เก็บรหัสไว้ รอบหน้าลองใหม่ (คำสั่ง affiliate:sync-bills เก็บตกให้)
     *
     * @return array{status: string, data: ?array} data = ข้อมูลสมาชิก (member_code, sponsor, referral)
     */
    public function ensureMember(User $user): array
    {
        $res = $this->server->affiliate('POST', '/accounts', array_filter([
            'user_ref' => $user->id,
            'name' => $this->displayName($user),
            'thaiprompt_user_id' => $user->thaiprompt_user_id ?: null,
            'referral_code' => $user->pending_referral_code ?: null,
        ], fn ($v) => $v !== null));

        if ($res['status'] === 'ok') {
            $this->remember($user, (array) ($res['data']['data'] ?? []));

            return ['status' => 'ok', 'data' => $res['data']['data'] ?? null];
        }

        if ($res['status'] === 'rejected') {
            // ข้อมูลที่ส่งไปใช้ไม่ได้ (เช่นชื่อยาวเกิน) — ส่งซ้ำก็ไม่ผ่าน ให้แอดมินเห็นใน log
            Log::warning('MaeMorAffiliate: Thaiprompt ปฏิเสธการเข้าผัง', [
                'user_id' => $user->id,
                'code' => $res['code'],
                'reason' => $res['reason_code'],
            ]);
            // คำตอบชัดแล้ว — ห้ามลองซ้ำทุกนาทีด้วยข้อมูลชุดเดิม (ลูกค้าเปิดหน้าสายงานเมื่อไรจะลองใหม่เอง)
            if ($user->pending_referral_code !== null) {
                $user->forceFill(['pending_referral_code' => null])->save();
            }
        }

        return ['status' => $res['status'], 'data' => null];
    }

    /**
     * ส่งบิลหนึ่งใบให้แม่หมอแจกค่าแนะนำ — อัปเดตสมุดส่งของ ($bill) ตามผล
     */
    public function sendBill(AffiliateBill $bill): string
    {
        $tx = $bill->walletTransaction;
        $user = $bill->user;

        if (! $tx || ! $user || $tx->status !== 'success') {
            // เคยยิงไปแล้ว (แม้คำตอบจะหาย/หมดเวลา) = แม่หมออาจบันทึกและแจกค่าแนะนำไปแล้ว → ต้องสั่งยกเลิก
            //   ข้ามเฉย ๆ = ค่าแนะนำของบิลที่คืนเงินแล้วค้างอยู่ที่ผู้แนะนำตลอดไป
            if ($bill->attempts > 0) {
                return $this->voidBill($bill);
            }

            // คืนเงินก่อนเคยส่ง = ไม่เคยเป็นรายได้ ไม่ต้องให้แม่หมอรู้
            $bill->update(['status' => AffiliateBill::STATUS_SKIPPED, 'next_attempt_at' => null]);

            return 'skipped';
        }

        $res = $this->server->affiliate('POST', '/bills', array_filter([
            'bill_id' => $tx->id,
            'user_ref' => $user->id,
            'name' => $this->displayName($user),
            'amount' => number_format((float) $bill->amount, 2, '.', ''),
            'product' => $bill->product,
            'paid_at' => $tx->created_at?->toIso8601String(),
            'thaiprompt_user_id' => $user->thaiprompt_user_id ?: null,
            'referral_code' => $user->pending_referral_code ?: null,
            // บิลก่อนเปิดระบบ — แม่หมอนับสิทธิ์ "เคยมีบิลที่ชำระแล้ว" แต่ไม่แจกค่าแนะนำ
            'history_only' => $bill->history_only ? true : null,
        ], fn ($v) => $v !== null), timeout: 15);

        if ($res['status'] === 'ok') {
            $data = (array) ($res['data']['data'] ?? []);
            $bill->update([
                'status' => AffiliateBill::STATUS_SENT,
                'attempts' => $bill->attempts + 1,
                'next_attempt_at' => null,
                'last_error' => null,
                'bill_reference' => $data['bill_reference'] ?? null,
                'commission_total' => collect($data['commissions'] ?? [])->sum('amount'),
                'sent_at' => now(),
            ]);
            if (! empty($data['member_code'])) {
                $this->remember($user, ['member_code' => $data['member_code']]);
            }

            return 'sent';
        }

        $attempts = $bill->attempts + 1;
        $definitive = $res['status'] === 'rejected';
        $bill->update([
            'status' => AffiliateBill::STATUS_FAILED,
            // attempts = จำนวนครั้งที่ยิงจริง (ใช้ตัดสินว่าต้องสั่งยกเลิกเมื่อคืนเงิน) ไม่ใช่โควตา
            'attempts' => $attempts,
            // ข้อมูลถูกปฏิเสธ = หยุดลองเอง รอแอดมินกดส่งซ้ำ · อีกฝั่งล่ม = ลองต่อเรื่อย ๆ (ห่างสุด 30 นาที)
            //   ไม่มีเพดาน: ล่มนานแค่ไหนบิลก็ต้องไม่ค้างถาวร
            'next_attempt_at' => $definitive ? null : now()->addMinutes(min(2 ** min($attempts, 5), 30)),
            'last_error' => Str::limit($this->describe($res), 250, ''),
        ]);

        return $res['status'];
    }

    /**
     * ลูกค้าได้เงินคืนแล้ว → ให้แม่หมอดึงค่าแนะนำคืน (ส่งซ้ำได้ ไม่ดึงคืนสองรอบ)
     */
    public function voidBill(AffiliateBill $bill): string
    {
        $res = $this->server->affiliate('POST', '/bills/' . $bill->wallet_transaction_id . '/void', [
            'reason' => Str::limit((string) ($bill->walletTransaction?->description ?? ''), 200, ''),
        ]);

        if ($res['status'] === 'ok') {
            $bill->update([
                'status' => AffiliateBill::STATUS_VOIDED,
                'voided_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
            ]);

            return 'voided';
        }

        // แม่หมอไม่เคยได้บิลนี้ = ไม่มีค่าแนะนำให้ดึงคืน · แม่หมอจดป้ายไว้แล้ว ถ้าบิลที่ค้างทางอยู่
        //   มาถึงทีหลังจะไม่แจกค่าแนะนำ → จบได้
        if ($res['reason_code'] === 'unknown_bill') {
            $bill->update([
                'status' => $bill->status === AffiliateBill::STATUS_SENT ? AffiliateBill::STATUS_VOIDED : AffiliateBill::STATUS_SKIPPED,
                'voided_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
            ]);

            return 'voided';
        }

        $bill->update(['last_error' => Str::limit('ดึงค่าแนะนำคืนไม่สำเร็จ: ' . $this->describe($res), 250, '')]);

        return $res['status'];
    }

    /* ============================================================ */

    /** จำรหัสสมาชิกของลูกค้า + ล้างรหัสเชิญที่ใช้ไปแล้ว */
    private function remember(User $user, array $data): void
    {
        $changes = [];
        // deploy ดึงโค้ดก่อน migrate เสร็จ — ช่วงนั้นข้ามการจำรหัส (หน้าสายงานต้องไม่ 500)
        self::$hasMemberCodeColumn ??= Schema::hasColumn('users', 'maemor_member_code');
        if (self::$hasMemberCodeColumn && ! empty($data['member_code']) && $user->maemor_member_code !== $data['member_code']) {
            $changes['maemor_member_code'] = substr((string) $data['member_code'], 0, 32);
        }
        // Thaiprompt ตอบแล้ว = รหัสเชิญถูกใช้/ถูกตัดสินแล้ว (เข้าสายแล้ว หรือมีสายเดิมอยู่) ห้ามส่งซ้ำ
        if ($user->pending_referral_code !== null) {
            $changes['pending_referral_code'] = null;
        }
        if ($changes !== []) {
            $user->forceFill($changes)->save();
        }
    }

    private function displayName(User $user): string
    {
        $name = trim((string) $user->name);

        return Str::limit($name !== '' ? $name : 'ลูกค้าจันทรา', 180, '');
    }

    private function describe(array $res): string
    {
        return trim(implode(' · ', array_filter([
            $res['status'],
            $res['code'] ? 'HTTP ' . $res['code'] : null,
            $res['reason_code'],
            $res['message'],
        ])));
    }
}
