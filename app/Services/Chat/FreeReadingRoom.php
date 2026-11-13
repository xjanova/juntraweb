<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\User;
use App\Support\ChatRooms;
use App\Support\FreeAskClock;
use App\Support\TarotSpreads;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 🎁 ห้องผู้พยากรณ์ที่เปิดต่อจากไพ่ฟรี 1 ใบ — ที่เดียวของเว็บ (/chat/from-free) และแอพ (POST /v1/tarot/free)
 *
 * เจ้าของสั่ง (2026-11-09): "หลังจากดูฟรี ควรรีบพาไปปิดการขายเพิ่ม … ให้ไปหน้าแชทเพื่อเริ่มปิดการขาย ชวนคุยต่อ ทันที"
 *   1. คำทำนายฟรีเป็นข้อความแรกของห้อง (ลูกค้าอ่านผลในห้องเดียวกับที่คุยต่อ)
 *   2. ผู้พยากรณ์ชวนคุยต่อ + การ์ดแพ็กเกจเปิดไพ่ (offer_topic) — บิลออกเมื่อลูกค้ากดจ่ายเองเท่านั้น
 *   3. ห้องไม่ผูก reading_id (ห้องผูกไพ่ซ่อนแพ็กเกจ) — จำไพ่ฟรีไว้ที่ free_reading_id → นาฬิกาถามฟรี (FreeAskClock)
 * เปิดซ้ำ (รีเฟรช / กดลิงก์เดิม / แอพส่งซ้ำ) = ห้องเดิม ไม่วางข้อความซ้ำ
 */
final class FreeReadingRoom
{
    /** ไพ่ใบนี้เปิดห้องต่อได้ไหม — ไพ่ฟรีของตัวเองที่มีคำทำนายแล้ว */
    public static function usable(Reading $reading): bool
    {
        return (bool) data_get($reading->payload, 'free')
            && trim((string) $reading->result) !== ''
            && ReadingChatContext::usable($reading);
    }

    /**
     * ห้องของไพ่ฟรีใบนี้ — มีแล้วคืนห้องเดิม · ยังไม่มีสร้างพร้อมคำทำนาย + คำชวนต่อ
     *
     * @throws LockTimeoutException อีกคำขอถือล็อกนานผิดปกติ (ผู้เรียกพาไปหน้าผลไพ่/ตอบลองใหม่)
     */
    public static function open(User $user, Reading $reading): ChatConversation
    {
        $room = ChatRooms::roomForSpread(TarotSpreads::keyFromType((string) $reading->type));
        $key = self::roomKey($reading->id);

        return Cache::lock($key.':lock', 10)->block(5, function () use ($user, $reading, $room, $key) {
            // 🎁 (2026-11-11) หาห้องจากคอลัมน์ก่อน (cache ถูกล้างได้) · ห้องเก่าที่เจอจาก cache = ติดป้ายไพ่ฟรีให้
            $known = ChatConversation::where('user_id', $user->id)->where('free_reading_id', $reading->id)->latest('id')->first()
                ?? ChatConversation::where('id', (int) Cache::get($key))->where('user_id', $user->id)->first();
            if ($known) {
                if ($known->free_reading_id === null && $known->reading_id === null) {
                    $known->update(['free_reading_id' => $reading->id]);
                }

                return $known;
            }

            $conversation = ChatConversation::create([
                'session_token' => (string) Str::uuid(),
                'user_id'       => $user->id,
                'title'         => 'สนทนากับ' . ChatRooms::name($room),
                'persona'       => $room,
                // ⏳ นาฬิกาถามฟรี 2 นาที + ป้ายไพ่หมวดอื่น (App\Support\FreeAskClock)
                'free_reading_id' => $reading->id,
            ]);
            ChatMessage::create([
                'chat_conversation_id' => $conversation->id,
                'role'    => 'assistant',
                'content' => trim((string) $reading->result),
            ]);
            ChatMessage::create([
                'chat_conversation_id' => $conversation->id,
                'role'        => 'assistant',
                'content'     => self::followUp($room),
                'offer_topic' => 'general',
            ]);

            // 🎁 (2026-11-11) ไม่วางปุ่ม "ถามต่อจากเรื่องนี้" แล้ว — ห้องนี้ใช้ป้ายไพ่หมวดอื่นแทน (FreeAskClock::picks)
            //    เจ้าของ: "ปุ่มคำถามต่อจากเรื่องนี้ ควรขึ้นไพ่หมวดอื่นให้เลือก"
            // ผู้พยากรณ์ต้องรู้ว่าลูกค้าเพิ่งได้ไพ่ใบไหน — ใช้ตอนเปิดห้อง Thaiprompt ครั้งแรก (ChatTurn::openUpstreamSession)
            Cache::put(self::seedContextKey($conversation->id), ReadingChatContext::for($reading), now()->addHours(6));
            Cache::put($key, $conversation->id, now()->addDays(30));

            return $conversation;
        });
    }

    /** บริบทไพ่ฟรีที่จำไว้ให้การเปิดห้อง Thaiprompt ครั้งแรกของห้องนี้ (null = ไม่ใช่ห้องไพ่ฟรี/หมดอายุ) */
    public static function seedContext(int $conversationId): ?string
    {
        $v = Cache::get(self::seedContextKey($conversationId));

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** คำชวนต่อหลังคำทำนายฟรี — ประกอบในเครื่อง ไม่ถาม AI (ไม่เสียรอบ ไม่กินโควตาคุยฟรี) */
    public static function followUp(string $room): string
    {
        $self = ChatRooms::selfName($room);
        $customer = ChatRooms::customer($room);

        $mins = FreeAskClock::minutes();

        return "ไพ่ใบเดียวบอกได้แค่ภาพรวมนะคะ 🌙 {$customer}ถามต่อจากไพ่ใบนี้ได้ฟรีอีก {$mins} นาที "
            ."ถ้าอยากรู้ลึกว่าเรื่องนี้จะไปทางไหน เลือกเรื่องที่อยากให้{$self}เปิดไพ่ชุดเต็มด้านล่างได้เลยค่ะ ไม่กดดันนะคะ";
    }

    private static function roomKey(int $readingId): string
    {
        return "chat:free-room:{$readingId}";
    }

    private static function seedContextKey(int $conversationId): string
    {
        return "chat:seed-ctx:{$conversationId}";
    }
}
