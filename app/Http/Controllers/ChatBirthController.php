<?php

namespace App\Http\Controllers;

use App\Support\Astrology;
use App\Support\ChatRooms;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🎂 (2026-11-06) วันเดือนปีเกิดกรอกในแชทได้เลย — เก็บเข้าโปรไฟล์ทันที
 *
 * เจ้าของสั่ง "ให้กรอกวันเดือนปีเกิดในแชทเลย แม้จะฟรี เพื่อเก็บเข้าโปรไฟล์ อัพเดทพร้อมไปเลย
 * หากใครวันเดือนปีเกิดผิด ก็ต้องให้กรอกใหม่ หรือแนะนำไปเปลี่ยนได้เอง"
 *   - ใช้กับไพ่ฟรีของบุษบา (FreeReadingPolicy ต้องมีวันเกิดก่อน) และทุกการเปิดไพ่ที่ผสานดวงวันเกิด
 *   - กรอกผิด = กดแก้ในแชทได้อีก หรือไปแก้ที่หน้า บัญชี ▸ ข้อมูลโหราศาสตร์
 *   - ราศีตะวันตก/ปีนักษัตรคำนวณใหม่ตามวันเกิดเสมอ (เหมือนติ๊ก "ใช้อัตโนมัติ" ในหน้าบัญชี)
 *     ไม่งั้นแก้วันเกิดแล้วราศีเดิมค้าง — ผู้พยากรณ์อ่านดวงคนละราศี
 */
class ChatBirthController extends Controller
{
    private const MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            // วันที่จริงในปฏิทิน (31 ก.พ. ไม่ผ่าน) · ไม่เกินวันนี้ · ไม่ก่อนปี 1900
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:tomorrow', 'after:1900-01-01'],
            'room'       => ['nullable', 'string', 'max:16'],
        ], [
            'birth_date.required'    => 'กรุณาเลือกวัน เดือน และปีเกิดให้ครบค่ะ',
            'birth_date.date_format' => 'วันเกิดนี้ไม่มีในปฏิทินค่ะ ลองเลือกใหม่อีกครั้งนะคะ',
            'birth_date.before'      => 'วันเกิดต้องไม่เกินวันนี้ค่ะ',
            'birth_date.after'       => 'ปีเกิดเก่าเกินไปค่ะ ลองเลือกใหม่อีกครั้งนะคะ',
        ]);

        $birth   = Carbon::createFromFormat('Y-m-d', $data['birth_date'])->startOfDay();
        $profile = $user->profile ?? $user->profile()->create([]);
        $changed = $profile->birth_date === null || $profile->birth_date->toDateString() !== $birth->toDateString();
        $profile->fill([
            'birth_date'          => $birth->toDateString(),
            'zodiac_slug'         => Astrology::westernZodiacSlug($birth),
            'chinese_zodiac_slug' => Astrology::chineseZodiacSlug($birth->year),
        ])->save();
        $user->setRelation('profile', $profile);

        $room = ChatRooms::normalize($data['room'] ?? null);

        return response()->json([
            'ok'         => true,
            'changed'    => $changed,
            'birth_date' => $birth->toDateString(),
            'label'      => self::label($birth),
            'message'    => 'บันทึกวันเกิด ' . self::label($birth) . ' เข้าโปรไฟล์แล้วค่ะ ' . ChatRooms::selfName($room) . 'จำไว้แล้วนะคะ ✨',
        ]);
    }

    /** "6 พฤศจิกายน 2530" — ปีเป็น พ.ศ. ตามที่ลูกค้าคุ้น */
    public static function label(?Carbon $date): ?string
    {
        return $date ? $date->day . ' ' . self::MONTHS[$date->month - 1] . ' ' . ($date->year + 543) : null;
    }
}
