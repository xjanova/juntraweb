<?php

namespace App\Support;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Setting;

/**
 * กติกาการเข้าใช้แชทแม่หมอ — แหล่งความจริงเดียวของทั้งเว็บและ API มือถือ
 *
 * ทำไมต้องมีคลาสนี้: เดิม ChatController (เว็บ) กับ Api\V1\ChatController
 * เป็นโค้ดคนละชุดที่ก๊อปกันมา พอเว็บเพิ่ม "เพดานคุยฟรีรายวัน" ฝั่ง API
 * ไม่ได้เพิ่มตาม → ผู้ใช้แอพยิง AI ฟรีได้ไม่จำกัด (ต้นทุนบานปลาย) และยัง
 * ไปกินโควตาของเว็บจนคนคนเดียวกันถูกบล็อกบนเว็บทั้งที่แอพยังคุยได้
 * ตั้งแต่นี้กติกาอยู่ที่เดียว ใครแก้ก็มีผลทั้งสองทาง
 */
class ChatPolicy
{
    /** โหมดคุยฟรี = ไม่คิดค่าบริการต่อข้อความ */
    public static function isFree(): bool
    {
        return Pricing::for('chat_message') <= 0;
    }

    /** ราคาต่อข้อความ (0 = ฟรี) */
    public static function cost(): float
    {
        return (float) Pricing::for('chat_message');
    }

    /**
     * โควตาข้อความฟรีต่อคนต่อวัน — Setting 'chat_daily_limit' (0 = ไม่จำกัด)
     *
     * ค่าเริ่มต้นคือ "ไม่จำกัด" ตามที่เจ้าของกำหนด (2026-07-25):
     * บนเว็บและแอพต้องคุยได้ฟรีเหมือนคุยกับบอทแม่หมอใน Facebook ซึ่งไม่มี
     * เพดานต่อวัน และเป็นบริบทเดียวกัน — การมีเพดานเฉพาะฝั่งเว็บ/แอพจะทำให้
     * ลูกค้าคนเดียวกันเจอกติกาคนละแบบในแต่ละช่องทาง
     *
     * 🎁 (2026-07-28) เดิมเมธอดนี้คืน 0 ทันทีเมื่อ "ไม่ใช่โหมดฟรี" ทำให้ตั้ง
     *    ราคาต่อข้อความเมื่อไหร่ โควตาฟรีหายทันที = ตั้งค่าโหมด
     *    "ฟรี N ข้อความก่อน แล้วค่อยหักเงิน" ไม่ได้เลย (เจ้าของสั่ง 2026-07-28)
     *    → ตอนนี้โควตาใช้ได้ทั้งสองโหมด ความหมายต่างกันตรงที่ **ใช้ครบแล้วเกิดอะไร**:
     *      • ราคา = 0  → ใช้ครบ = หยุดคุยวันนี้ (มีวอลเลตก็ไม่ช่วย เพราะไม่มีอะไรให้จ่าย)
     *      • ราคา > 0  → ใช้ครบ = เริ่มหักเครดิตต่อข้อความ (คุยต่อได้ ไม่ถูกบล็อก)
     */
    public static function dailyLimit(): int
    {
        $val = Setting::get('chat_daily_limit');

        return is_numeric($val) ? max(0, (int) $val) : 0;
    }

    /**
     * 🎁 (2026-07-28) ราคาที่ผู้ใช้ **คนนี้** ต้องจ่ายสำหรับข้อความถัดไป
     *
     * คืน 0 เมื่อยังอยู่ในโควตาฟรีของวันนี้ → ทุกจุดที่ `if ($cost > 0)` อยู่แล้ว
     * (เช็คยอดเงิน / หักเครดิต / ป้ายราคาในหน้าแชท) จะข้ามไปเองทั้งหมด
     * ไม่ต้องไล่แก้ทีละคอนโทรลเลอร์
     *
     * ⚠️ ทุกที่ที่จะ "คิดเงินจริง" ต้องเรียกตัวนี้ ไม่ใช่ cost()
     *    cost() = ป้ายราคาของบริการ · costFor() = ราคาที่คนนี้ต้องจ่ายตอนนี้
     */
    public static function costFor($user): float
    {
        $price = self::cost();

        if ($price <= 0) {
            return 0.0;
        }

        $quota = self::dailyLimit();

        // ไม่ตั้งโควตาฟรี → คิดเงินทุกข้อความตั้งแต่ข้อความแรก (พฤติกรรมเดิม)
        if ($quota <= 0) {
            return $price;
        }

        return self::dailyUsed($user) < $quota ? 0.0 : $price;
    }

    /**
     * จำนวนข้อความที่ผู้ใช้ส่งไปแล้ววันนี้ — นับรวมทุกช่องทาง (เว็บ + แอพ)
     * เพราะเป็นคนเดียวกันและต้นทุน AI ก้อนเดียวกัน
     *
     * ใช้ whereIn บน id ของห้องแทน whereHas เพื่อให้ใช้ index
     * (chat_conversation_id, role, created_at) ได้ตรง ๆ
     */
    public static function dailyUsed($user): int
    {
        if (! $user) {
            return 0;
        }

        return ChatMessage::whereIn(
            'chat_conversation_id',
            ChatConversation::where('user_id', $user->id)->select('id')
        )
            ->where('role', 'user')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /** โควตาฟรีที่เหลือวันนี้ (คืน null เมื่อไม่ได้ตั้งโควตา) */
    public static function dailyLeft($user): ?int
    {
        $limit = self::dailyLimit();
        if ($limit <= 0) {
            return null;
        }

        return max(0, $limit - self::dailyUsed($user));
    }

    /**
     * ใช้โควตาครบแล้ว **และคุยต่อไม่ได้จริง ๆ** หรือยัง
     *
     * 🎁 (2026-07-28) ครบโควตา ≠ ถูกบล็อกเสมอไป — ถ้าตั้งราคาต่อข้อความไว้
     *    ลูกค้าจ่ายเครดิตคุยต่อได้ การบล็อกตรงนี้จะกลายเป็นทางตัน
     *    (ปิดปากคนที่พร้อมจ่าย = เสียทั้งลูกค้าและรายได้)
     *    → บล็อกเฉพาะตอน "ฟรีล้วน" ซึ่งไม่มีอะไรให้จ่ายเพื่อคุยต่อ
     */
    public static function exhausted($user): bool
    {
        if (! self::isFree()) {
            return false;
        }

        $left = self::dailyLeft($user);

        return $left !== null && $left <= 0;
    }

    /** ข้อความตอนครบเพดาน — เตะเบา ๆ แล้วชวนไปเปิดไพ่ ไม่ใช่ error แข็ง ๆ */
    public static function limitMessage(): string
    {
        $limit = self::dailyLimit();

        return "วันนี้ลูกคุยกับแม่หมอครบ {$limit} ข้อความแล้วค่ะ ✨ พรุ่งนี้แม่หมอรอฟังเรื่องราวต่อนะคะ — "
            .'หรือถ้าอยากรู้ลึกถึงดวงชะตา ให้แม่หมอเปิดไพ่ดูแบบเจาะจงได้เลยค่ะ 🔮';
    }

    /**
     * ผู้ใช้คนนี้คุยได้ไหม
     *
     * @param  bool  $requireChannelLink  เว็บบังคับเชื่อม FB/LINE ในโหมดคิดเงิน
     *                                    ส่วนแอพมือถือไม่บังคับ (ล็อกอินด้วยอีเมลได้)
     *                                    — คงพฤติกรรมเดิมของแต่ละช่องทางไว้
     * @return array{allowed:bool,code:?string,reason:?string}
     */
    public static function gate($user, bool $requireChannelLink = true): array
    {
        if (! $user) {
            return ['allowed' => false, 'code' => 'guest',
                    'reason' => 'กรุณาเข้าสู่ระบบ หรือสมัครสมาชิกฟรี เพื่อคุยกับแม่หมอ'];
        }

        // 🌙 (2026-09-15) เจ้าของกำหนด: "ทุกคนที่ล็อกอินคุยได้" ทั้งเว็บและแอพ
        //
        // เดิมเว็บบังคับ (1) เชื่อม Facebook/LINE เมื่อมีค่าบริการ และ (2) มี token ของ Thaiprompt
        // เพราะแชทต้องยิงไปหาแม่หมอด้วย token ของลูกค้า — ลูกค้าที่สมัครด้วยเบอร์/อีเมลจึงคุยไม่ได้เลย
        // และได้ข้อความ "session หมดอายุ" ที่ชวนเข้าใจผิด (production: สมาชิก 4 คน มี token 1 คน,
        // 60 วันไม่มีข้อความจากลูกค้าสักข้อความ) ตอนนี้เว็บคุยกับแม่หมอด้วยตัวตนของเว็บเอง
        // (App\Services\Chat\MaeMorUpstream) เงื่อนไขทั้งสองจึงหมดความจำเป็น
        // $requireChannelLink คงไว้ให้ผู้เรียกเดิม ไม่มีผลแล้ว
        return ['allowed' => true, 'code' => null, 'reason' => null];
    }
}
