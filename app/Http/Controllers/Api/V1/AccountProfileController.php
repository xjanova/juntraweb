<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Profile;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\ProfileAstrology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 🪐 (2026-11-12) ข้อมูลโหราศาสตร์ในแอพ — คู่กับหน้าเว็บ /account/astrology
 *
 * กติกาตรวจ/การคำนวณราศีมาจาก ProfileAstrology ชุดเดียวกับเว็บ · กติกาช่องชื่อมาจาก ProfileUpdateRequest
 *   - แอพแก้ได้เฉพาะ ชื่อ · วัน/เวลา/สถานที่เกิด · LINE ID · รับคำทำนายรายวัน (ส่งมาบางช่องก็ได้)
 *   - อีเมล/เบอร์เข้าระบบแก้ที่นี่ไม่ได้ (เป็นตัวตนของบัญชี) — ส่งมาก็ไม่สนใจ
 *   - แอพเลือกราศีเองไม่ได้: เซิร์ฟเวอร์คำนวณจากวันเกิดเสมอ (ราศีไทย นิรายนะ ลาหิรี) — ส่ง zodiac_slug มาก็ไม่สนใจ
 */
class AccountProfileController extends Controller
{
    /** ช่องโปรไฟล์ที่แอพแก้ได้ — ใช้กติกาของเว็บ (ProfileAstrology::rules) ตรง ๆ */
    private const EDITABLE = ['birth_date', 'birth_time', 'birth_place', 'line_id', 'subscribed'];

    /** ข้อความไทยที่แอพโชว์ได้ทันที (ไม่พึ่ง locale ของเซิร์ฟเวอร์) */
    private const MESSAGES = [
        'name.required'          => 'กรุณากรอกชื่อ',
        'name.string'            => 'ชื่อต้องเป็นข้อความ',
        'name.max'               => 'ชื่อยาวได้ไม่เกิน :max ตัวอักษร',
        'birth_date.date'        => 'วันเกิดไม่ถูกต้อง กรุณาเลือกวันเกิดใหม่',
        'birth_date.before'      => 'วันเกิดต้องไม่เกินวันนี้',
        'birth_date.after'       => 'วันเกิดต้องอยู่หลังวันที่ 1 มกราคม ค.ศ. 1900',
        'birth_time.date_format' => 'เวลาเกิดต้องอยู่ในรูปแบบ ชั่วโมง:นาที เช่น 08:30',
        'birth_place.string'     => 'สถานที่เกิดต้องเป็นข้อความ',
        'birth_place.max'        => 'สถานที่เกิดยาวได้ไม่เกิน :max ตัวอักษร',
        'line_id.string'         => 'LINE ID ต้องเป็นข้อความ',
        'line_id.max'            => 'LINE ID ยาวได้ไม่เกิน :max ตัวอักษร',
        'subscribed.boolean'     => 'ค่าการรับคำทำนายรายวันไม่ถูกต้อง',
    ];

    /** GET /v1/account/profile — ยังไม่มีแถวโปรไฟล์ก็สร้างแถวว่างให้ เหมือนหน้าเว็บ */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->payload($user, ProfileAstrology::profileFor($user))]);
    }

    /** PATCH /v1/account/profile — ส่งมาเฉพาะช่องที่จะแก้ ช่องที่ไม่ส่งคงค่าเดิม */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            ['name' => array_merge(['sometimes'], ProfileUpdateRequest::nameRules())]
                + Arr::only(ProfileAstrology::rules(), self::EDITABLE),
            self::MESSAGES,
        );

        if ($validator->fails()) {
            return response()->json([
                'message'     => $validator->errors()->first(),
                'reason_code' => 'validation_failed',
                'errors'      => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $user = $request->user();
        $profile = ProfileAstrology::profileFor($user);

        $fields = Arr::only($data, ['birth_date', 'birth_time', 'birth_place', 'line_id']);
        if (array_key_exists('subscribed', $data)) {
            $fields['subscribed'] = $request->boolean('subscribed');
        }

        // วัน/เวลาเกิดเปลี่ยน → คำนวณราศีใหม่ทั้งคู่ เหมือนเว็บตอนติ๊ก "คำนวณอัตโนมัติ" (ค่าเริ่มต้นของฟอร์ม)
        // ช่องที่ไม่ได้ส่งมาใช้ค่าเดิมในโปรไฟล์ · ส่งแค่ LINE ID = ไม่แตะราศี (ราศีที่เลือกเองบนเว็บยังอยู่)
        // ล้างวันเกิด = ไม่มีอะไรให้คำนวณ ราศีเดิมคงไว้ — เว็บก็เป็นแบบนี้ (ฟอร์มส่งราศีเดิมกลับมา)
        if (array_key_exists('birth_date', $fields) || array_key_exists('birth_time', $fields)) {
            $basis = [
                'birth_date' => array_key_exists('birth_date', $fields) ? $fields['birth_date'] : $profile->birth_date?->format('Y-m-d'),
                'birth_time' => array_key_exists('birth_time', $fields) ? $fields['birth_time'] : self::hm($profile->birth_time),
            ];
            $fields += Arr::only(ProfileAstrology::withDerivedZodiacs($basis, true), ['zodiac_slug', 'chinese_zodiac_slug']);
        }

        DB::transaction(function () use ($user, $profile, $data, $fields) {
            if (array_key_exists('name', $data)) {
                $user->fill(['name' => $data['name']])->save();
            }
            $profile->fill($fields)->save();
        });

        return response()->json(['data' => $this->payload($user, $profile->fresh())]);
    }

    private function payload(User $user, Profile $profile): array
    {
        $zodiac = $profile->zodiac();
        $chinese = $profile->chineseZodiac();

        return [
            'name'           => $user->name,
            // อีเมลที่ระบบสร้างให้คนสมัครด้วยเบอร์ ไม่ใช่อีเมลจริง — ไม่โชว์ (กติกาเดียวกับ /v1/auth/me)
            'email'          => PhoneNumber::isPlaceholderEmail($user->email) ? null : $user->email,
            // เบอร์ที่ใช้เข้าระบบ (users.phone ค่าเดียวกับ /v1/auth/me)
            'phone'          => $user->phone,
            // เบอร์ติดต่อที่กรอกในหน้าข้อมูลโหราศาสตร์บนเว็บ (profiles.phone) — คนละเบอร์กับเบอร์เข้าระบบ
            'contact_phone'  => $profile->phone,
            'birth_date'     => $profile->birth_date?->format('Y-m-d'),
            'birth_time'     => self::hm($profile->birth_time),
            'birth_place'    => $profile->birth_place,
            'zodiac'         => $zodiac ? ['slug' => $zodiac->slug, 'name_th' => $zodiac->name_th] : null,
            'chinese_zodiac' => $chinese ? ['slug' => $chinese->slug, 'name_th' => $chinese->name_th] : null,
            'line_id'        => $profile->line_id,
            'subscribed'     => (bool) $profile->subscribed,
        ];
    }

    /** เวลาเกิดแบบ H:i (คอลัมน์ time ของ MySQL คืน H:i:s) — แปลงแบบเดียวกับฟอร์มบนเว็บ */
    private static function hm(?string $time): ?string
    {
        return $time ? Carbon::parse($time)->format('H:i') : null;
    }
}
