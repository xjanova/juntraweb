<?php

namespace App\Support;

use App\Models\Setting;

/**
 * สวิตช์ "อนุมัติเติมเงินอัตโนมัติ" ที่แอดมินเปิด/ปิดได้เองในหน้า ตั้งค่าวอลเลต
 *
 * เจ้าของ (2026-11-09): "ถ้ายอดตรง sms checker ผ่าน หรือ slip ok ผ่าน ต้องอนุมัติให้เลย
 * ไม่งั้นลูกค้าจะงง" — ค่าเริ่มต้นจึงเปิดทั้งสองทาง ปิดไว้ใช้ตอนสงสัยว่ามีคนโกง
 *
 *   - sms  : SMS ธนาคารยอดตรงทุกสตางค์กับบิลที่รออยู่ → เครดิตทันที
 *            (ปิด = จับคู่ให้แอดมินเห็นในบิล แต่ไม่เติมเงินเอง)
 *   - slip : SlipOK ตรวจผ่านครบทุกด่าน → เครดิตทันที
 *            (ปิด = ตรวจสลิปและบันทึกผลไว้ แต่รอแอดมินกดยืนยัน)
 *
 * ทุกการอนุมัติอัตโนมัติเก็บป้าย "อนุมัติอัตโนมัติโดย ..." ไว้ใน meta.auto_approved ของรายการ
 */
class TopupAutoApprove
{
    public const SMS = 'sms';

    public const SLIP = 'slipok';

    /** 🧾 (2026-11-10) สลิปที่ลูกค้าส่งในแชทเพจ (บอทตรวจแล้วส่งต่อ) — ใช้สวิตช์เดียวกับ SLIP */
    public const SLIP_CHAT = 'slipok_chat';

    private const KEYS = [
        self::SMS  => 'topup_auto_approve_sms',
        self::SLIP => 'topup_auto_approve_slip',
    ];

    private const LABELS = [
        self::SMS  => 'อนุมัติอัตโนมัติโดย SMS',
        self::SLIP => 'อนุมัติอัตโนมัติโดย SlipOK',
        self::SLIP_CHAT => 'อนุมัติอัตโนมัติโดย SlipOK (สลิปจากแชทเพจ)',
    ];

    /** SMS ธนาคารเครดิตให้เองได้ไหม (ยังต้องเปิด smschecker.enabled ด้วย) */
    public static function sms(): bool
    {
        return (bool) config('smschecker.enabled') && self::on(self::SMS);
    }

    /** สลิปที่ SlipOK ตรวจผ่านเครดิตให้เองได้ไหม */
    public static function slip(): bool
    {
        return self::on(self::SLIP);
    }

    /** ค่าสวิตช์ดิบ (ไม่สน smschecker.enabled) — ใช้กับหน้าตั้งค่า */
    public static function on(string $channel): bool
    {
        return Setting::get(self::key($channel), '1') !== '0';
    }

    public static function set(string $channel, bool $on): void
    {
        Setting::put(self::key($channel), $on ? '1' : '0', 'wallet');
    }

    public static function key(string $channel): string
    {
        return self::KEYS[$channel] ?? throw new \InvalidArgumentException("unknown channel {$channel}");
    }

    public static function label(string $channel): string
    {
        return self::LABELS[$channel] ?? 'อนุมัติอัตโนมัติ';
    }
}
