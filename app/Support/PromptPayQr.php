<?php

namespace App\Support;

/**
 * Builds an EMVCo-compliant PromptPay QR payload (the string a Thai banking
 * app scans). Pure PHP, no dependency — rendering the payload into an actual
 * QR image is done separately (SVG via endroid/qr-code when available, else
 * the raw payload is handed to the client to render).
 *
 * Supports the two common PromptPay target types:
 *   - mobile number  (10 digits, e.g. 0812345678)  → AID 01 / tag 29-01
 *   - national id / tax id (13 digits)             → AID 02 / tag 29-02
 *
 * Reference: EMV QRCPS + Thai PromptPay spec (BOT).
 */
class PromptPayQr
{
    /** Build the full EMVCo payload string for $target, optionally with $amount. */
    public static function payload(string $target, ?float $amount = null): string
    {
        $target = preg_replace('/\D/', '', $target) ?? '';
        if ($target === '') {
            return '';
        }

        // Merchant Account Information (tag 29) — ชนิดพร็อกซีแยกด้วย "ความยาว"
        // ตามสเปกของ ธปท. และต้องตรงกับฝั่งแอพ (lib/core/payments/promptpay_qr.dart)
        // เป๊ะ ๆ เพราะ input เดียวกันต้องได้ payload เดียวกันทั้งสองฝั่ง
        //
        // 🔴 ของเดิมใช้ `strlen >= 13` แล้ว `substr(...,0,13)` → เลข e-Wallet
        // 15 หลักถูก **ตัดเหลือ 13 หลัก** แล้วติดแท็ก 02 (บัตรประชาชน) ผิดชนิด
        // ลูกค้าที่สแกนจะโอนเข้าเลขที่ไม่มีอยู่จริง
        $aidGuid = static::field('00', 'A000000677010111');
        $len     = strlen($target);

        if ($len === 15) {
            // e-Wallet ID — 15 หลัก แท็ก 03
            $account = static::field('03', $target);
        } elseif ($len === 13) {
            // National ID / Tax ID — 13 หลัก แท็ก 02
            $account = static::field('02', $target);
        } elseif ($len === 10 && str_starts_with($target, '0')) {
            $account = static::field('01', '0066' . substr($target, 1));
        } elseif ($len === 11 && str_starts_with($target, '66')) {
            $account = static::field('01', '00' . $target);
        } elseif ($len === 9) {
            $account = static::field('01', '0066' . $target);
        } else {
            // ความยาวไม่เข้าชนิดใดเลย — คืนสตริงว่างเหมือนกรณีไม่มีเลขบัญชี
            // ผู้เรียกทุกที่เช็ค falsy อยู่แล้ว ดีกว่าปั้น QR ที่พาโอนผิดเลข
            return '';
        }

        $merchant = static::field('29', $aidGuid . $account);

        // POI: 12 = dynamic (มียอด) · 11 = static (ไม่มียอด)
        // ต้องผูกกับ "มียอดจริงไหม" ให้ตรงกับแท็ก 54 ด้านล่าง ไม่งั้นเรียกด้วย
        // amount = 0.0 จะได้ QR ประเภท dynamic ที่ไม่มียอด ซึ่งแอปธนาคารบางตัวปฏิเสธ
        $hasAmount = $amount !== null && $amount > 0;

        $payload  = static::field('00', '01');                               // payload format
        $payload .= static::field('01', $hasAmount ? '12' : '11');
        $payload .= $merchant;
        $payload .= static::field('53', '764');                              // currency THB
        if ($hasAmount) {
            $payload .= static::field('54', number_format($amount, 2, '.', ''));
        }
        $payload .= static::field('58', 'TH');                               // country

        // CRC (tag 63) is computed over the whole payload INCLUDING '6304'.
        $payload .= '6304';
        return $payload . static::crc16($payload);
    }

    /**
     * Render the QR as an inline SVG data URI (no gd needed). Returns null if
     * the target is empty or the QR library isn't available, so callers can
     * gracefully fall back to showing the PromptPay number as text.
     */
    public static function svgDataUri(string $target, ?float $amount = null): ?string
    {
        $payload = static::payload($target, $amount);
        if ($payload === '') {
            return null;
        }
        if (!class_exists(\Endroid\QrCode\Writer\SvgWriter::class)) {
            return null;
        }
        try {
            $result = (new \Endroid\QrCode\Writer\SvgWriter())
                ->write(new \Endroid\QrCode\QrCode($payload));
            return $result->getDataUri();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function field(string $id, string $value): string
    {
        return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    /** CRC-16/CCITT-FALSE, uppercase hex, used by the EMVCo tag 63. */
    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
