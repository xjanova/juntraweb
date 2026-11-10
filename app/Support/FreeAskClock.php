<?php

namespace App\Support;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\User;
use App\Services\Chat\ChatOffers;
use App\Services\Chat\ChatReadingIntent;
use App\Services\Wallet\WalletService;

/**
 * 🎁 นาฬิกาถามฟรีหลังดูไพ่ฟรี 1 ใบ (เจ้าของสั่ง 2026-11-11)
 *
 * "การถามฟรี เวลาควรไม่เกิน 2 นาที และปุ่มคำถามต่อจากเรื่องนี้ ควรขึ้นไพ่หมวดอื่นให้เลือกอัตโนมัติ
 *  หรือขึ้นป้ายให้เลือกดูไพ่อย่างอื่นไปแต่แชท ก็เหมือนแชทผ่านเฟซบุ๊ก ซึ่งจะเริ่มให้ลองเติมเงินเพื่อรับคำทำนายเจาะลึกเนียน ๆ แทน"
 *
 * ต่างจาก ReadingQaClock (ไพ่ที่จ่ายแล้ว) ตรงนี้:
 *   - ห้องไม่ผูก reading_id (ห้องผูกไพ่ซ่อนแพ็กเกจ) — จำไพ่ฟรีไว้ที่ free_reading_id แทน
 *   - ระหว่างถามฟรี ป้ายไพ่หมวดอื่นอยู่ในห้องตลอด (ไม่ซ่อนแบบห้องไพ่ที่จ่ายแล้ว)
 *   - หมดเวลาแล้วยังพิมพ์ได้ แต่ผู้พยากรณ์ไม่ทำนายต่อฟรี — ตอบด้วยคำชวนนุ่ม ๆ + การ์ดไพ่ชุดเต็ม (ไม่ถาม AI ไม่คิดเงิน)
 *     แบบเดียวกับบอทเพจ FB ที่หมดรอบถามฟรีแล้วชวนเปิดไพ่ชุดเต็ม
 * นาฬิกาเริ่มที่คำถามแรกของลูกค้า (qa_started_at ชุดเดิม) · เวลาเป็นของเซิร์ฟเวอร์ หน้าเว็บนับตาม
 */
final class FreeAskClock
{
    /** แบบไพ่ที่ยื่นให้หลังดูฟรี (เรียงตามที่ชวนก่อน) + ชื่อหมวดสั้น ๆ ที่ลูกค้าเข้าใจทันที */
    private const PICKS = [
        'love' => ['icon' => '💗', 'topic' => 'ความรัก เนื้อคู่'],
        'career' => ['icon' => '💼', 'topic' => 'การงาน การเงิน'],
        'decision' => ['icon' => '🔀', 'topic' => 'ทางแยก ตัดสินใจ'],
        'three' => ['icon' => '⏳', 'topic' => 'อดีต ปัจจุบัน อนาคต'],
        'celtic' => ['icon' => '🌟', 'topic' => 'เจาะลึกทุกด้าน'],
        'year' => ['icon' => '📅', 'topic' => 'ดวง 12 เดือน'],
    ];

    /** นาทีถามฟรี — เจ้าของกำหนดไม่เกิน 2 นาที (env ปรับลงได้ ปรับขึ้นเกิน 2 ไม่ได้) */
    public static function minutes(): int
    {
        return max(1, min(2, (int) config('reading_qa.free_minutes', 2)));
    }

    /** ไพ่ฟรีของห้องนี้ — เฉพาะไพ่ฟรีของเจ้าของห้องเอง */
    public static function readingOf(ChatConversation $conversation): ?Reading
    {
        if (! $conversation->free_reading_id || ! $conversation->user_id) {
            return null;
        }
        $reading = Reading::find($conversation->free_reading_id);

        return $reading
            && (int) $reading->user_id === (int) $conversation->user_id
            && data_get($reading->payload, 'free')
            && trim((string) $reading->result) !== ''
            ? $reading : null;
    }

    /**
     * สถานะนาฬิกา — null ถ้าไม่ใช่ห้องที่เริ่มจากไพ่ฟรี
     *
     * @return array{minutes:int,started:bool,ends_at:?int,over:bool,left:int}|null
     */
    public static function state(ChatConversation $conversation): ?array
    {
        if (! $conversation->free_reading_id) {
            return null;
        }
        $minutes = self::minutes();
        $started = $conversation->qa_started_at;
        $endsAt = $started ? $started->copy()->addMinutes($minutes) : null;
        $left = $endsAt ? max(0, (int) now()->diffInSeconds($endsAt, false)) : $minutes * 60;
        $over = $conversation->qa_ended_at !== null || ($endsAt !== null && $left <= 0);

        return [
            'minutes' => $minutes,
            'started' => $started !== null,
            'ends_at' => $endsAt?->getTimestamp(),
            'over' => $over,
            'left' => $over ? 0 : $left,
        ];
    }

    /** คำถามแรก → เริ่มนับ (ครั้งเดียว — สองแท็บส่งพร้อมกันได้เวลาเดียวกัน) */
    public static function startIfNeeded(ChatConversation $conversation): void
    {
        if ($conversation->qa_started_at !== null || ! $conversation->free_reading_id) {
            return;
        }
        ChatConversation::whereKey($conversation->id)->whereNull('qa_started_at')->update(['qa_started_at' => now()]);
        $conversation->refresh();
    }

    /** คำถามแรกไม่ได้คำตอบ (ระบบล่ม/แอดมินรับไป) → ยังไม่เริ่มนับ ลูกค้าไม่เสียเวลาฟรี */
    public static function unstart(ChatConversation $conversation): void
    {
        ChatConversation::whereKey($conversation->id)->update(['qa_started_at' => null]);
        $conversation->refresh();
    }

    /**
     * ป้ายไพ่หมวดอื่น — แทนปุ่ม "ถามต่อจากเรื่องนี้" ในห้องของไพ่ฟรี
     *
     * การ์ดชุดเดียวกับโต๊ะในห้อง (ChatOffers::roomMenu — ราคา/สวิตช์ขาย/ข้อห้ามเปิดซ้ำตรงกันเสมอ)
     * ไม่เอาไพ่ใบเดียว (เพิ่งได้ไปฟรี) และไม่เอาแบบที่ติดข้อห้ามเปิดซ้ำ
     *
     * @return list<array<string,mixed>>
     */
    public static function picks(string $room, ?User $user): array
    {
        $menu = [];
        foreach (ChatOffers::roomMenu($room, $user) as $o) {
            if (($o['locked_until'] ?? null) !== null || empty($o['spread'])) {
                continue;
            }
            $menu[$o['spread']] = $o;
        }
        $out = [];
        foreach (self::PICKS as $spread => $meta) {
            if (isset($menu[$spread])) {
                $out[] = $menu[$spread] + ['pick_icon' => $meta['icon'], 'pick_topic' => $meta['topic']];
            }
        }

        return $out;
    }

    /** หมวดของคำถาม → แบบไพ่ที่ชวนก่อน (การ์ดเดียว — ตัวเลือกมากไปคือไม่เลือก) */
    public static function spreadForTopic(string $topic): string
    {
        return match ($topic) {
            'love' => 'love',
            'career', 'money' => 'career',
            default => 'celtic',
        };
    }

    /**
     * การ์ดที่ยื่นในห้องของไพ่ฟรี (ขอให้ดูดวง / พิมพ์มาหลังหมดเวลา) — แบบที่ตรงเรื่อง + ชุดเจาะลึก (ไม่เกิน 2 ใบ)
     *
     * ไม่ใช้ ChatOffers::forRoom — ห้องแม่หมอยื่นได้แค่แพ็กเกจประจำห้อง (Celtic) ใบเดียว
     * คนที่ถามเรื่องความรักหลังดูฟรีควรเห็นไพ่ความรักก่อน
     *
     * @return list<array<string,mixed>>
     */
    public static function offersFor(string $room, ?User $user, ?string $topic): array
    {
        $picks = [];
        foreach (self::picks($room, $user) as $o) {
            $picks[$o['spread']] = $o;
        }
        $first = self::spreadForTopic(ChatReadingIntent::normalizeTopic($topic));
        $out = [];
        foreach (array_unique([$first, 'celtic']) as $spread) {
            if (isset($picks[$spread])) {
                $out[] = $picks[$spread];
            }
        }

        return $out !== [] ? $out : array_slice(array_values($picks), 0, 2);
    }

    /** หมวดของข้อความลูกค้า (love|career|money|health|kunsai|general) */
    public static function topicOf(string $message): string
    {
        return ChatReadingIntent::topicOf(mb_strtolower($message));
    }

    /**
     * บรรทัดเวลาที่แนบหน้าบริบทไพ่ฟรี — ผู้พยากรณ์รู้ว่าเป็นช่วงถามฟรีสั้น ๆ และเหลือเวลาเท่าไหร่
     */
    public static function contextLine(?array $state): ?string
    {
        if ($state === null) {
            return null;
        }
        $secs = max(0, (int) $state['left']);
        $line = "🎁 ช่วงถามฟรีหลังไพ่ฟรี 1 ใบ: เหลืออีกราว {$secs} วินาที จากทั้งหมด {$state['minutes']} นาที"
            ."\n- ตอบสั้น กระชับ อุ่นใจ จากไพ่ใบที่เปิดไว้เท่านั้น ไม่เปิดไพ่ใบใหม่ให้ฟรี"
            ."\n- ไพ่ใบเดียวเห็นแค่ภาพรวม ถ้าคำถามต้องดูลึก (ช่วงเวลา คนรอบตัว ทางเลือก) บอกเบา ๆ ว่าไพ่ชุดเต็มจะเห็นชัดกว่า"
            .' — ชวนแค่ประโยคเดียว ไม่เร่ง ไม่พูดเรื่องราคา';

        return $line;
    }

    /** เหลือราว 1 นาที — ผู้พยากรณ์บอกเอง (หน้าเว็บประกอบ ไม่ถาม AI) */
    public static function warnText(?string $persona): string
    {
        $self = ChatRooms::selfName($persona);

        return "อีกราว 1 นาทีจะหมดเวลาถามฟรีแล้วนะคะ ⏳ ถามข้อที่ค้างใจที่สุดมาได้เลย {$self}ตอบให้ค่ะ";
    }

    /**
     * หมดเวลาถามฟรี — ชวนเปิดไพ่ชุดเต็มแบบนุ่ม ๆ (ประกอบในเครื่อง ไม่ถาม AI ไม่คิดเงิน)
     * ไม่บอกราคาในประโยค — ราคาอยู่บนการ์ด ลูกค้าเลือกเอง
     */
    public static function overText(?string $persona): string
    {
        $self = ChatRooms::selfName($persona);
        $customer = ChatRooms::customer($persona);

        return "ครบเวลาถามฟรีของไพ่ใบนี้แล้วนะคะ ขอบคุณที่ให้{$self}ได้ดูให้ค่ะ 🌙 "
            ."ไพ่ใบเดียวเห็นได้แค่ภาพรวม ถ้า{$customer}อยากรู้ให้ชัดว่าเรื่องนี้จะไปทางไหน เมื่อไหร่ และควรทำอย่างไร "
            ."{$self}เปิดไพ่ชุดเต็มอ่านให้ละเอียดได้เลยค่ะ เลือกเรื่องที่อยากรู้ด้านล่างได้เลยนะคะ ✨";
    }

    /**
     * ลูกค้าพิมพ์มาหลังหมดเวลาถามฟรี — ไม่ทำนายต่อฟรี แต่ตอบรับเรื่องที่ถาม แล้วชวนเปิดไพ่ที่ตรงเรื่อง
     * (แบบบอทเพจ FB: ไม่ตัดบท ไม่ปฏิเสธแข็ง ๆ)
     */
    public static function afterText(?string $persona, ?User $user): string
    {
        $self = ChatRooms::selfName($persona);
        $customer = ChatRooms::customer($persona);
        $tail = $user !== null && (float) app(WalletService::class)->balance($user) <= 0
            ? ' เติมเครดิตในห้องนี้ได้เลย สแกนจ่ายเสร็จ เปิดไพ่ต่อได้ทันทีค่ะ'
            : '';

        return "เรื่องนี้{$self}อยากดูให้ละเอียดจริง ๆ ค่ะ 🌙 แต่เกินกว่าที่ไพ่ใบเดียวจะตอบได้ชัดแล้ว "
            ."ให้{$self}เปิดไพ่ชุดเต็มดูให้นะคะ จะได้เห็นทั้งที่มา ทางออก และช่วงเวลาที่ควรระวัง "
            ."{$customer}เลือกแบบที่ตรงใจด้านล่างได้เลยค่ะ ✨{$tail}";
    }
}
