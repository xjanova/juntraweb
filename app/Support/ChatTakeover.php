<?php

namespace App\Support;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * 🧑‍💼 (2026-11-09) แอดมินคุมห้องผู้พยากรณ์ — บอทหยุดตอบระหว่างที่แอดมินคุยกับลูกค้า
 *
 * เจ้าของสั่ง: "ระบบแชทของแอดมิน สำหรับแม่หมอแต่ละห้อง ไว้หลังบ้าน ตอบโต้กับผู้ใช้ได้ทุกห้อง
 *   เวลาแอดมินพูดแชท บอทแม่หมอต้องหยุดก่อน และมีปุ่มเทคบอทเช่นกัน"
 *   + "หน้าที่ลูกค้าเห็น เราก็เห็นแบบเดียวกับลูกค้า แล้วมีปุ่มพิเศษคุยแทนบอทแม่หมอได้"
 *
 * กติกา (ที่เดียวทั้งเว็บ แอพมือถือ และหลังบ้าน):
 *  - แอดมินพิมพ์ = บอทหยุดทันที · กด "เทคบอท" = หยุดโดยไม่ต้องพิมพ์ · "คืนให้บอท" = บอทตอบต่อ
 *  - แอดมินเงียบเกิน N นาที (ตั้งได้ · 0 = ไม่คืนเอง) = คืนให้บอทเอง ลูกค้าไม่ค้างรอคนที่ลุกไปแล้ว
 *  - ระหว่างแอดมินคุม ข้อความของลูกค้าเก็บลงห้อง ไม่ถาม AI ไม่คิดเงิน ไม่กินโควตาเวลาถามต่อ
 *  - คำตอบของบอทที่กำลังมาตอนแอดมินกดเทค = ทิ้ง (ไม่บันทึก ไม่คิดเงิน) — ผู้เรียกเช็คซ้ำหลังได้คำตอบ
 *  - คืนให้บอทแล้ว คำตอบแรกของบอทได้สรุปสิ่งที่แอดมินคุยไป (handback) จะได้ไม่ขัดกับที่แอดมินรับปากไว้
 */
class ChatTakeover
{
    /** เงียบเกินกี่นาทีแล้วคืนให้บอทเอง (ค่าเริ่มต้น) */
    public const DEFAULT_IDLE_MINUTES = 30;

    /** ลูกค้ายังเปิดห้องอยู่ถ้าหน้าห้องเพิ่งถามข้อความใหม่ภายในกี่วินาที */
    public const LIVE_SECONDS = 45;

    /** ตัวเลือกเวลาคืนบอทเองในหลังบ้าน (นาที · 0 = ไม่คืนเอง) */
    public const IDLE_CHOICES = [10, 30, 60, 180, 0];

    public static function idleMinutes(): int
    {
        $v = Setting::get('chat_takeover_idle_minutes');

        return is_numeric($v) ? max(0, (int) $v) : self::DEFAULT_IDLE_MINUTES;
    }

    public static function setIdleMinutes(int $minutes): void
    {
        Setting::put('chat_takeover_idle_minutes', (string) max(0, min(1440, $minutes)), 'chat');
    }

    /**
     * แอดมินคุมห้องนี้อยู่ไหม — อ่านจากฐานข้อมูลเสมอ (ห้ามเชื่อค่าในอ็อบเจกต์ที่โหลดไว้ก่อนถาม AI)
     *
     * เงียบเกินเวลาที่ตั้ง = คืนให้บอทตรงนี้เลย (ไม่ต้องมี cron)
     */
    public static function isPaused(ChatConversation $conversation): bool
    {
        $row = ChatConversation::query()->whereKey($conversation->id)->first(['id', 'bot_paused_at', 'bot_paused_by']);
        if ($row === null || $row->bot_paused_at === null) {
            self::sync($conversation, null, null);

            return false;
        }

        $idle = self::idleMinutes();
        if ($idle > 0 && $row->bot_paused_at->lt(now()->subMinutes($idle))) {
            // ล้างแบบมีเงื่อนไข — แอดมินที่เพิ่งพิมพ์พร้อมกันไม่ถูกทับ
            ChatConversation::query()->whereKey($row->id)
                ->where('bot_paused_at', $row->bot_paused_at)
                ->update(['bot_paused_at' => null, 'bot_paused_by' => null]);
            self::sync($conversation, null, null);

            return false;
        }

        self::sync($conversation, $row->bot_paused_at, $row->bot_paused_by);

        return true;
    }

    /** ปุ่ม "เทคบอท" — บอทหยุด (กดซ้ำ = ขยับเวลาเงียบใหม่) */
    public static function pause(ChatConversation $conversation, User $admin): void
    {
        $now = now();
        ChatConversation::query()->whereKey($conversation->id)
            ->update(['bot_paused_at' => $now, 'bot_paused_by' => $admin->id]);
        self::sync($conversation, $now, $admin->id);
    }

    /** ปุ่ม "คืนให้บอท" — บอทตอบข้อความถัดไปของลูกค้าเอง */
    public static function release(ChatConversation $conversation): void
    {
        ChatConversation::query()->whereKey($conversation->id)
            ->update(['bot_paused_at' => null, 'bot_paused_by' => null]);
        self::sync($conversation, null, null);
    }

    /**
     * แอดมินพิมพ์ในห้อง — บันทึก + บอทหยุดทันที
     *
     * @param  string  $as  oracle = ลูกค้าเห็นเป็นผู้พยากรณ์ของห้องพูด (ปุ่ม "คุยแทนแม่หมอ") · admin = ป้ายแอดมิน
     */
    public static function say(ChatConversation $conversation, User $admin, string $text, string $as = ChatMessage::AS_ORACLE): ChatMessage
    {
        $as = $as === ChatMessage::AS_ADMIN ? ChatMessage::AS_ADMIN : ChatMessage::AS_ORACLE;

        // หยุดบอทก่อนบันทึก — คำตอบของบอทที่กำลังมาเห็นสถานะหยุดแล้วทิ้งตัวเอง
        self::pause($conversation, $admin);

        $message = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role'     => 'assistant',
            'content'  => $text,
            'admin_id' => $admin->id,
            'admin_as' => $as,
        ]);
        $conversation->touch();
        self::markRead($conversation);

        return $message;
    }

    public static function markRead(ChatConversation $conversation): void
    {
        $now = now();
        ChatConversation::query()->whereKey($conversation->id)->update(['admin_read_at' => $now]);
        $conversation->admin_read_at = $now;
    }

    /** หน้าห้องของลูกค้าถามหาข้อความใหม่ = ลูกค้ายังเปิดห้องอยู่ (หลังบ้านโชว์ "ออนไลน์") */
    public static function touchSeen(ChatConversation $conversation): void
    {
        Cache::put(self::seenKey($conversation->id), now()->getTimestamp(), self::LIVE_SECONDS * 2);
    }

    public static function isLive(int $conversationId): bool
    {
        $at = Cache::get(self::seenKey($conversationId));

        return is_numeric($at) && now()->getTimestamp() - (int) $at <= self::LIVE_SECONDS;
    }

    /** สถานะสำหรับหน้าจอ (หลังบ้าน) */
    public static function state(ChatConversation $conversation): array
    {
        $paused = self::isPaused($conversation);
        $idle   = self::idleMinutes();
        $by     = $paused && $conversation->bot_paused_by ? User::query()->whereKey($conversation->bot_paused_by)->value('name') : null;

        return [
            'paused'       => $paused,
            'by'           => $by,
            'since'        => $paused ? $conversation->bot_paused_at?->getTimestamp() : null,
            // คืนให้บอทเองตอนไหน (epoch) — null = ไม่คืนเอง/บอทตอบอยู่
            'resumes_at'   => $paused && $idle > 0 ? $conversation->bot_paused_at?->copy()->addMinutes($idle)->getTimestamp() : null,
            'idle_minutes' => $idle,
            'live'         => self::isLive($conversation->id),
        ];
    }

    /**
     * 🔁 บอทกลับมาตอบหลังแอดมินคุยไป — สรุปบทสนทนาช่วงที่แอดมินคุยไว้ใส่บริบทของผู้พยากรณ์
     *
     * ไม่ต้องมีสถานะเพิ่ม: ข้อความหลังคำตอบล่าสุดของบอทที่มีของแอดมินปน = เพิ่งคืนจากแอดมิน
     * บอทตอบไปหนึ่งครั้งแล้ว ข้อความชุดนั้นอยู่ก่อนคำตอบล่าสุดของบอท = ไม่ส่งซ้ำ
     */
    public static function handbackNote(ChatConversation $conversation, int $maxChars = 1500): ?string
    {
        $lastBotId = ChatMessage::query()
            ->where('chat_conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->whereNull('admin_as')
            ->max('id') ?? 0;

        $since = ChatMessage::query()
            ->where('chat_conversation_id', $conversation->id)
            ->where('id', '>', $lastBotId)
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'role', 'content', 'admin_as'])
            ->reverse()
            ->values();

        if (! $since->contains(fn (ChatMessage $m) => $m->fromAdmin())) {
            return null;
        }

        $lines = $since->map(fn (ChatMessage $m) => ($m->fromAdmin() ? 'ทีมงาน' : 'ลูกค้า') . ': '
            . preg_replace('/\s+/u', ' ', mb_substr(trim((string) $m->content), 0, 300)))->all();

        $head = "[ช่วงก่อนหน้านี้ ทีมงานตัวจริงคุยกับลูกค้าแทนคุณ — คุยต่อให้สอดคล้อง ห้ามขัดหรือย้อนสิ่งที่ทีมงานบอก/รับปากไว้ และไม่ต้องพูดถึงทีมงาน]\n";
        $body = implode("\n", $lines);

        return mb_substr($head . $body, 0, $maxChars);
    }

    /**
     * ข้อความหนึ่งข้อความสำหรับหน้าห้อง
     *
     * @param  bool  $forAdmin  หลังบ้าน = เห็นทุกข้อความของแอดมินแยกชัด · ลูกค้า = "คุยแทนแม่หมอ" ดูเป็นผู้พยากรณ์พูด
     */
    public static function payload(ChatMessage $m, bool $forAdmin = false): array
    {
        $role = match (true) {
            $m->role === 'user' => 'user',
            $m->fromAdmin() && ($forAdmin || $m->admin_as === ChatMessage::AS_ADMIN) => 'admin',
            default => 'assistant',
        };

        return [
            'mid'      => $m->id,
            'role'     => $role,
            'admin_as' => $forAdmin ? $m->admin_as : null,
            'author'   => $forAdmin && $m->fromAdmin() ? (optional($m->admin)->name ?: 'แอดมิน') : null,
            'html'     => Markdown::safe((string) $m->content),
            'text'     => (string) $m->content,
            'time'     => optional($m->created_at)->format('H:i'),
            'state'    => 'ok',
            'offers'   => [],
            'question' => null,
        ];
    }

    private static function seenKey(int $conversationId): string
    {
        return "chat:seen:c{$conversationId}";
    }

    /** ให้อ็อบเจกต์ที่ผู้เรียกถืออยู่ตรงกับฐานข้อมูล (ไม่ต้อง refresh ทั้งแถว) */
    private static function sync(ChatConversation $conversation, $at, $by): void
    {
        $conversation->bot_paused_at = $at;
        $conversation->bot_paused_by = $by;
        $conversation->syncOriginalAttributes(['bot_paused_at', 'bot_paused_by']);
    }
}
