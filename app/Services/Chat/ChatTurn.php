<?php

namespace App\Services\Chat;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\ChatTopupController;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Services\AiOracle;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Services\Wallet\WalletService;
use App\Support\AppChannel;
use App\Support\ChatPolicy;
use App\Support\ChatRooms;
use App\Support\ChatSuggestions;
use App\Support\ChatTakeover;
use App\Support\FreeAskClock;
use App\Support\ReadingQaClock;
use App\Support\ReadingRoomLock;
use App\Support\ReadingTheater;
use App\Support\SiteHelp;
use App\Support\VoicePremium;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 💬 หนึ่งรอบสนทนาในห้องผู้พยากรณ์ — ที่เดียวของหน้าเว็บ (/chat/send) และแอพ (POST /v1/chat/conversations/{id}/send)
 *
 * 🔮 (2026-11-12) แยกออกจาก ChatController ของเว็บ: แอพเคยมีขั้นตอนของตัวเองที่ตามเว็บไม่ทัน
 *   (ไม่มีนาฬิกาถามต่อ ไม่มีถามฟรีหลังไพ่ฟรี ถามวิธีใช้เว็บโดนคิดเงิน) — กติกาที่ก๊อปไว้สองที่ drift เสมอ
 *   ตอนนี้ทั้งสองทางเรียกเมธอดเดียวกัน ต่างกันแค่หน้าตาคำตอบ (ผู้เรียกจัดเอง)
 *
 * ผู้เรียกผ่านด่านก่อนส่งมาเองแล้ว (ล็อกอิน · ห้องเปิด · แอดมินคุมห้อง · กันส่งซ้ำ · โควตา · เครดิต · ทีละข้อความ)
 * แล้วส่ง $release มาให้ปล่อยล็อกกันตัดเงินซ้ำเมื่อรอบนี้ไม่สำเร็จ/ไม่คิดเงิน
 */
final class ChatTurn
{
    /**
     * งบเวลาทั้งคำขอส่งข้อความ (วินาที) — Apache ตัดที่ ~60 วิ ถ้าเกินนั้นลูกค้าเห็น error
     * ทั้งที่ PHP ยังทำต่อและบันทึกคำตอบที่ลูกค้าไม่เคยเห็น แล้วลูกค้ากดส่งซ้ำ = คำถามซ้ำ
     */
    public const BUDGET_SEC = 52;

    /** เสียงคำตอบห้องถามต่อยาวสุดต่อข้อ แม้ตั้งไม่อั้น — /voice/speak ของ Thaiprompt รับไม่เกิน 6,000 ตัว */
    private const SPEAK_HARD_MAX = 5000;

    public function __construct(
        private AiOracle $oracle,
        private WalletService $wallet,
        private MaeMorUpstream $upstream,
    ) {}

    /** คีย์ cache ปุ่มจาก AI ชุดล่าสุดของห้อง */
    public static function askKey(int $conversationId): string
    {
        return "chat:ask:{$conversationId}";
    }

    /**
     * ⏳ "พอแล้ว" — จบเวลาถามต่อของไพ่ชุดนี้ก่อนกำหนด (หน้าเว็บ /chat/qa/end + แอพ …/qa/end)
     *
     * คำลาประกอบในเครื่อง ไม่ถาม AI ไม่คิดเงิน · กดซ้ำ = null (ไม่มีคำลาซ้ำ)
     */
    public static function endQa(ChatConversation $conversation): ?ChatMessage
    {
        if (! ReadingQaClock::end($conversation)) {
            return null;
        }

        return ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role'    => 'assistant',
            'content' => ReadingQaClock::farewell($conversation->persona),
        ]);
    }

    /**
     * ราคาของข้อความนี้ — อ่านก่อนบันทึกข้อความของลูกค้า (ไม่งั้น dailyUsed() นับข้อความนี้รวมด้วย)
     *
     * @return array{cost: float, help: bool}
     */
    public static function costFor($user, ChatConversation $conversation, string $message): array
    {
        // 🎁 (2026-07-28) ราคาต่อคน — 0 ระหว่างที่ยังอยู่ในโควตาฟรีของวันนี้
        $cost = ChatPolicy::costFor($user);

        // 🧭 (2026-11-10) ถามวิธีใช้เว็บ/แจ้งปัญหา (เติมเงิน บิล ย้อนดู เงินไม่เข้า) = ไม่คิดเงิน ไม่ติดเพดานคุยฟรี
        //    ลูกค้าที่ติดปัญหาการจ่ายเงินต้องถามได้เสมอ — ไม่ใช่โดน "เครดิตไม่พอ" ปิดปากตอนถามว่าเติมเงินยังไง
        $help = SiteHelp::isHelp($message);
        if ($help) {
            $cost = 0.0;
        }
        // 🎁 (2026-11-11) ห้องของไพ่ฟรี = "ถามฟรี" จริง ๆ แม้แอดมินตั้งราคาแชทไว้ · หมดเวลาแล้วคือคำชวน + การ์ด (ไม่ใช่คำตอบ)
        //    เคสที่ลองจริง: ตั้งแชท ฿2 ลูกค้าเครดิต 0 พิมพ์หลังหมดเวลา → ได้ "เครดิตไม่พอ" แทนคำชวนเปิดไพ่
        //    เวลาถูกจำกัดที่ FreeAskClock แล้ว (ไม่เกิน 2 นาที) จึงไม่ใช่ช่องคุยฟรีไม่จำกัด
        if ($conversation->free_reading_id !== null && $conversation->reading_id === null) {
            $cost = 0.0;
        }

        return ['cost' => $cost, 'help' => $help];
    }

    /**
     * ส่งข้อความที่ผ่านทุกด่านแล้ว — บันทึก → ถามผู้พยากรณ์ → หักเงิน → บันทึกคำตอบ
     *
     * status:
     *   qa_over        หมดเวลาถามต่อ/กด "พอแล้ว" แล้ว — ไม่ส่ง ไม่ถาม AI ไม่คิดเงิน (message, qa)
     *   free_over      หมดเวลาถามฟรี — คำชวน + การ์ด ไม่ถาม AI ไม่คิดเงิน (reply, offers, free_ask, question, assistant)
     *   held           แอดมินเทคห้องระหว่างที่ AI คิด — คำตอบทิ้ง ไม่คิดเงิน
     *   persist_failed บันทึกคำตอบไม่ได้ (คืนเงินแล้ว) (message)
     *   ok             คำตอบปกติ (reply, voice_url, kind, offers, degraded, qa, free_ask, ai_suggestions, awaiting, question, assistant)
     *
     * @param  Closure():void  $release  ปล่อยล็อกกันตัดเงินซ้ำ (รอบนี้ไม่สำเร็จ/ไม่คิดเงิน ลูกค้าส่งใหม่ด้วยคีย์เดิมได้ทันที)
     * @param  bool  $speak  พูดคำตอบออกเสียงห้องถามต่อของพรีเมียมไหม (หน้าเว็บแบบฟอร์มธรรมดาไม่ต้อง)
     */
    public function deliver(Request $request, $user, ChatConversation $conversation, string $message, float $cost, float $t0, Closure $release, bool $speak = true): array
    {
        $room = ChatRooms::normalize($conversation->persona);

        // ⏳ ห้องที่คุยต่อจากไพ่: หมดเวลาถามต่อ/กด "พอแล้ว" แล้ว = ไม่ส่ง ไม่ถาม AI ไม่คิดเงิน
        $reading = ReadingChatContext::readingOf($conversation);
        $qa = ReadingQaClock::state($conversation, $reading);
        if ($qa !== null && $qa['over']) {
            $release();

            return ['status' => 'qa_over', 'message' => ReadingQaClock::overMessage($conversation->persona), 'qa' => $qa];
        }
        // 🎁 (2026-11-11) ห้องของไพ่ฟรี: หมดเวลาถามฟรีแล้ว = ไม่ทำนายต่อฟรี ตอบคำชวนนุ่ม ๆ + การ์ดไพ่ชุดเต็ม
        //    (ไม่ถาม AI ไม่คิดเงิน ไม่ปิดช่องพิมพ์ — แบบบอทเพจ FB) · ถามวิธีใช้เว็บ/จ่ายเงินยังได้คำตอบปกติ
        $free = $reading === null ? FreeAskClock::state($conversation) : null;
        if ($free !== null && $free['over'] && ! SiteHelp::isHelp($message)) {
            $answered = $this->freeAskOver($request, $user, $conversation, $message, $free, $release);
            if ($answered !== null) {
                return $answered;
            }
        }
        // คำถามแรก = เริ่มนับ (นับจากตอนลูกค้าถาม ไม่ใช่ตอนเปิดห้อง)
        $qaJustStarted = ($qa !== null && ! $qa['started']) || ($free !== null && ! $free['started']);
        if ($qa !== null) {
            ReadingQaClock::startIfNeeded($conversation);
        }
        if ($free !== null) {
            FreeAskClock::startIfNeeded($conversation);
        }

        $userMessage = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role'    => 'user',
            'content' => $message,
        ]);

        $dispatch = $this->dispatchToUpstream($user, $conversation, $message, $t0);

        // 🧑‍💼 แอดมินกดเทคบอท/พิมพ์เข้ามาระหว่างที่ AI กำลังคิด — คำตอบนี้ทิ้ง ไม่บันทึก ไม่คิดเงิน
        //    ข้อความของลูกค้ายังอยู่ในห้อง (แอดมินเห็นและตอบเอง) · ยังไม่เริ่มนับเวลาถามต่อ
        if (ChatTakeover::isPaused($conversation)) {
            $release();
            $userMessage->update(['admin_as' => ChatMessage::HELD]);
            if ($qaJustStarted) {
                FreeAskClock::unstart($conversation);
            }

            return ['status' => 'held'];
        }

        $reply    = $dispatch['reply'];
        $degraded = $dispatch['degraded'] ?? false;
        $kind     = $dispatch['kind'] ?? 'reply';
        $offers = $kind !== 'offer' ? []
            : ($free !== null
                ? FreeAskClock::offersFor((string) $conversation->persona, $user, (string) ($dispatch['offer_topic'] ?? 'general'))
                : ChatOffers::forRoom($conversation->persona, (string) ($dispatch['offer_topic'] ?? 'general'), $user));
        // 🔒 (2026-11-05) กำลังทำนายอยู่ (สับไพ่/อ่านไพ่) หรือมีบิลเติมเงินค้าง — ไม่ยื่นการ์ดเปิดไพ่ใบใหม่ซ้อน
        //    เจ้าของสั่ง "ถ้าทำนายอยู่ปุ่มดูดวงไม่ควรโผล่มา" + "ป้ายเปิดไพ่ต้องไม่ขึ้นถ้ายังค้างบิลอยู่"
        if ($offers !== [] && ($hold = $this->offerHold($request, $user, $conversation->persona, $qa, $offers)) !== null) {
            $offers = [];
            $reply  = $hold;
        }
        // ✨ ปุ่มที่ผู้พยากรณ์คิดมาให้จากบทสนทนา — การ์ดเปิดไพ่ขึ้นอยู่แล้วไม่ต้องมีปุ่มแย่งความสนใจ
        $aiSuggestions = ! $degraded && $offers === [] ? (array) ($dispatch['suggestions'] ?? []) : [];
        if ($aiSuggestions !== [] && (ReadingRoomLock::active($request) !== null || $qa !== null)) {
            $aiSuggestions = ChatSuggestions::withoutReadingStarters($aiSuggestions);
        }
        // 🎁 ห้องของไพ่ฟรี — ไม่มีปุ่ม "ถามต่อจากเรื่องนี้" (ป้ายไพ่หมวดอื่นแทน) · ยกเว้นตัวเลือกคำตอบตอนผู้พยากรณ์ถามกลับ
        if ($free !== null && ! ChatSuggestions::isAwaitingAnswer((string) $reply)) {
            $aiSuggestions = [];
        }
        if ($degraded) {
            // ไม่ได้คำตอบจริง ไม่คิดเงิน — ลูกค้าส่งใหม่ด้วยคีย์เดิมได้ทันที
            $release();
            // ⏳ คำถามแรกไม่ได้คำตอบ = ยังไม่เริ่มนับ (ลูกค้าไม่เสียเวลาถามต่อไปกับตอนที่ระบบล่ม)
            if ($qaJustStarted) {
                FreeAskClock::unstart($conversation);
            }
            // 🔮 (2026-11-03) และไม่กินโควตาคุยฟรี: dailyUsed() นับข้อความของลูกค้า ถ้าเก็บไว้ ส่งใหม่หนึ่งครั้ง
            //    = เสียโควตาสองข้อความแลกคำตอบเดียว · คำขอโทษก็ไม่เก็บ (ลูกค้าเห็นบนจอแล้ว ส่งใหม่ได้คำตอบจริงแทน)
            $userMessage->delete();
        }

        // Debit only AFTER a successful reply — fairer to the user when upstream
        // blips. And NEVER charge for a degraded placeholder (no AI key AND
        // upstream unreachable): the user gets the "not ready" note for free.
        // Nor for an invitation to open cards (kind=offer): that is the shop
        // counter, not an answer — the reading itself is what gets paid for.
        // Race-safe because debit() locks the wallet row.
        $debitTx = null;
        if ($cost > 0 && ! $degraded && empty($dispatch['fallback']) && ! ChatPolicy::isFreeTurn($kind)) {
            try {
                $debitTx = $this->wallet->debit($user, $cost, 'สนทนากับ' . ChatRooms::name($room), [
                    'reference_type' => 'chat_message',
                    'reference_id'   => $userMessage->id,
                ]);
            } catch (InsufficientFundsException $e) {
                // Race lost (parallel debit between balance check and now).
                // We've already produced a reply — surface a one-liner in the
                // assistant message so the user understands why no further
                // messages will go through.
                $reply .= "\n\n— *" . ChatRooms::selfName($conversation->persona) . 'บอก: เครดิตในวอลเลตหมดพอดีตอนตอบครั้งนี้ ครั้งหน้ากรุณาเติมเงินก่อนนะคะ*';
            }
        }

        // We've already debited — if persisting the reply now fails, the user
        // would be charged for a message they never see. Refund on failure.
        $assistant = null;
        try {
            if (! $degraded) {
                // จำปุ่มชุดล่าสุดไว้ให้หน้าที่รีเฟรช (ชุดว่าง = ลบของเก่า ไม่ให้ปุ่มของคำตอบก่อนค้างอยู่)
                $aiSuggestions !== []
                    ? Cache::put(self::askKey($conversation->id), $aiSuggestions, now()->addHours(6))
                    : Cache::forget(self::askKey($conversation->id));
                $assistant = ChatMessage::create([
                    'chat_conversation_id' => $conversation->id,
                    'role'        => 'assistant',
                    'content'     => $reply,
                    'offer_topic' => $offers !== [] ? ChatReadingIntent::normalizeTopic($dispatch['offer_topic'] ?? null) : null,
                ]);
            }
        } catch (\Throwable $e) {
            if ($debitTx) {
                try {
                    $this->wallet->refund($debitTx, 'บันทึกข้อความตอบกลับไม่สำเร็จ');
                } catch (\Throwable $refundErr) {
                    Log::critical('Chat refund FAILED after persist failure — manual intervention needed', [
                        'tx_id' => $debitTx->id, 'err' => $refundErr->getMessage(),
                    ]);
                }
            }
            Log::error('Chat assistant message persist failed after debit', [
                'user_id' => $user->id, 'err' => $e->getMessage(),
            ]);
            $release();

            return [
                'status'  => 'persist_failed',
                'message' => 'ระบบขัดข้องชั่วคราว — ' . ($debitTx ? 'เครดิตถูกคืนแล้ว ' : '') . 'กรุณาลองใหม่อีกครั้ง',
            ];
        }
        $conversation->touch();

        // ✨ พรีเมียมเสียง — ระหว่างเวลาถามต่อ ทุกคำตอบพูดออกเสียงของห้อง
        $voiceUrl = $speak && ! $degraded && $kind !== 'offer' && $kind !== 'help' && $reading !== null
            ? $this->speakReply($reading, $conversation, (string) $reply) : null;

        return [
            'status'         => 'ok',
            'reply'          => $reply,
            'voice_url'      => $voiceUrl,
            'kind'           => $kind,
            'offers'         => $offers,
            'degraded'       => $degraded,
            // แม่หมอกำลังถามกลับอยู่ไหม — หน้าห้องยุบแถบปุ่มคำถามลัด ไม่ให้บทสนทนาหลุดโฟลว์
            'awaiting'       => ChatSuggestions::isAwaitingAnswer($reply),
            // ⏳ นาฬิกาถามต่อ (null = ห้องคุยทั่วไป) — หน้าห้องนับถอยหลังตามเวลาของเซิร์ฟเวอร์
            'qa'             => ReadingQaClock::state($conversation, $reading),
            // 🎁 นาฬิกาถามฟรีของห้องที่เริ่มจากไพ่ฟรี (null = ห้องอื่น)
            'free_ask'       => $reading === null ? FreeAskClock::state($conversation) : null,
            'ai_suggestions' => $aiSuggestions,
            'question'       => $offers !== [] ? mb_substr($message, 0, 300) : null,
            'assistant'      => $assistant,
        ];
    }

    /**
     * เงินในวอลเลตพอเปิดแบบไพ่ที่ยื่นอยู่ได้อย่างน้อยหนึ่งแบบไหม (แบบที่ยังล็อกอยู่ไม่นับ)
     * เทียบเป็นสตางค์ เหมือน canAfford() ของหน้าแชท — float ตรง ๆ ทำให้คนที่เงินพอดีโดนบล็อก
     */
    public function walletCovers($user, array $offers): bool
    {
        if (! $user || $offers === []) {
            return false;
        }
        $cents = (int) round($this->wallet->balance($user) * 100);
        foreach ($offers as $o) {
            if (($o['locked_until'] ?? null) !== null) {
                continue;
            }
            if ((int) round(((float) ($o['price'] ?? 0)) * 100) <= $cents) {
                return true;
            }
        }

        return false;
    }

    /**
     * เปิด upstream session ใหม่ — ห้องที่ผูกกับไพ่ได้บริบทเต็มไปด้วยตั้งแต่เปิด
     *
     * upstream หมุน session เองทุก 6 ชม. (TTL) เดิมเมื่อ session หมุนแล้ว
     * เราเปิดใหม่ "เปล่า ๆ" บริบทไพ่จึงหายเงียบ ๆ — ผู้ใช้ยังเห็นหน้าจอเดิม
     * ที่ชวนถามต่อจากไพ่ แต่แม่หมอตอบเหมือนไม่เคยเห็นไพ่ชุดนั้นเลย
     * 💬 (2026-09-21) บริบทไม่ใช่ข้อความ primer ที่ต้องยิงซ้ำ (และเงียบหายได้ถ้ายิงไม่ผ่าน) อีกต่อไป
     * แต่เป็นฟิลด์ `context` ของการเปิดห้อง ซึ่งผู้เรียกสร้างใหม่จากฐานข้อมูล (reading_id)
     */
    public function openUpstreamSession($user, ChatConversation $conversation, ?string $context = null): ?string
    {
        // 🎁 ห้องที่เริ่มจากไพ่ฟรี — ไม่ผูก reading_id แต่ผู้พยากรณ์ต้องเห็นไพ่ใบนั้น (FreeReadingRoom::open)
        $context ??= FreeReadingRoom::seedContext($conversation->id);
        $start     = $this->upstream->start($user, $context, persona: ChatRooms::upstreamPersona($conversation->persona));
        $sessionId = $start['session'] ?? null;
        if (! $sessionId) {
            return null;
        }

        $this->upstream->rememberSession($conversation->id, $sessionId);

        return $sessionId;
    }

    /**
     * ✨ (2026-11-05) พรีเมียมเสียง: คำตอบในห้องถามต่อ → ไฟล์เสียงของห้อง (null = ตอบเป็นตัวอักษรอย่างเดียว)
     *
     * ตัดอีโมจิ/มาร์กดาวน์ก่อนพูด · ยาวเกินตัดที่ช่องว่างสุดท้าย · เกินเพดานต่อคำทำนาย = ไม่พูด
     * รอเสียงไม่เกิน ~12 วิ — ช้ากว่านั้นลูกค้าได้ข้อความไปก่อน (ไม่ค้างหน้า)
     */
    private function speakReply(Reading $reading, ChatConversation $conversation, string $reply): ?string
    {
        $qa = ReadingQaClock::state($conversation, $reading);
        if ($qa === null || $qa['over'] || ! VoicePremium::active($reading)) {
            return null;
        }
        $text = ReadingTheater::plain($reply);
        $text = (string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text);
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));
        // 0 = ไม่อั้น (พูดทั้งคำตอบ) · ยังตัดที่ 5,000 เสมอ — /voice/speak ของ Thaiprompt รับไม่เกิน 6,000
        $max = (int) config('reading_voice.reply_max_chars', 0);
        $max = $max > 0 ? min($max, self::SPEAK_HARD_MAX) : self::SPEAK_HARD_MAX;
        if (mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max);
            $space = max((int) mb_strrpos($cut, ' '), (int) mb_strrpos($cut, "\n"));
            $text = trim($space > $max / 2 ? mb_substr($cut, 0, $space) : $cut);
        }
        if ($text === '' || ! VoicePremium::canSpeak($reading, mb_strlen($text))) {
            return null;
        }

        $r = app(JuntraServerClient::class)->voiceSpeak((string) $conversation->persona, $text, 'chat', (string) $reading->user_id, 12);
        if ($r['status'] !== 'completed') {
            return null;
        }
        VoicePremium::addChatChars($reading, mb_strlen($text));

        return $r['url'];
    }

    /**
     * ลูกค้าขอดูดวงระหว่างที่ยังเปิดการ์ดใหม่ไม่ได้ — คืนคำตอบนุ่ม ๆ แทนการ์ด หรือ null ถ้าเปิดได้ตามปกติ
     */
    private function offerHold(Request $request, $user, ?string $persona, ?array $qa = null, array $offers = []): ?string
    {
        $self = ChatRooms::selfName($persona);
        if ($qa !== null && ! $qa['over']) {
            $mins = max(1, (int) ceil($qa['left'] / 60));

            return "ตอนนี้เรากำลังคุยต่อจากไพ่ชุดที่เปิดไว้นะคะ ยังถามต่อได้อีกราว {$mins} นาที "
                . "ถ้าอยากเปิดไพ่ชุดใหม่ กดปุ่ม \"พอแล้ว\" ด้านบนก่อน แล้ว{$self}เปิดให้ได้เลยค่ะ 🌙";
        }
        if (($lock = ReadingRoomLock::active($request)) !== null) {
            return match ($lock['reason']) {
                'reading' => "{$self}กำลังอ่านไพ่ชุดที่เปิดไว้ให้อยู่นะคะ อีกสักครู่คำทำนายจะเสร็จ แล้วถามต่อจากไพ่ชุดนั้นได้เลยค่ะ 🌙",
                // ห้องใหม่ระหว่างที่นาฬิกาถามต่อของไพ่ชุดก่อนยังเดินอยู่
                'qa' => "ตอนนี้ยังอยู่ในช่วงถามต่อจากไพ่ชุดที่เปิดไว้นะคะ กลับไปถามต่อ หรือกด \"พอแล้ว\" ในห้องนั้นก่อน แล้ว{$self}เปิดไพ่ชุดใหม่ให้ได้เลยค่ะ 🌙",
                default => "ตอนนี้เรากำลังอยู่ในพิธีเปิดไพ่กันอยู่นะคะ กลับไปเลือกไพ่ให้ครบก่อน แล้วค่อยถามต่อจากไพ่ได้เลยค่ะ 🌙",
            };
        }
        // 🧾 (2026-11-11) บิลค้างขวางเฉพาะเมื่อเงินในวอลเลตยังไม่พอเปิดแบบที่ยื่นให้สักแบบ
        //    เคสจริง: ลูกค้า #26 มีเงิน ฿39.92 พอเปิดไพ่ ฿39 แต่มีรายการรอค้าง → ได้ข้อความทวงบิลแทนทุกคำถาม
        //    (กดแบบที่เงินไม่พอ หน้าแชทเปิดบิลเดิมให้จ่ายเอง ไม่ออกบิลใหม่ซ้อน)
        // 🛡 แอพช่อง Google Play: ห้ามมีข้อความที่พาไปจ่ายนอก Play (QR/โอนบัญชี) — บิลจากเว็บไม่ขวางการ์ดในแอพ
        if ($user && ! AppChannel::isPlay($request)
            && ($bill = ChatTopupController::pendingBill($user)) !== null && ! $this->walletCovers($user, $offers)) {
            $amount = '฿' . number_format((float) $bill->amount, 2);
            // แนบสลิปแล้ว = ลูกค้าโอนมาแล้ว รอตรวจ — ห้ามบอกให้โอนอีก (ทวงซ้ำ = ลูกค้าโอนซ้ำจริง)
            if (! empty($bill->slip_path)) {
                return "ได้รับสลิปของบิลเลขที่ {$bill->reference_code} ยอด {$amount} แล้วนะคะ กำลังตรวจอยู่ ไม่ต้องโอนซ้ำค่ะ "
                    . "พอเงินเข้าระบบแล้ว{$self}เปิดไพ่ให้ต่อได้เลยค่ะ 🌙";
            }

            // 🧭 (2026-11-10) แชทจริง: ลูกค้าถาม "ชำระตรงไหนค่ะ" แล้วได้ประโยคนี้ซ้ำ 8 ครั้งโดยไม่รู้ว่าต้องกดอะไร
            //    → บอกทางจ่ายต่อ และทางยกเลิกถ้าไม่ใช้บิลนี้แล้ว (ชื่อปุ่มตรงกับหน้าเว็บ)
            return "ยังมีบิลเติมเงินเลขที่ {$bill->reference_code} ยอด ฿" . number_format((float) $bill->amount, 2) . ' รอชำระอยู่นะคะ '
                . 'จ่ายต่อได้ที่ปุ่ม "ดู QR จ่ายต่อ" ในแถบบิลด้านบนห้องนี้ (โอนยอดนี้ให้ตรงเป๊ะ ระบบตรวจให้เอง) '
                . 'ถ้าไม่ใช้บิลนี้แล้ว ยกเลิกได้ที่เมนู "วอลเลต" → "สถานะการเติมเงิน" → "รายละเอียด →" → "ยกเลิกรายการนี้" '
                . "จ่ายหรือยกเลิกแล้ว{$self}เปิดไพ่ให้ต่อได้เลยค่ะ";
        }

        return null;
    }

    /**
     * 🎁 (2026-11-11) พิมพ์มาหลังหมดเวลาถามฟรี — คำชวนเปิดไพ่ชุดเต็ม + การ์ดที่ตรงเรื่อง (ไม่ถาม AI ไม่คิดเงิน)
     *
     * บิลค้าง = บอกทางจ่ายบิลเดิมแทนการ์ด (offerHold ชุดเดียวกับห้องอื่น)
     * ไม่มีแบบไพ่ให้ยื่นเลย (ปิดขายหมด/ติดข้อห้ามเปิดซ้ำทุกแบบ) = null → คุยต่อตามปกติ ดีกว่าบอก "เลือกด้านล่าง" แต่ไม่มีอะไรให้เลือก
     */
    private function freeAskOver(Request $request, $user, ChatConversation $conversation, string $message, array $free, Closure $release): ?array
    {
        $room = (string) $conversation->persona;
        $topic = FreeAskClock::topicOf($message);
        $offers = FreeAskClock::offersFor($room, $user, $topic);
        $hold = $this->offerHold($request, $user, $room, null, $offers);
        if ($offers === [] && $hold === null) {
            return null;
        }
        $reply = $hold ?? FreeAskClock::afterText($room, $user);
        $offers = $hold !== null ? [] : $offers;

        ChatMessage::create(['chat_conversation_id' => $conversation->id, 'role' => 'user', 'content' => $message]);
        $assistant = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'offer_topic' => $offers !== [] ? ChatReadingIntent::normalizeTopic($topic) : null,
        ]);
        Cache::forget(self::askKey($conversation->id));
        $release();

        return [
            'status'    => 'free_over',
            'reply'     => $reply,
            'offers'    => $offers,
            'free_ask'  => $free,
            'question'  => $offers !== [] ? mb_substr($message, 0, 300) : null,
            'assistant' => $assistant,
        ];
    }

    /**
     * Try Thaiprompt first (the canonical FB/LINE bot pattern, using the
     * upstream API-key pool). If it's unreachable or returns nothing we
     * fall back to the local AiOracle — juntra's chat NEVER breaks, even
     * when upstream is down.
     */
    private function dispatchToUpstream($user, ChatConversation $conversation, string $message, float $t0): array
    {
        $room    = ChatRooms::normalize($conversation->persona);
        $persona = ChatRooms::upstreamPersona($room);
        $left    = fn (): int => (int) floor(self::BUDGET_SEC - (microtime(true) - $t0));

        // 💬 (2026-09-21) ห้องที่ผูกกับไพ่ที่จ่ายแล้ว — อ่านจากฐานข้อมูลทุกครั้ง (เดิมอ่านจาก session เบราว์เซอร์
        //    และมีอายุ 24 ชม.) · บริบท = ไพ่ทุกใบ + คำพยากรณ์ฉบับเต็ม ส่งไปทุกรอบ ห้องฝั่ง Thaiprompt หมดอายุ
        //    หรือ cache ถูกล้างเมื่อไร แม่หมอก็ยังเห็นคำพยากรณ์ครบ
        $reading  = ReadingChatContext::readingOf($conversation);
        // ⏳ บรรทัดเวลาที่เหลือขึ้นก่อนบริบทไพ่ — ผู้พยากรณ์รู้ว่าเหลือกี่นาที (เจ้าของถาม "ทำไมบอทไม่รู้ว่าเหลือเวลาเท่าไหร่")
        $timeLine = $reading ? ReadingQaClock::contextLine(ReadingQaClock::state($conversation, $reading)) : null;
        // 🎁 (2026-11-11) ห้องของไพ่ฟรี — แนบไพ่ใบนั้น + เวลาถามฟรีที่เหลือทุกข้อความ (เดิมแนบแค่ตอนเปิดห้องจาก cache)
        //    ไม่นับเป็น grounded: ขอให้ดูดวงเรื่องใหม่ยังได้การ์ดเปิดไพ่ (ChatReadingIntent ด้านล่าง)
        $freeReading = $reading === null ? FreeAskClock::readingOf($conversation) : null;
        if ($freeReading !== null) {
            $timeLine = FreeAskClock::contextLine(FreeAskClock::state($conversation));
        }
        $cardsOf = $reading ?? $freeReading;
        // 🧑‍💼 (2026-11-09) เพิ่งคืนห้องจากแอดมิน — ผู้พยากรณ์ต้องรู้ว่าทีมงานคุยอะไรไป (ห้ามขัดที่รับปากไว้)
        $head     = trim(($timeLine ?? '') . "\n\n" . (ChatTakeover::handbackNote($conversation) ?? ''));
        $head     = $head !== '' ? $head : null;
        $context  = $cardsOf
            ? trim(($head !== null ? $head . "\n\n" : '') . ReadingChatContext::for($cardsOf, $head !== null ? mb_strlen($head) + 2 : 0))
            : $head;
        $grounded = $reading !== null;

        // 🧭 (2026-11-10) ถามวิธีใช้เว็บ/แจ้งปัญหา → แนบคู่มือเฉพาะหัวข้อนั้น + สถานะบัญชีจริง ให้ผู้พยากรณ์ตอบเป็นขั้นตอน
        //    มาก่อนด่านยื่นการ์ด: "เปิดไพ่ยังไง" = ถามวิธีใช้ ไม่ใช่ขอให้ดูดวง · เรื่องที่แก้เองไม่ได้ (เงินไม่เข้า
        //    ขอคุยกับคน เว็บค้าง) แจ้งทีมงานทาง Telegram ทันที (ครั้งเดียวต่อลูกค้าต่อหัวข้อต่อ 30 นาที)
        $helpIds  = SiteHelp::match($message);
        $siteHelp = $helpIds !== [] ? SiteHelp::block($helpIds, $user, $room) : '';
        $siteHelp = $siteHelp !== '' ? $siteHelp : null;
        if ($siteHelp !== null && ($escalate = SiteHelp::escalationFor($helpIds)) !== null) {
            SiteHelp::escalate($user, $escalate, $message, $room);
        }

        // 🌙 (2026-09-15) "คุยฟรีจนกว่าจะเริ่มการทำนาย" — ขอให้ดูดวงตรง ๆ = ยื่นการ์ดเปิดไพ่เลย
        //    ไม่ส่งไปให้ AI ทำนายฟรี · ยกเว้นห้องที่คุยต่อจากไพ่ที่จ่ายแล้ว (ถามเรื่องไพ่ชุดนั้น = คุยได้)
        if ($siteHelp === null && ! $grounded && ($topic = ChatReadingIntent::detect($message)) !== null) {
            return ['reply' => ChatOffers::invite($topic, $room, $user), 'degraded' => false, 'kind' => 'offer', 'offer_topic' => $topic];
        }

        // Re-use the upstream session id across messages for context continuity.
        $sessionId = $this->upstream->sessionFor($conversation->id)
            ?? $this->openUpstreamSession($user, $conversation, $context);

        $out = $sessionId && $left() >= 8
            ? $this->upstream->send($user, $sessionId, $message, $grounded, $context, $persona, $left() - 3, $siteHelp)
            : null;

        // ลองห้องใหม่อีกรอบเฉพาะตอน "ได้คำตอบว่าง" (ห้องค้าง) และยังมีเวลาพอ — ต่อไม่ติด/หมดเวลา/เซิร์ฟเวอร์แน่น
        // ยิงซ้ำทันทีมีแต่ซ้ำเติมฝั่งที่กำลังล้ม และกินเวลาจนเกินเพดาน 60 วิ (เดิมรวมได้ ~148 วิ)
        if ($out === null && $sessionId && $this->upstream->worthRetry() && $left() >= 25) {
            Log::info('ผู้พยากรณ์ตอบว่าง — เปิดห้องใหม่แล้วลองอีกครั้ง', ['user_id' => $user->id, 'room' => $room]);
            $this->upstream->forgetSession($conversation->id);
            $newSession = $this->openUpstreamSession($user, $conversation, $context);
            $out = $newSession ? $this->upstream->send($user, $newSession, $message, $grounded, $context, $persona, $left() - 3, $siteHelp) : null;
        }

        if ($out === null) {
            Log::warning('ผู้พยากรณ์ไม่ตอบ — ใช้ทางสำรอง', ['user_id' => $user->id, 'room' => $room, 'left' => $left()]);

            // ทางสำรอง (AiOracle) รอได้ถึง 30 วิ — เวลาไม่พอแล้วตอบขอโทษ (ไม่คิดเงิน) ดีกว่าให้ลูกค้าเห็นหน้า error
            // ห้องที่ไม่ใช่แม่หมอก็ไม่ใช้ทางสำรอง: ทางสำรองพูดเป็นแม่หมอ บุคลิกจะไม่ตรงห้อง
            // เทิร์นช่วยใช้เว็บก็ไม่ใช้ทางสำรอง — ทางสำรองไม่เห็นคู่มือ จะเดาขั้นตอน/ชื่อปุ่มเอง
            if ($room !== ChatRooms::DEFAULT || $left() < 32 || $siteHelp !== null) {
                return ['reply' => ChatRooms::busyReply($room), 'degraded' => true];
            }

            return $this->degradedFallback($message, $context);
        }

        // เทิร์นช่วยใช้เว็บ = kind 'help' (ไม่คิดเงิน ไม่ยื่นการ์ด ไม่อ่านออกเสียง) แม้ฝั่งนั้นจะตอบ reply/offer มา
        return [
            'reply'       => $out['reply'],
            'degraded'    => false,
            'kind'        => $siteHelp !== null ? 'help' : $out['kind'],
            'offer_topic' => $siteHelp !== null ? null : $out['offer_topic'],
            'suggestions' => $out['suggestions'] ?? [],
        ];
    }

    /**
     * Local AiOracle fallback. Marked "degraded" only when there's no Gemini
     * key either — i.e. the reply is a placeholder, not a real answer — so the
     * caller can skip the charge. When a key IS set the local model gives a
     * genuine reply and we charge normally.
     * 💬 (2026-09-21) ห้องที่ผูกกับไพ่ — ทางสำรองก็ตอบจากคำพยากรณ์เดิม (เดิมได้แค่ข้อความของลูกค้าอย่างเดียว)
     */
    private function degradedFallback(string $message, ?string $context = null): array
    {
        return [
            'reply'    => $this->oracle->chat([(object) ['role' => 'user', 'content' => $message]], $context),
            'degraded' => ! $this->oracle->isConfigured(),
            // 🛡 (2026-11-07) คำตอบจากทางสำรองไม่ผ่านด่านของสมองบอท (กันมโนไพ่/หลุดบท/ความจำ) — ตอบได้ แต่ไม่คิดเงิน
            'fallback' => true,
        ];
    }
}
