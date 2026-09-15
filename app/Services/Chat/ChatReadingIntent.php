<?php

namespace App\Services\Chat;

/**
 * ลูกค้า "ขอให้ทำนาย" หรือยัง — จุดที่แชทฟรีเปลี่ยนเป็นการเปิดไพ่ที่หักเครดิต
 *
 * เจ้าของกำหนด (2026-09-15): แชทกับแม่หมอบนเว็บต้องเหมือนคุยกับบอทแม่หมอใน Facebook/LINE —
 * คุยฟรี จนกว่าจะเริ่มการทำนาย ในแชทแม่หมอจึงไม่ทำนายให้ฟรี แต่ยื่นการ์ดแพ็กเกจเปิดไพ่ (ChatOffers)
 *
 * ด่านนี้เป็นด่านแรก (ฟรี ไม่ต้องถาม AI) สำหรับคำขอที่ชัด ๆ อย่าง "ดูดวงให้หน่อย" "เปิดไพ่"
 * กรณีอ้อม ๆ ฝั่ง Thaiprompt ให้แม่หมอตัดสินเองแล้วแปะป้าย offer กลับมา — สองชั้นช่วยกัน
 *
 * จงใจอนุรักษ์นิยม: จับเฉพาะคำที่เป็น "การขอให้ดู" จริง ๆ — ลูกค้าที่แค่ระบายเรื่องความรัก
 * ต้องได้คำปลอบใจจากแม่หมอ ไม่ใช่ใบเสนอราคา
 */
final class ChatReadingIntent
{
    public const TOPICS = ['love', 'career', 'money', 'health', 'general'];

    /** คำที่แปลว่า "ขอให้ดู/ทำนาย" ตรง ๆ */
    private const ASK = '(ดูดวง|ดูดวงให้|ทำนาย|พยากรณ์|เปิดไพ่|ดูไพ่|จับไพ่|สุ่มไพ่|ขอไพ่|ไพ่ยิปซี|ไพ่ทาโร|ทาโร่|ทาโรต์|tarot|ดวงชะตา|ดูหมอ|หมอดูให้)';

    /** "ดวง" + เรื่อง/ช่วงเวลา = ถามดวงของตัวเอง */
    private const DUANG = 'ดวง\s*(ความรัก|คู่|เนื้อคู่|การงาน|งาน|การเงิน|เงิน|โชคลาภ|สุขภาพ|วันนี้|พรุ่งนี้|สัปดาห์นี้|เดือนนี้|ปีนี้|ช่วงนี้|ของ(หนู|ผม|ฉัน|เรา|ดิฉัน)|เป็น(ยังไง|อย่างไร))';

    /** @return string|null  หมวด (love|career|money|health|general) หรือ null = ไม่ได้ขอให้ทำนาย */
    public static function detect(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 400) {
            return null;   // เรื่องยาว ๆ คือการเล่า ไม่ใช่การสั่ง — ให้แม่หมอฟังก่อน
        }

        $asked = preg_match('/' . self::ASK . '/u', $t) === 1 || preg_match('/' . self::DUANG . '/u', $t) === 1;
        if (! $asked) {
            return null;
        }
        // "ไม่ต้องดูดวง" / "ไม่อยากเปิดไพ่" = ปฏิเสธ ไม่ใช่ขอ
        if (preg_match('/(ไม่|อย่า)\s*(ต้อง|อยาก|เอา|ขอ)?\s*' . self::ASK . '/u', $t) === 1) {
            return null;
        }

        return self::topicOf($t);
    }

    public static function topicOf(string $t): string
    {
        return match (true) {
            preg_match('/(รัก|แฟน|เนื้อคู่|คู่ครอง|คนรัก|แต่งงาน|สามี|ภรรยา|โสด|หัวใจ|คนคุย|ความสัมพันธ์)/u', $t) === 1 => 'love',
            preg_match('/(เงิน|หนี้|รวย|โชคลาภ|ลงทุน|หวย|ลาภ|ขาดทุน|กำไร)/u', $t) === 1 => 'money',
            preg_match('/(งาน|อาชีพ|เลื่อนตำแหน่ง|สัมภาษณ์|ธุรกิจ|ค้าขาย|เรียน|สอบ|หัวหน้า|ลาออก)/u', $t) === 1 => 'career',
            preg_match('/(สุขภาพ|ป่วย|โรค|ร่างกาย)/u', $t) === 1 => 'health',
            default => 'general',
        };
    }

    public static function normalizeTopic(?string $topic): string
    {
        $topic = strtolower((string) $topic);

        return in_array($topic, self::TOPICS, true) ? $topic : 'general';
    }
}
