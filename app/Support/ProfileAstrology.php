<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 🪐 (2026-11-12) ข้อมูลโหราศาสตร์ในโปรไฟล์ — กติกาเดียวทั้งหน้าเว็บ /account/astrology และแอพ (/v1/account/profile)
 *
 * ย้ายออกมาจาก AccountController::astrologyUpdate โดยไม่เปลี่ยนพฤติกรรมของเว็บ
 * ราศีคำนวณที่ Astrology เท่านั้น (ราศีไทย นิรายนะ ลาหิรี · ปีนักษัตรจากปี ค.ศ.) — ห้ามคำนวณเองที่อื่น
 */
final class ProfileAstrology
{
    /** กติกาตรวจฟอร์มข้อมูลโหราศาสตร์ของเว็บ — แอพหยิบไปใช้เฉพาะช่องที่แอพแก้ได้ */
    public static function rules(): array
    {
        return [
            'birth_date'          => ['nullable', 'date', 'before:tomorrow', 'after:1900-01-01'],
            'birth_time'          => ['nullable', 'date_format:H:i'],
            'birth_place'         => ['nullable', 'string', 'max:191'],
            'zodiac_slug'         => ['nullable', 'string', 'exists:zodiacs,slug'],
            'chinese_zodiac_slug' => ['nullable', 'string', 'exists:chinese_zodiacs,slug'],
            'phone'               => ['nullable', 'string', 'max:32'],
            'line_id'             => ['nullable', 'string', 'max:64'],
            'subscribed'          => ['nullable', 'boolean'],
            'auto_zodiac'         => ['nullable', 'boolean'],
        ];
    }

    /**
     * โปรไฟล์ของลูกค้า — ยังไม่มีแถวก็สร้างแถวว่างให้ (เหมือนที่หน้าเว็บทำมาตลอด)
     *
     * ผูกแถวที่สร้างกลับเข้า $user->profile ด้วย: เรียกซ้ำกับ User ตัวเดิมต้องได้แถวเดิม
     * (profiles.user_id ไม่มี unique index จริง — `->unique()` ในไมเกรชันต่อท้าย FK ไม่ได้สร้าง index)
     */
    public static function profileFor(User $user): Profile
    {
        if ($user->profile) {
            return $user->profile;
        }

        $profile = $user->profile()->create([]);
        $user->setRelation('profile', $profile);

        return $profile;
    }

    /**
     * Auto-derive zodiacs from birth_date when user ticked "ใช้อัตโนมัติ"
     * (or when fields are blank but DOB exists). Manual override wins
     * when the user picked a slug explicitly.
     *
     * ไม่มีวันเกิด = ไม่แตะราศีเลย (คืน $data เดิม)
     */
    public static function withDerivedZodiacs(array $data, bool $autoDerive): array
    {
        if (!empty($data['birth_date'])) {
            $birth = Carbon::parse($data['birth_date']);
            if ($autoDerive || empty($data['zodiac_slug'])) {
                $data['zodiac_slug'] = Astrology::thaiZodiacSlug($birth, $data['birth_time'] ?? null);
            }
            if ($autoDerive || empty($data['chinese_zodiac_slug'])) {
                $data['chinese_zodiac_slug'] = Astrology::chineseZodiacSlug($birth->year);
            }
        }

        return $data;
    }
}
