<?php

namespace App\Services\FortuneBot;

use App\Models\Reading;
use App\Models\User;
use App\Services\AiOracle;
use App\Support\TarotSpreads;
use Illuminate\Support\Facades\Log;

/**
 * Routes fortune AI requests to Thaiprompt's API pool first (so juntra
 * users get the same model rotation, sensitive-mode handling, and
 * billing telemetry as the FB Messenger and LINE OA bots) and falls
 * back to the local AiOracle (Gemini key on this machine) only when
 * upstream is unavailable.
 *
 * juntra still holds NO production AI keys — the local fallback exists
 * solely so the page renders something useful when the operator hasn't
 * yet linked the user's Thaiprompt account, OR when Thaiprompt is
 * temporarily unreachable.
 */
class FortuneAiService
{
    public function __construct(
        private FortuneBotClient $bot,
        private AiOracle $oracle,
    ) {}

    /**
     * ระบบทำนายจริงพร้อมใช้กับผู้ใช้คนนี้ไหม
     *
     * ผู้เรียกที่ **เก็บเงิน** ต้องถามก่อนตัดเครดิตเสมอ — ถ้าไม่พร้อม
     * {@see interpretTarot()} จะตกไปคืนข้อความที่ประกอบจากคอลัมน์ความหมายไพ่
     * (`source => 'local'`) ซึ่งไม่ใช่คำทำนาย และไม่ควรขายเต็มราคา
     */
    public function isAvailableFor(?User $user): bool
    {
        return $this->bot->isAvailable($user);
    }

    /**
     * Interpret a tarot spread.
     *
     * @return array{text: string, provider: string, model: string, source: 'thaiprompt'|'local'}
     */
    public function interpretTarot(Reading $reading, ?User $user): array
    {
        $key = TarotSpreads::keyFromType($reading->type);

        // Build the structured payload the upstream tarot endpoint expects.
        // `cards` carry each position's `asks` (TarotPromptBuilder::payloadCards)
        // and `prompt` is the full Card-First Mandate text so the chat-pipe
        // fallback reads card × position exactly like the local path.
        $payload = [
            'spread' => $reading->type,
            'spread_key' => $key,
            'spread_name' => $key ? (TarotSpreads::get($key)['name_th'] ?? null) : null,
            'question' => $reading->question,
            'cards' => TarotPromptBuilder::payloadCards($reading),
            'prompt' => TarotPromptBuilder::userPrompt($reading),
        ];

        if ($this->bot->isAvailable($user)) {
            $remote = $this->bot->interpretTarot($user, $payload);
            if ($remote && ! empty($remote['interpretation'])) {
                return [
                    'text' => $remote['interpretation'],
                    'provider' => $remote['ai_provider'] ?? 'thaiprompt',
                    'model' => $remote['ai_model'] ?? 'pool',
                    'source' => 'thaiprompt',
                ];
            }
            Log::info('FortuneAiService: upstream returned null/empty — using local fallback', [
                'user_id' => $user?->id,
                'reading' => $reading->id,
            ]);
        }

        return [
            'text' => $this->oracle->interpretTarotReading($reading),
            'provider' => $this->oracle->provider(),
            'model' => $this->oracle->model(),
            'source' => 'local',
        ];
    }

    /**
     * 🎁 (2026-07-26) คำทำนายฟรี 1 ใบ สั้น ๆ — ปลายทางของปุ่ม "ดูดวงฟรี" จากบอท
     *
     * ต่างจาก interpretTarot() ตรงที่ **ไม่มี fallback ในเครื่อง** โดยเจตนา:
     * ของฟรีคือด่านแรกที่ลูกค้าจากเฟซบุ๊กเจอ ถ้าได้ข้อความ deterministic ของ
     * AiOracle (ซึ่งไม่มีคีย์บนโปรดักชัน) แทนคำทำนายจริง ลูกค้าจะปิดหน้าไปเลย
     * — คืนสิทธิ์ให้ลองใหม่ดีกว่าเผาโอกาสเดียวที่มีทิ้ง
     *
     * @return array{text:string,provider:string,model:string,next_questions:array<int,string>}|null
     *                                                                                               null = อัปสตรีมไม่พร้อม (ผู้เรียกต้องคืนสิทธิ์ฟรีให้ลูกค้า)
     */
    public function freeTarot(Reading $reading, ?User $user, int $maxChars): ?array
    {
        if (! $this->bot->isAvailable($user)) {
            Log::warning('FortuneAiService::freeTarot — ไม่มี thaiprompt_token ใช้งานได้', [
                'user_id' => $user?->id,
                'reading' => $reading->id,
            ]);

            return null;
        }

        $card = $reading->tarotCards->first();
        if (! $card || ! $card->card) {
            Log::warning('FortuneAiService::freeTarot — reading ไม่มีไพ่', ['reading' => $reading->id]);

            return null;
        }

        $remote = $this->bot->freeTarot($user, [
            'card' => [
                'name_en' => (string) ($card->card->name_en ?? ''),
                'name_th' => (string) ($card->card->name_th ?? ''),
                'is_reversed' => (bool) $card->reversed,
                'meaning' => (string) ($card->reversed
                    ? ($card->card->reversed_meaning_th ?? '')
                    : ($card->card->upright_meaning_th ?? '')),
            ],
            'position_name' => (string) ($card->position_label ?? 'คำตอบของไพ่'),
            'max_chars' => $maxChars,
            'customer_name' => $this->addressableName($user?->name),
        ]);

        if (! $remote || empty($remote['interpretation'])) {
            return null;
        }

        return [
            'text' => (string) $remote['interpretation'],
            'provider' => (string) ($remote['ai_provider'] ?? 'thaiprompt'),
            'model' => (string) ($remote['ai_model'] ?? 'pool'),
            'next_questions' => is_array($remote['next_questions'] ?? null) ? $remote['next_questions'] : [],
        ];
    }

    /**
     * ชื่อที่แม่หมอ "เรียกได้" — ถ้าไม่ใช่ชื่อคนก็ไม่ต้องส่งไปเลย
     *
     * ทดสอบบน prod แล้วได้คำทำนายว่า "ชีวิตช่วงนี้ของ adminthaiprompt กำลัง…"
     * เพราะ users.name บางบัญชีเป็น username/อีเมล ไม่ใช่ชื่อจริง
     * ลูกค้าจากบอทได้ชื่อจริงมาจากโปรไฟล์ FB/LINE ตอน SSO อยู่แล้ว
     * เคสที่เหลือปล่อยว่างดีกว่า — แม่หมอจะใช้ "เจ้าชะตา" แทนเอง
     */
    private function addressableName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return '';
        }

        // อีเมล/แฮนเดิล/มี URL = ไม่ใช่ชื่อที่เรียกออกเสียงได้
        if (preg_match('/[@\/\\\\:_]|\d{3}/u', $name)) {
            return '';
        }

        // ชื่อไทย (มีอักษรไทย) ผ่านเลย · ชื่อละตินต้องมีช่องว่างแบบชื่อ-นามสกุล
        // (คำละตินคำเดียวติดกันมักเป็น username เช่น adminthaiprompt)
        if (preg_match('/\p{Thai}/u', $name)) {
            return $name;
        }

        return str_contains($name, ' ') ? $name : '';
    }
}
