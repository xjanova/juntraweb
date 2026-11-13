<?php

namespace App\Support;

use App\Models\User;
use App\Models\WalletTransaction;

/**
 * ช่องทางเติมเงินที่ลูกค้าเลือก (พร้อมเพย์ / โอนเข้าบัญชีกสิกรไทย) — กติกาเดียวของหน้าเว็บ /wallet/topup และแอพ
 *
 * 'kbank' = โอนเข้าเลขบัญชีกสิกรไทย ซึ่งเป็น "บัญชีเดียวกับ QR พร้อมเพย์" ตัวจับ SMS เห็นเงินเข้า
 * เหมือนกันทุกประการ → ภายในเป็น method 'promptpay' (SmsCheckerService จับคู่เฉพาะ 'promptpay')
 * ช่องทางที่ลูกค้าเลือกกับบัญชีที่โชว์ให้โอนจดไว้ใน meta.channel / meta.transfer ของรายการ
 */
final class TopupChannel
{
    public const CHANNELS = ['promptpay', 'kbank'];

    public const KBANK_UNAVAILABLE = 'ช่องทางโอนเข้าบัญชีกสิกรไทยยังไม่พร้อมชั่วคราว — สแกน QR พร้อมเพย์แทนได้เลยค่ะ';

    /** @return array{bundles: list<int>, min: int, max: int} */
    public static function limits(): array
    {
        return [
            'bundles' => array_values(array_map('intval', (array) config('pricing.topup_bundles', [50, 100, 200, 500]))),
            'min'     => (int) config('pricing.min_topup', 20),
            'max'     => (int) config('pricing.max_topup', 50000),
        ];
    }

    /**
     * บัญชีกสิกรที่ให้โอนได้ตอนนี้ — null = ไม่โชว์ช่องทางกสิกรเลย (ห้ามเดาเลขบัญชีให้ลูกค้าโอน)
     *
     * @return array<string,string>|null
     */
    public static function transfer(?User $user): ?array
    {
        return PayoutAccount::resolve($user)['transfer'] ?? null;
    }

    /**
     * ตรวจช่องทางที่เลือกก่อนสร้างรายการ
     *
     * @return array{channel: string, transfer: ?array}|null  null = เลือกกสิกรแต่บัญชียังไม่พร้อม
     */
    public static function pick(?User $user, string $channel): ?array
    {
        $transfer = self::transfer($user);
        if ($channel === 'kbank' && ! $transfer) {
            return null;
        }

        return ['channel' => $channel, 'transfer' => $transfer];
    }

    /**
     * meta ที่จดกับรายการ — หน้าสถานะโชว์บัญชีของรายการนี้ ไม่ใช่บัญชีใหม่ที่แอดมินอาจสลับไปทีหลัง
     *
     * @param  array{channel: string, transfer: ?array}  $picked
     */
    public static function meta(array $picked): array
    {
        return ['channel' => $picked['channel'], 'transfer' => $picked['transfer']];
    }

    /**
     * บัญชีกสิกร + ช่องทางของรายการที่ยังรอจ่าย — ใช้ใบที่จดไว้กับรายการก่อน
     * รายการเก่าก่อน 2026-11-11 ไม่ได้จด = ใช้บัญชีปัจจุบัน (เหมือน QR)
     *
     * @param  array|null  $payout  ผลของ PayoutAccount::resolve()
     * @return array{channel: string, transfer: ?array}
     */
    public static function ofTransaction(WalletTransaction $tx, ?array $payout): array
    {
        $meta     = (array) $tx->meta;
        $transfer = null;
        if ($tx->awaitsCustomerPayment() && ! empty($payout['promptpay_id'])
            && in_array($tx->method, ['promptpay', 'promptpay_slip'], true)) {
            $transfer = array_key_exists('transfer', $meta)
                ? (is_array($meta['transfer']) ? $meta['transfer'] : null)
                : ($payout['transfer'] ?? null);
        }

        return [
            'channel'  => ($meta['channel'] ?? null) === 'kbank' && $transfer ? 'kbank' : 'promptpay',
            'transfer' => $transfer,
        ];
    }

    /**
     * การ์ดบัญชีกสิกรสำหรับแอพ — number คือเลขที่ให้คัดลอก (ตัวเลขล้วน เหมือนปุ่มคัดลอกของเว็บ)
     *
     * @return array{bank: string, name: string, number: string, display_number: string, bank_code: string}|null
     */
    public static function card(?array $transfer, string $fallbackName = ''): ?array
    {
        if (! $transfer || empty($transfer['account_number'])) {
            return null;
        }

        return [
            'bank'           => (string) (($transfer['bank_name'] ?? '') ?: 'ธนาคารกสิกรไทย'),
            'name'           => (string) (($transfer['account_name'] ?? '') ?: $fallbackName),
            'number'         => (string) $transfer['account_number'],
            'display_number' => (string) (($transfer['display_number'] ?? '') ?: $transfer['account_number']),
            'bank_code'      => (string) ($transfer['bank_code'] ?? ''),
        ];
    }
}
