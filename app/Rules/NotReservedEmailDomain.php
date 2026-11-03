<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ห้ามใช้อีเมลโดเมนที่ระบบสร้างให้เอง (สมัครเอง / เปลี่ยนอีเมลเอง)
 *
 * Thaiprompt สร้างอีเมลสังเคราะห์ให้ลูกค้าบอท เช่น fb_{PSID}@thaiprompt.local
 * และ PSID อ่านได้จากลิงก์ magic link ที่ถูกส่งต่อ — ถ้าปล่อยให้สมัครด้วยอีเมลนี้ได้
 * คนร้ายจะจองบัญชีรอไว้ก่อน แล้ว SSO ของเจ้าของตัวจริงจะไหลเข้าบัญชีคนร้าย (pre-account hijack)
 * ส่วน phone.juntra.local คืออีเมลแทนที่จันทราสร้างให้บัญชีเบอร์โทร ห้ามให้คนนอกจองเช่นกัน
 *
 * $ignore = อีเมลปัจจุบันของผู้ใช้ (หน้าแก้โปรไฟล์) — บัญชีที่ระบบสร้างอีเมลให้
 * ต้องกดบันทึกชื่อได้โดยไม่ถูกบังคับเปลี่ยนอีเมล
 */
class NotReservedEmailDomain implements ValidationRule
{
    /** โดเมนที่สงวนไว้ (รวมโดเมนย่อยทั้งหมด) */
    public const RESERVED_DOMAINS = [
        'thaiprompt.local',
        PhoneNumber::PLACEHOLDER_EMAIL_DOMAIN,
    ];

    public function __construct(private ?string $ignore = null)
    {
    }

    /** อีเมลนี้อยู่ในโดเมนที่สงวนไว้หรือไม่ (ใช้ซ้ำได้นอก validator เช่นใน SSO callback) */
    public static function isReserved(?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        // ตัดจุดท้ายโดเมนออก กันเลี่ยงด้วย "x@thaiprompt.local."
        $domain = rtrim(substr($email, $at + 1), '.');

        foreach (self::RESERVED_DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.' . $reserved)) {
                return true;
            }
        }

        return false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        // อีเมลเดิมของบัญชีตัวเอง (ระบบสร้างให้) = ไม่ได้เปลี่ยน ให้ผ่าน
        if ($this->ignore !== null && strtolower(trim($value)) === strtolower(trim($this->ignore))) {
            return;
        }

        if (self::isReserved($value)) {
            $fail('อีเมลนี้เป็นอีเมลที่ระบบสงวนไว้ ใช้สมัครหรือเปลี่ยนเป็นอีเมลนี้ไม่ได้ — กรุณาใช้อีเมลจริงของคุณ');
        }
    }
}
