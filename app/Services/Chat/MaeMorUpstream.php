<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Support\ChatRooms;
use Illuminate\Support\Facades\Cache;

/**
 * ทางคุยกับแม่หมอ (Thaiprompt) — เลือกทางให้เอง ใช้ร่วมกันทั้งเว็บและแอพ
 *
 *   1. ทางของเว็บเอง (server-to-server) — ลูกค้าทุกคนที่ล็อกอินคุยได้ (เจ้าของสั่ง 2026-09-15)
 *      และแม่หมอรู้ว่าห้องนี้ "คุยฟรี ทำนาย = ชวนเปิดไพ่" (kind=offer)
 *   2. ทางเดิมด้วย token ของลูกค้า — เฉพาะตอนฝั่ง Thaiprompt ยังไม่มีทางแรก (deploy ไม่พร้อมกัน)
 *
 * รหัสห้องที่เก็บไว้ติดคำนำหน้าบอกทาง ("srv:" / "usr:") เพราะห้องของสองทางใช้แทนกันไม่ได้
 * รหัสเก่าที่ไม่มีคำนำหน้า = ทางเดิม
 *
 * 💬 (2026-09-21) `$context` = คำพยากรณ์ที่ห้องนี้คุยต่อ (ReadingChatContext) ไปกับทางที่ 1 เท่านั้น
 * ทางที่ 2 จงใจไม่ส่ง: endpoint นั้นรับ token ของลูกค้า ถ้ายอมรับบริบทที่ไปอยู่ใน system message
 * ลูกค้าคนไหนถือ token ก็เขียนคำสั่งระบบของแม่หมอเองได้ (ทางนี้ใช้เฉพาะตอนยังไม่ได้ตั้ง client ของเว็บ)
 */
class MaeMorUpstream
{
    public function __construct(
        private JuntraServerClient $server,
        private FortuneBotClient $bot,
    ) {}

    /** เหตุที่ครั้งล่าสุดไม่ได้คำตอบ: 'network' | 'busy' | 'empty' | null (ผู้เรียกใช้ตัดสินว่าควรลองใหม่ไหม) */
    private ?string $lastFailure = null;

    /**
     * 🌙 (2026-11-03 FB web-only) `$handoff` = nonce จากลิงก์ /chat/continue/{handoff} ไปกับทางที่ 1 เท่านั้น
     * และส่ง thaiprompt_user_id ของลูกค้าไปด้วยทุกครั้ง ให้แม่หมอรู้จักลูกค้าที่ย้ายมาจากเพจ
     * (ความจำอยู่ฝั่ง Thaiprompt ไม่ถูกส่งกลับมา — ตอบแค่ continued_from / offer_topic)
     *
     * 🔐 (รีวิว) handoff_id / handoff_consumed ส่งต่อให้ ChatController::continue ตัดสินว่าลิงก์นี้ "ใช้ได้ครั้งนี้" จริงไหม
     *    (handoff_consumed = null = Thaiprompt รุ่นเก่าที่ยังไม่ส่งฟิลด์นี้)
     *
     * @param  string|null  $persona  🔮 (2026-11-03) ผู้พยากรณ์ของห้อง (ChatRooms::upstreamPersona) — ไปทางที่ 1 เท่านั้น
     *                                ทางที่ 2 (token ลูกค้า) เป็นบอทแม่หมอล้วน ไม่มีบุคลิกอื่น
     * @return array{session:string,greeting:?string,offer_topic:?string,continued_from:?string,handoff_id:?string,handoff_consumed:?bool}|null  null = คุยกับแม่หมอไม่ได้ตอนนี้
     */
    public function start(User $user, ?string $context = null, ?string $handoff = null, ?string $persona = null): ?array
    {
        $res = $this->server->chatStart($user->id, $context, $this->thaipromptUserId($user), $handoff, $persona);
        if ($res['status'] === 'ok' && ! empty($res['data']['session_id'])) {
            $topic = $res['data']['offer_topic'] ?? null;

            return [
                'session'        => 'srv:' . $res['data']['session_id'],
                'greeting'       => $res['data']['greeting'] ?? null,
                'offer_topic'    => is_string($topic) && $topic !== '' ? ChatReadingIntent::normalizeTopic($topic) : null,
                'continued_from' => ($res['data']['continued_from'] ?? null) === 'facebook' ? 'facebook' : null,
                'handoff_id'     => is_string($res['data']['handoff_id'] ?? null) ? $res['data']['handoff_id'] : null,
                'handoff_consumed' => array_key_exists('handoff_consumed', $res['data']) ? (bool) $res['data']['handoff_consumed'] : null,
            ];
        }

        // ทางที่ 2 เป็นบอทแม่หมอล้วน — ห้องบุษบา/คุณไสยห้ามถอยไปทางนี้ (แม่หมอจะตอบในห้องบุษบา = บุคลิกไม่ตรงห้อง)
        //    คืน null ให้ผู้เรียกตอบ busyReply ของห้องแทน
        $foreign = $persona !== null && $persona !== ChatRooms::DEFAULT;
        if ($res['status'] === 'unsupported' && ! $foreign && $this->bot->isAvailable($user)) {
            $start = $this->bot->start($user);
            if (! empty($start['session_id'])) {
                return ['session' => 'usr:' . $start['session_id'], 'greeting' => $start['greeting'] ?? null, 'offer_topic' => null, 'continued_from' => null, 'handoff_id' => null, 'handoff_consumed' => null];
            }
        }

        return null;
    }

    /**
     * @return array{reply:string,kind:string,offer_topic:?string,suggestions:list<string>,crisis:bool,crisis_room:?bool}|null  null = ไม่มีคำตอบจริง (ผู้เรียกลองห้องใหม่/ถอยไปทางสำรอง)
     */
    public function send(User $user, string $session, string $text, bool $grounded = false, ?string $context = null, ?string $persona = null, int $timeout = 45): ?array
    {
        [$mode, $sid] = str_contains($session, ':') ? explode(':', $session, 2) : ['usr', $session];
        $this->lastFailure = null;

        if ($mode === 'srv') {
            $res = $this->server->chatSend($user->id, $user->name, $sid, $text, $grounded, $context, $this->thaipromptUserId($user), $persona, $timeout);
            $reply = trim((string) ($res['data']['reply'] ?? ''));
            if ($res['status'] !== 'ok' || $reply === '') {
                $this->lastFailure = $res['status'] === 'ok' ? 'empty' : ($this->server->lastFailure() ?? 'busy');

                return null;
            }
            $kind = (string) ($res['data']['kind'] ?? 'reply');

            return [
                'reply'       => $reply,
                'kind'        => in_array($kind, ['reply', 'guard', 'offer'], true) ? $kind : 'reply',
                'offer_topic' => $kind === 'offer' ? ChatReadingIntent::normalizeTopic($res['data']['offer_topic'] ?? null) : null,
                'suggestions' => self::cleanSuggestions($res['data']['suggestions'] ?? null),
                // 🤍 (2026-11-06) คำปลอบลูกค้าที่ส่งสัญญาณอยากทำร้ายตัวเอง (kind ยังเป็น guard) — ห้ามคิดเงินเทิร์นนี้
                'crisis'      => (bool) ($res['data']['crisis'] ?? false),
                // ห้องอยู่ในโหมดวิกฤต · null = Thaiprompt รุ่นก่อนไม่ได้บอก (ไม่แตะค่าที่จำไว้)
                'crisis_room' => array_key_exists('crisis_room', $res['data']) ? (bool) $res['data']['crisis_room'] : null,
            ];
        }

        if (! $this->bot->isAvailable($user)) {
            $this->lastFailure = 'busy';

            return null;
        }
        $reply = trim((string) (($this->bot->send($user, $sid, $text))['reply'] ?? ''));
        if ($reply === '') {
            // ทางเดิม: ห้องของลูกค้าหมดอายุได้จริง → คำตอบว่าง = เปิดห้องใหม่แล้วลองอีกครั้งมีประโยชน์
            $this->lastFailure = 'empty';

            return null;
        }

        return ['reply' => $reply, 'kind' => 'reply', 'offer_topic' => null, 'suggestions' => [], 'crisis' => false, 'crisis_room' => null];
    }

    /**
     * ✨ ปุ่มที่ผู้พยากรณ์คิดมาให้ (data.suggestions) — กรองซ้ำฝั่งเว็บ ไม่เชื่อของที่ส่งข้ามระบบมาตรง ๆ
     *
     * เหลือเฉพาะข้อความสั้นล้วน: ไม่มีแท็ก ลิงก์ ราคา · ไม่ซ้ำ · ไม่เกิน 4 ปุ่ม · ยาวไม่เกิน 60 ตัว
     *
     * @return list<string>
     */
    public static function cleanSuggestions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $t = trim((string) preg_replace('/\s+/u', ' ', strip_tags($item)));
            if ($t === '' || mb_strlen($t) > 60 || preg_match('~https?://|www\.|฿|บาท|[<>\[\]{}]~iu', $t)) {
                continue;
            }
            if (! in_array($t, $out, true)) {
                $out[] = $t;
            }
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    /**
     * ห้อง Thaiprompt ของห้องแชทนี้ — เก็บต่อห้องแชทใน cache 6 ชม. (เท่าอายุห้องฝั่ง Thaiprompt)
     *
     * 🔮 (2026-11-03) เว็บเคยเก็บไว้ใน session เบราว์เซอร์คีย์เดียว: ได้ห้องเดียวต่อเบราว์เซอร์
     *    (3 ห้องผู้พยากรณ์ใช้ร่วมกันไม่ได้) และคำขออื่นที่วิ่งพร้อมกัน (หน้าแชท poll สถานะเติมเงิน)
     *    เขียน session ทับได้ · คีย์เดียวกับ API มือถือ (Api\V1\ChatController) ห้องเดียวกันจึงใช้ห้องเดียวกัน
     */
    public function sessionFor(int $conversationId): ?string
    {
        $v = Cache::get(self::sessionKey($conversationId));

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function rememberSession(int $conversationId, string $session): void
    {
        Cache::put(self::sessionKey($conversationId), $session, now()->addHours(6));
    }

    public function forgetSession(int $conversationId): void
    {
        Cache::forget(self::sessionKey($conversationId));
    }

    private static function sessionKey(int $conversationId): string
    {
        return "juntra:chat:upstream_session:$conversationId";
    }

    /**
     * ควรเปิดห้องใหม่แล้วลองอีกรอบไหม — เฉพาะตอนได้คำตอบว่าง (ห้องค้าง)
     * ต่อไม่ติด/หมดเวลา/เซิร์ฟเวอร์แน่น = ลองซ้ำทันทีมีแต่เพิ่มภาระให้ฝั่งที่กำลังล้ม และกินเวลาจนเกินเพดาน 60 วิ
     */
    public function worthRetry(): bool
    {
        return $this->lastFailure === 'empty';
    }

    /** id บัญชี Thaiprompt ของลูกค้า (ผูกผ่าน SSO) — ไม่ผูก = null */
    private function thaipromptUserId(User $user): ?int
    {
        $id = (int) ($user->thaiprompt_user_id ?? 0);

        return $id > 0 ? $id : null;
    }
}
