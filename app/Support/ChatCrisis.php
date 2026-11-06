<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * 🤍 (2026-11-06) ลูกค้าที่ส่งสัญญาณอยากทำร้ายตัวเอง — Thaiprompt (PR #2649) บอกมากับคำตอบแชท
 *
 *   data.crisis      = เทิร์นนี้คือคำปลอบของผู้พยากรณ์ (kind ยังเป็น "guard") → **ไม่คิดค่าแชทเทิร์นนี้**
 *   data.crisis_room = ห้องอยู่ในโหมดวิกฤต → ไม่ยื่นการ์ดเปิดไพ่ ไม่วางแพ็กเกจบนโต๊ะ ไม่มีปุ่มชวนดูดวง
 *
 * จำต่อคน (ไม่ใช่ต่อห้อง): ฝั่ง Thaiprompt ปักธงลูกค้าคนนี้ไว้ ห้องใหม่ทุกห้องเปิดในโหมดวิกฤตเหมือนกัน
 * และด่านแรกของเรา (ChatReadingIntent) ตัดสินก่อนถาม Thaiprompt — ถ้าไม่จำไว้ "อยากดูดวงความรัก"
 * ในห้องใหม่จะได้การ์ดขายก่อนที่ Thaiprompt จะได้บอกอะไรเลย
 *
 * อายุ 6 ชม. เท่าห้องฝั่ง Thaiprompt · ทุกคำตอบที่มีฟิลด์ crisis_room เขียนทับค่าเดิม (false = ล้าง)
 * Thaiprompt รุ่นก่อนไม่ส่งฟิลด์นี้ = ไม่แตะค่าเดิม
 */
final class ChatCrisis
{
    public const TTL_HOURS = 6;

    public static function active(?User $user): bool
    {
        return $user !== null && Cache::get(self::key($user->id)) === true;
    }

    /** @param  bool|null  $crisisRoom  null = Thaiprompt ไม่ได้บอก (รุ่นเก่า / ทางสำรอง) */
    public static function remember(User $user, ?bool $crisisRoom): void
    {
        if ($crisisRoom === true) {
            Cache::put(self::key($user->id), true, now()->addHours(self::TTL_HOURS));
        } elseif ($crisisRoom === false) {
            Cache::forget(self::key($user->id));
        }
    }

    /** โควตาคุยฟรีหมดระหว่างห้องวิกฤต — ไม่ชวนเปิดไพ่ (ข้อความปกติลงท้ายด้วยการขาย) */
    public static function limitMessage(?string $room = null): string
    {
        $self = ChatRooms::selfName($room ?? ChatRooms::DEFAULT);
        $customer = ChatRooms::customer($room ?? ChatRooms::DEFAULT);

        // ไม่ยัดเบอร์สายด่วน — Thaiprompt ให้เบอร์เฉพาะเมื่อลูกค้าถามเอง (เจ้าของสั่งใน #2649)
        return "วันนี้{$customer}คุยกับ{$self}ครบโควตาแล้วนะคะ 🤍 {$self}ยังอยู่ตรงนี้เสมอ "
            ."พรุ่งนี้กลับมาเล่าให้{$self}ฟังต่อได้เลยนะคะ ระหว่างนี้ลองหาใครสักคนที่ไว้ใจอยู่ข้าง ๆ ด้วยนะคะ";
    }

    private static function key(int $userId): string
    {
        return "juntra:chat:crisis:u{$userId}";
    }
}
