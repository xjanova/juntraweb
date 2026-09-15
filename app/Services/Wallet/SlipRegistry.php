<?php

namespace App\Services\Wallet;

use App\Models\SmsPaymentNotification;
use App\Models\WalletTransaction;
use App\Services\Thaiprompt\JuntraServerClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "สลิปใบนี้ถูกใช้ไปแล้วหรือยัง" — ทั้งในเว็บจันทราเอง และในระบบแม่หมอ (Thaiprompt)
 *
 * เจ้าของกำหนด (2026-09-15): เงินโอนก้อนเดียวต้องใช้ได้ครั้งเดียว ไม่ว่าจะส่งสลิปให้บอท
 * แม่หมอใน LINE/Facebook หรือแนบในเว็บ — บัญชีรับเงินเป็นบัญชีเดียวกัน สลิปจึงใช้ข้าม
 * สองระบบได้ถ้าไม่มีทะเบียนกลาง
 *
 * ทะเบียนกลาง = ตาราง slip_verifications ของ Thaiprompt (ตัวเดียวกับที่บอทใช้กันสลิปซ้ำ)
 * เว็บ "จอง" เลขอ้างอิงลงทะเบียนนั้นก่อนเครดิตทุกครั้ง (claim) — ใครจองได้ก่อนคนนั้น
 * ได้ใช้ บอทจะเห็นว่าสลิปถูกใช้แล้วทันที และเว็บก็เห็นสลิปที่บอทใช้ไปแล้วเช่นกัน
 *
 * กฎเรื่องเงิน: ปฏิเสธได้เฉพาะเมื่อมี "หลักฐานเชิงบวก" (เจอเลขอ้างอิงในทะเบียน) —
 * ถามไม่ได้ ≠ สลิปซ้ำ ต้องส่งให้แอดมินตัดสิน
 */
class SlipRegistry
{
    public const CLAIMED = 'claimed';

    public const ALREADY_USED = 'already_used';

    /** Thaiprompt ต่อไม่ติด — ห้ามเครดิตเอง ส่งแอดมิน */
    public const UNAVAILABLE = 'unavailable';

    /** ยังไม่ได้ตั้งค่า client / Thaiprompt ยังไม่มี endpoint (ช่วง deploy) — ใช้กติกาเดิมของเว็บ */
    public const UNSUPPORTED = 'unsupported';

    public function __construct(private JuntraServerClient $server) {}

    /**
     * เลขอ้างอิงนี้เคยยืนยันรายการอื่นของเว็บไปแล้วไหม
     *
     * นับเฉพาะรายการที่เงินเข้าแล้ว/กำลังจะเข้า (success, pending ที่ติดเลขแล้ว, refunded
     * = เคยเข้าแล้วถูกเรียกคืน) — ใบที่แอดมินปฏิเสธหรือลูกค้ายกเลิกไม่ได้ใช้เงินก้อนนั้นจริง
     */
    public function usedLocally(string $transRef, ?int $exceptTxId = null): ?WalletTransaction
    {
        $transRef = trim($transRef);
        if ($transRef === '') {
            return null;
        }

        return WalletTransaction::where('bank_reference', $transRef)
            ->whereIn('status', ['success', 'pending', 'refunded'])
            ->when($exceptTxId, fn ($q) => $q->where('id', '!=', $exceptTxId))
            ->first();
    }

    /**
     * สลิปนี้คือเงินก้อนที่ SMS ธนาคารยืนยันรายการอื่นของเว็บไปแล้วหรือเปล่า
     *
     * ตรงกับด่าน 4.5 ของบอท (slipMatchesUsedSmsPayment): รายการที่เครดิตด้วย SMS ไม่มี
     * เลขอ้างอิงสลิปติดอยู่ ถ้าไม่เช็คตรงนี้ ลูกค้าคนหนึ่งจ่ายผ่าน QR (SMS เครดิตให้แล้ว)
     * แล้วเอาสลิปใบเดียวกันมาแนบกับอีกรายการได้อีกรอบ
     */
    public function usedBySms(?float $amount, ?string $transTimestamp, ?int $exceptTxId = null): bool
    {
        if ($amount === null || $amount <= 0 || empty($transTimestamp)) {
            return false;
        }
        try {
            // สลิปเป็น UTC (Z) — แปลงเป็นโซนเดียวกับ sms_timestamp ที่เก็บในโซนของแอพ
            $at = Carbon::parse($transTimestamp)->setTimezone((string) config('app.timezone', 'UTC'));

            return SmsPaymentNotification::whereIn('status', ['confirmed', 'matched'])
                ->whereNotNull('matched_transaction_id')
                ->when($exceptTxId, fn ($q) => $q->where('matched_transaction_id', '!=', $exceptTxId))
                ->whereBetween('amount', [$amount - 0.001, $amount + 0.001])
                ->whereBetween('sms_timestamp', [
                    $at->copy()->subMinutes(2)->toDateTimeString(),
                    $at->copy()->addMinutes(2)->toDateTimeString(),
                ])
                ->exists();
        } catch (Throwable $e) {
            Log::warning('SlipRegistry::usedBySms failed (non-blocking)', ['err' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * จองสลิปในทะเบียนกลางของแม่หมอก่อนเครดิต
     *
     * @param  array  $slip  ผลตรวจสลิป (trans_ref, amount, sender_name, receiver_account, sending_bank, receiving_bank, trans_timestamp)
     * @return array{status:string,used_by:?array}
     */
    public function claim(WalletTransaction $tx, array $slip): array
    {
        $ref = trim((string) ($slip['trans_ref'] ?? ''));
        if ($ref === '') {
            return ['status' => self::UNSUPPORTED, 'used_by' => null];
        }

        $res = $this->server->claimSlip([
            'trans_ref'        => $ref,
            'amount'           => (float) ($slip['amount'] ?? $tx->amount),
            'user_ref'         => (string) $tx->user_id,
            'topup_ref'        => (string) ($tx->reference_code ?: ('TX' . $tx->id)),
            'sender_name'      => $slip['sender_name'] ?? null,
            'receiver_account' => $slip['receiver_account'] ?? null,
            'sending_bank'     => $slip['sending_bank'] ?? null,
            'receiving_bank'   => $slip['receiving_bank'] ?? null,
            'trans_timestamp'  => $slip['trans_timestamp'] ?? null,
        ]);

        return [
            'status'  => match ($res['status']) {
                'claimed'      => self::CLAIMED,
                'already_used' => self::ALREADY_USED,
                'unsupported'  => self::UNSUPPORTED,
                default        => self::UNAVAILABLE,
            },
            'used_by' => $res['status'] === 'already_used' ? ($res['data'] ?? []) : null,
        ];
    }

    /** "เคยใช้ที่ไหน" เป็นภาษาคน สำหรับข้อความถึงลูกค้าและการ์ดแจ้งเตือน */
    public static function whereUsed(?array $usedBy, ?string $source = null): string
    {
        $platform = strtolower((string) ($usedBy['platform'] ?? ''));

        return match (true) {
            $platform === 'juntraweb'                    => 'เว็บจันทรา',
            in_array($platform, ['line', 'facebook', 'telegram'], true) => 'บอทแม่หมอ (' . ucfirst($platform) . ')',
            $source === 'sms_payment'                    => 'บิลแม่หมอที่จ่ายผ่าน SMS แล้ว',
            $source === 'web_sms'                        => 'รายการเติมเงินอื่นที่ SMS ยืนยันแล้ว',
            $source === 'web'                            => 'รายการเติมเงินอื่นของเว็บ',
            default                                      => 'ระบบแม่หมอ',
        };
    }
}
