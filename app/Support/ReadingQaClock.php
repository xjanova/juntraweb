<?php

namespace App\Support;

use App\Models\ChatConversation;
use App\Models\Reading;

/**
 * ⏳ นาฬิกาถามต่อหลังเปิดไพ่ (เจ้าของสั่ง 2026-11-05)
 *
 * "นาฬิกาเริ่มนับถอยหลังตามระบบจริงที่เราจับเวลา" · "ทำไมบอทไม่รู้ว่าเหลือเวลาเท่าไหร่"
 * "ปุ่ม พอแล้ว ด้วย หมายถึงเลิกก่อนเวลา และควรมีนาฬิกาให้เห็นนับถอยหลัง จากคำถามแรก"
 *
 * กติกาเดียวกับ Pro Session ของบอท FB/LINE (config/reading_qa.php):
 *   - ห้องที่คุยต่อจากไพ่ (reading_id) เท่านั้น — ห้องคุยทั่วไปไม่มีนาฬิกา
 *   - เริ่มนับที่คำถามแรกของลูกค้า (qa_started_at) · กด "พอแล้ว" = จบทันที (qa_ended_at)
 *   - หมดเวลา/จบแล้ว → ส่งข้อความในห้องนี้ไม่ได้ (ไม่คิดเงิน) ไปคุยเรื่องอื่นในห้องใหม่ได้
 *   - ทุกข้อความแนบ "เหลือ N นาที" ไปกับบริบทไพ่ ผู้พยากรณ์จึงตอบเรื่องเวลาได้ และบอกเมื่อใกล้หมด
 * เวลาทั้งหมดเป็นของเซิร์ฟเวอร์ (epoch วินาที) หน้าเว็บแค่นับถอยหลังตาม
 */
final class ReadingQaClock
{
    /** นาทีถามต่อของไพ่ชุดนี้ */
    public static function minutesFor(Reading $reading): int
    {
        $key = TarotSpreads::keyFromType((string) $reading->type);
        $map = (array) config('reading_qa.minutes', []);

        return max(1, (int) ($map[$key] ?? $map['default'] ?? 7));
    }

    /**
     * สถานะนาฬิกาของห้องนี้ — null ถ้าไม่ใช่ห้องที่คุยต่อจากไพ่
     *
     * @return array{minutes:int,started:bool,ends_at:?int,ended:bool,over:bool,left:int,warn:int}|null
     */
    public static function state(ChatConversation $conversation, ?Reading $reading): ?array
    {
        if ($reading === null) {
            return null;
        }
        $minutes = self::minutesFor($reading);
        $started = $conversation->qa_started_at;
        $endsAt  = $started ? $started->copy()->addMinutes($minutes) : null;
        $ended   = $conversation->qa_ended_at !== null;
        $left    = $endsAt ? max(0, (int) now()->diffInSeconds($endsAt, false)) : $minutes * 60;

        return [
            'minutes' => $minutes,
            'started' => $started !== null,
            'ends_at' => $endsAt?->getTimestamp(),
            'ended'   => $ended,
            'over'    => $ended || ($endsAt !== null && $left <= 0),
            'left'    => $ended ? 0 : $left,
            'warn'    => (int) config('reading_qa.warn_minutes', 3),
        ];
    }

    /** คำถามแรก → เริ่มนับ (ครั้งเดียว — สองแท็บส่งพร้อมกันได้เวลาเดียวกัน) */
    public static function startIfNeeded(ChatConversation $conversation): void
    {
        if ($conversation->qa_started_at !== null || $conversation->reading_id === null) {
            return;
        }
        ChatConversation::whereKey($conversation->id)->whereNull('qa_started_at')->update(['qa_started_at' => now()]);
        $conversation->refresh();
    }

    /** "พอแล้ว" — จบก่อนเวลา · คืน true ถ้าเพิ่งจบรอบนี้ (กดซ้ำ = false) */
    public static function end(ChatConversation $conversation): bool
    {
        $changed = ChatConversation::whereKey($conversation->id)->whereNull('qa_ended_at')->update([
            'qa_ended_at'   => now(),
            'qa_started_at' => $conversation->qa_started_at ?? now(),
        ]) > 0;
        $conversation->refresh();

        return $changed;
    }

    /**
     * บรรทัดเวลาที่แนบหน้าบริบทไพ่ทุกข้อความ — ผู้พยากรณ์รู้ว่าเหลือเวลาเท่าไหร่
     * (ใส่ไว้ต้นบริบท บริบทยาวถูกตัดท้ายเมื่อไรบรรทัดนี้ก็ยังอยู่)
     */
    public static function contextLine(?array $state): ?string
    {
        if ($state === null) {
            return null;
        }
        $mins = max(1, (int) ceil($state['left'] / 60));
        $line = "⏱️ เวลาถามต่อของไพ่ชุดนี้: เหลืออีกประมาณ {$mins} นาที จากทั้งหมด {$state['minutes']} นาที (เริ่มนับตั้งแต่คำถามแรกของลูก)";
        $line .= "\n- ถ้าลูกถามว่าเหลือเวลาเท่าไหร่ ให้ตอบตามตัวเลขนี้";
        if ($mins <= $state['warn']) {
            $line .= "\n- ใกล้หมดเวลาแล้ว: ตอบคำถามนี้ให้ครบ แล้วบอกลูกสั้น ๆ ว่าเหลือเวลาอีกราว {$mins} นาที ชวนถามข้อที่สำคัญที่สุด";
        }

        return $line;
    }

    /** ข้อความเมื่อส่งหลังหมดเวลา */
    public static function overMessage(?string $persona): string
    {
        $self = ChatRooms::selfName($persona);

        return "หมดเวลาถามต่อของไพ่ชุดนี้แล้วนะคะ ขอบคุณที่ให้{$self}ได้อ่านไพ่ให้ค่ะ 🌙 อยากคุยเรื่องอื่นต่อ หรือเปิดไพ่ชุดใหม่ กดปุ่มด้านล่างได้เลยค่ะ";
    }

    /** คำลาเมื่อลูกค้ากด "พอแล้ว" (ประกอบในเครื่อง ไม่ถาม AI ไม่คิดเงิน) */
    public static function farewell(?string $persona): string
    {
        $self = ChatRooms::selfName($persona);

        return "ขอบคุณที่ให้{$self}ได้อ่านไพ่และคุยด้วยนะคะ 🌙 ทำตามที่ไพ่แนะนำทีละขั้น แล้วทุกอย่างจะค่อย ๆ คลี่คลายค่ะ อยากกลับมาถามเมื่อไร {$self}รออยู่ตรงนี้เสมอค่ะ";
    }
}
