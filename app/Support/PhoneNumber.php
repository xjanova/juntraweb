<?php

namespace App\Support;

/**
 * เบอร์โทรของผู้ใช้ — จุดเดียวที่ตัดสินว่า "เบอร์นี้เขียนยังไง"
 *
 * ก่อนหน้านี้ตรรกะนี้ถูกคัดลอกไว้สองที่ (RegisteredUserController กับ
 * LoginRequest) แล้ว **ไม่ตรงกัน**: ฝั่งสมัครรับตัวเลขกี่หลักก็ได้
 * ฝั่งล็อกอินต้อง ≥ 9 หลัก ผลคือคนที่พิมพ์เบอร์สั้น ๆ ตอนสมัครจะได้บัญชี
 * ที่ **ล็อกอินกลับเข้ามาไม่ได้ตลอดกาล** เพราะ normalise คนละแบบ
 *
 * ตอนนี้ทั้งเว็บ (Breeze) และ API ของแอพ (Sanctum) เรียกคลาสนี้ตัวเดียวกัน
 * เบอร์ที่สมัครได้จึงล็อกอินได้เสมอ ไม่ว่าจะเข้าทางไหน
 */
final class PhoneNumber
{
    private function __construct() {}

    /**
     * โดเมนอีเมลที่ระบบสร้างให้คนที่สมัครด้วยเบอร์อย่างเดียว
     *
     * ตั้งใจใช้โดเมนที่ส่งเมลจริงไม่ได้ — มันเป็นแค่คีย์ในตาราง users
     * ไม่ใช่ช่องทางติดต่อ และต้องไม่มีใครหลงส่งเมลไปจริง
     */
    public const PLACEHOLDER_EMAIL_DOMAIN = 'phone.juntra.local';

    /** สั้นสุดที่ยอมรับ (เบอร์บ้านไทย 9 หลัก เช่น 021234567) */
    private const MIN_DIGITS = 9;

    /** ยาวสุดที่คอลัมน์ users.phone รับได้ */
    private const MAX_DIGITS = 32;

    /**
     * แปลงเบอร์ให้เป็นรูปแบบเดียวเสมอ (08xxxxxxxx)
     *
     * คืน null เมื่อ "ไม่ใช่เบอร์" — เช่นเป็นอีเมล, ว่าง, หรือหลักไม่พอ
     * ผู้เรียกจึงแยกได้ว่าควรลองล็อกอินด้วยเบอร์ต่อไหม
     */
    public static function normalise(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        // มี @ = อีเมล ไม่ใช่เบอร์ — ตัดจบตรงนี้ ไม่ต้องยิง query เปล่า
        if ($raw === '' || str_contains($raw, '@')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';

        // +66 8xxxxxxxx / 668xxxxxxxx → 08xxxxxxxx
        if (str_starts_with($digits, '66') && strlen($digits) >= 11) {
            $digits = '0' . substr($digits, 2);
        }

        $len = strlen($digits);
        if ($len < self::MIN_DIGITS || $len > self::MAX_DIGITS) {
            return null;
        }

        return $digits;
    }

    /** true เมื่อข้อความนี้ดูเป็นเบอร์โทร (ใช้ตัดสินว่าจะ validate แบบไหน) */
    public static function looksLikePhone(?string $raw): bool
    {
        return self::normalise($raw) !== null;
    }

    /**
     * อีเมลภายในสำหรับบัญชีที่สมัครด้วยเบอร์อย่างเดียว
     *
     * ไม่ซ้ำเพราะ users.phone เป็น unique อยู่แล้ว
     */
    public static function placeholderEmail(string $normalisedPhone): string
    {
        return 'p' . $normalisedPhone . '@' . self::PLACEHOLDER_EMAIL_DOMAIN;
    }

    /** true เมื่ออีเมลนี้เป็นอีเมลที่ระบบสร้างให้ ไม่ใช่อีเมลจริงของผู้ใช้ */
    public static function isPlaceholderEmail(?string $email): bool
    {
        return is_string($email)
            && str_ends_with($email, '@' . self::PLACEHOLDER_EMAIL_DOMAIN);
    }
}
