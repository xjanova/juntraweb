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
 */
class MaeMorUpstream
{
    public function __construct(
        private JuntraServerClient $server,
        private FortuneBotClient $bot,
    ) {}

    /** @return array{session:string,greeting:?string}|null  null = คุยกับแม่หมอไม่ได้ตอนนี้ */
    public function start(User $user): ?array
    {
        $res = $this->server->chatStart($user->id);
        if ($res['status'] === 'ok' && ! empty($res['data']['session_id'])) {
            return ['session' => 'srv:' . $res['data']['session_id'], 'greeting' => $res['data']['greeting'] ?? null];
        }

        if ($res['status'] === 'unsupported' && $this->bot->isAvailable($user)) {
            $start = $this->bot->start($user);
            if (! empty($start['session_id'])) {
                return ['session' => 'usr:' . $start['session_id'], 'greeting' => $start['greeting'] ?? null];
            }
        }

        return null;
    }

    /**
     * @return array{reply:string,kind:string,offer_topic:?string}|null  null = ไม่มีคำตอบจริง (ผู้เรียกลองห้องใหม่/ถอยไปทางสำรอง)
     */
    public function send(User $user, string $session, string $text, bool $grounded = false): ?array
    {
        [$mode, $sid] = str_contains($session, ':') ? explode(':', $session, 2) : ['usr', $session];

        if ($mode === 'srv') {
            $res = $this->server->chatSend($user->id, $user->name, $sid, $text, $grounded);
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
}
