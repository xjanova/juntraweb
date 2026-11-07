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

    /**
     * 🤍 (2026-11-07) ข้อความนี้ "อาจ" เป็นสัญญาณอยากทำร้ายตัวเอง — ใช้แค่กันด่านขายของเราเอง
     *
     * ด่าน ChatReadingIntent ตัดสินก่อนถาม Thaiprompt: ข้อความแรกอย่าง "อยากตาย ดูดวงให้หน่อย"
     * ได้การ์ดขายแพ็กเกจกลับไปทันที โดยที่ Thaiprompt (ตัวตรวจวิกฤตตัวจริง) ไม่เคยเห็นข้อความเลย
     *
     * จงใจกว้าง: จับเกิน = แค่ไม่ยื่นการ์ดขายจากด่านแรก แล้วส่งให้ Thaiprompt ตัดสินตามปกติ
     * (ถ้าไม่ใช่วิกฤตจริง แม่หมอแปะป้าย offer กลับมาเองได้) · ❌ ไม่ใช้ตัดสินว่าเป็นวิกฤต ไม่ติดธง ไม่แจ้งใคร
     */
    public static function mayBeCrisis(string $text): bool
    {
        // ตัดช่องว่าง + ตัวอักษรล่องหน (zero-width / soft hyphen / BOM) — "อยาก ตาย" ต้องเท่ากับ "อยากตาย"
        $t = mb_strtolower((string) preg_replace('/[\s\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}]+/u', '', $text));
        if ($t === '') {
            return false;
        }

        return preg_match('/'.self::SELF_HARM_HINT.'/u', $t) === 1;
    }

    /** คำที่ลูกค้าพิมพ์จริงเวลาไม่อยากอยู่ต่อ (เทียบกับข้อความที่ตัดช่องว่างแล้ว) */
    private const SELF_HARM_HINT = 'อยากตาย|อยากจะตาย|ฆ่าตัวตาย|ฆ่าตัวเอง|ไม่อยากอยู่|ไม่อยากมีชีวิต|ไม่อยากหายใจ|ไม่อยากตื่น|'
        .'จบชีวิต|ปลิดชีพ|ทำร้ายตัวเอง|ทำร้ายร่างกายตัวเอง|กรีดข้อมือ|กรีดแขน|กินยาตาย|กินยาเกินขนาด|ผูกคอ|'
        .'กระโดดตึก|โดดตึก|โดดสะพาน|ตายๆไป|ตายไปซะ|หายไปจากโลก|ไม่มีใครต้องการ|อยู่ไปก็ไม่มีประโยชน์|'
        .'suicide|killmyself|wanttodie|endmylife|selfharm';

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
