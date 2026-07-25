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
     * เพดานข้อความฟรีต่อคนต่อวัน — Setting 'chat_daily_limit' (default 30, 0 = ไม่จำกัด)
     * ใช้เฉพาะโหมดคุยฟรี (โหมดคิดเงินมีวอลเลตเป็นเบรกอยู่แล้ว)
     */
    public static function dailyLimit(): int
    {
        if (! self::isFree()) {
            return 0;
        }

        $val = Setting::get('chat_daily_limit');

        return is_numeric($val) ? max(0, (int) $val) : 30;
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

    /** โควตาที่เหลือวันนี้ (คืน null เมื่อไม่จำกัด/ไม่ใช่โหมดฟรี) */
    public static function dailyLeft($user): ?int
    {
        $limit = self::dailyLimit();
        if ($limit <= 0) {
            return null;
        }

        return max(0, $limit - self::dailyUsed($user));
    }

    /** ใช้โควตาวันนี้ครบแล้วหรือยัง */
    public static function exhausted($user): bool
    {
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
                    'reason' => 'กรุณาเข้าสู่ระบบด้วย Thaiprompt (Facebook หรือ LINE) เพื่อคุยกับแม่หมอ'];
        }

        // โหมดคุยฟรี — เปิดให้สมาชิก SSO ทุกคน ไม่ต้องเช็คการเชื่อม FB/LINE
        // (เงื่อนไขนั้นเป็นของยุคคิดเงินต่อข้อความ) จำเป็นสำหรับลูกค้าจาก
        // Magic Link ของบอท: บัญชีบอทมี facebook_psid แต่ไม่มี facebook_user_id
        if ($requireChannelLink && ! self::isFree() && ! $user->isLinkedViaFbOrLine()) {
            return ['allowed' => false, 'code' => 'no_link',
                    'reason' => 'บัญชีของคุณยังไม่ได้เชื่อมกับ Facebook หรือ LINE — เข้าสู่ระบบใหม่ผ่าน Thaiprompt เพื่อยืนยันตัวตน'];
        }

        if ($requireChannelLink && empty($user->thaiprompt_token)) {
            return ['allowed' => false, 'code' => 'no_token',
                    'reason' => 'session หมดอายุ — กรุณาเข้าสู่ระบบใหม่'];
        }

        return ['allowed' => true, 'code' => null, 'reason' => null];
    }
}
