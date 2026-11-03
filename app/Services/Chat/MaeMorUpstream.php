<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Thaiprompt\JuntraServerClient;

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

    /**
     * 🌙 (2026-11-03 FB web-only) `$handoff` = nonce จากลิงก์ /chat/continue/{handoff} ไปกับทางที่ 1 เท่านั้น
     * และส่ง thaiprompt_user_id ของลูกค้าไปด้วยทุกครั้ง ให้แม่หมอรู้จักลูกค้าที่ย้ายมาจากเพจ
     * (ความจำอยู่ฝั่ง Thaiprompt ไม่ถูกส่งกลับมา — ตอบแค่ continued_from / offer_topic)
     *
     * 🔐 (รีวิว) handoff_id / handoff_consumed ส่งต่อให้ ChatController::continue ตัดสินว่าลิงก์นี้ "ใช้ได้ครั้งนี้" จริงไหม
     *    (handoff_consumed = null = Thaiprompt รุ่นเก่าที่ยังไม่ส่งฟิลด์นี้)
     *
     * @return array{session:string,greeting:?string,offer_topic:?string,continued_from:?string,handoff_id:?string,handoff_consumed:?bool}|null  null = คุยกับแม่หมอไม่ได้ตอนนี้
     */
    public function start(User $user, ?string $context = null, ?string $handoff = null): ?array
    {
        $res = $this->server->chatStart($user->id, $context, $this->thaipromptUserId($user), $handoff);
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

        if ($res['status'] === 'unsupported' && $this->bot->isAvailable($user)) {
            $start = $this->bot->start($user);
            if (! empty($start['session_id'])) {
                return ['session' => 'usr:' . $start['session_id'], 'greeting' => $start['greeting'] ?? null, 'offer_topic' => null, 'continued_from' => null, 'handoff_id' => null, 'handoff_consumed' => null];
            }
        }

        return null;
    }

    /**
     * @return array{reply:string,kind:string,offer_topic:?string}|null  null = ไม่มีคำตอบจริง (ผู้เรียกลองห้องใหม่/ถอยไปทางสำรอง)
     */
    public function send(User $user, string $session, string $text, bool $grounded = false, ?string $context = null): ?array
    {
        [$mode, $sid] = str_contains($session, ':') ? explode(':', $session, 2) : ['usr', $session];

        if ($mode === 'srv') {
            $res = $this->server->chatSend($user->id, $user->name, $sid, $text, $grounded, $context, $this->thaipromptUserId($user));
            $reply = trim((string) ($res['data']['reply'] ?? ''));
            if ($res['status'] !== 'ok' || $reply === '') {
                return null;
            }
            $kind = (string) ($res['data']['kind'] ?? 'reply');

            return [
                'reply'       => $reply,
                'kind'        => in_array($kind, ['reply', 'guard', 'offer'], true) ? $kind : 'reply',
                'offer_topic' => $kind === 'offer' ? ChatReadingIntent::normalizeTopic($res['data']['offer_topic'] ?? null) : null,
            ];
        }

        if (! $this->bot->isAvailable($user)) {
            return null;
        }
        $reply = trim((string) (($this->bot->send($user, $sid, $text))['reply'] ?? ''));

        return $reply !== '' ? ['reply' => $reply, 'kind' => 'reply', 'offer_topic' => null] : null;
    }

    /** id บัญชี Thaiprompt ของลูกค้า (ผูกผ่าน SSO) — ไม่ผูก = null */
    private function thaipromptUserId(User $user): ?int
    {
        $id = (int) ($user->thaiprompt_user_id ?? 0);

        return $id > 0 ? $id : null;
    }
}
