<?php

namespace App\Services\FortuneBot;

use App\Models\Setting;
use App\Models\User;
use App\Services\ThaipromptTokenService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP wrapper around Thaiprompt's "Mae Mor Chantra" web/mobile chat API
 * AND its tarot-interpretation endpoint. Both ride on the same upstream
 * API-key pool that powers the Facebook Messenger / LINE bots, so juntra
 * users get identical persona + AI quality and juntra holds NO API keys
 * for AI providers — all key rotation, sensitive-mode detection, billing,
 * etc. happen upstream.
 *
 * Endpoints consumed:
 *   POST /api/v1/juntra/chat/mae-mor/start         → {data: {session_id, greeting}}
 *   POST /api/v1/juntra/chat/mae-mor/send          → {data: {session_id, reply, ai_provider}}
 *   POST /api/v1/juntra/fortune/tarot/interpret    → {data: {interpretation, ai_provider, ai_model}}
 *
 * If the dedicated tarot endpoint is not yet deployed upstream, we fall
 * back to running the tarot prompt through the chat pipeline — same pool,
 * same quality, just a slightly less structured response shape.
 */
class FortuneBotClient
{
    /** Start a new chat conversation. */
    public function start(User $user): ?array
    {
        try {
            $resp = $this->client($user)->post($this->chatUrl('/start'));
            if (! $resp->successful()) {
                $this->handleUnauthorized($user, $resp->status());
                Log::warning('FortuneBotClient::start failed', ['status' => $resp->status(), 'body' => $resp->body()]);

                return null;
            }
            $data = $resp->json('data');

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::warning('FortuneBotClient::start threw', ['err' => $e->getMessage()]);

            return null;
        }
    }

    /** Send a user message; returns ['session_id', 'reply', 'ai_provider'] or null. */
    public function send(User $user, string $sessionId, string $text): ?array
    {
        try {
            $resp = $this->client($user)->post($this->chatUrl('/send'), [
                'session_id' => $sessionId,
                'text' => $text,
            ]);
            if (! $resp->successful()) {
                $this->handleUnauthorized($user, $resp->status());
                Log::warning('FortuneBotClient::send failed', ['status' => $resp->status(), 'body' => $resp->body()]);

                return null;
            }
            $data = $resp->json('data');

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::warning('FortuneBotClient::send threw', ['err' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Interpret a tarot spread via Thaiprompt's API pool.
     * Tries the dedicated endpoint first; falls back to a chat-piped
     * tarot prompt when the endpoint is not available (404 / 405).
     *
     * Returns ['interpretation' => str, 'ai_provider' => str, 'ai_model' => str] or null.
     */
    public function interpretTarot(User $user, array $payload): ?array
    {
        try {
            $resp = $this->client($user)->post($this->fortuneUrl('/tarot/interpret'), $payload);
            if ($resp->successful()) {
                $data = $resp->json('data');
                if (is_array($data) && ! empty($data['interpretation'])) {
                    return $data;
                }
            }
            // 404/405 → endpoint not deployed yet; 422 → bad payload; anything else → log + fall through
            if (! in_array($resp->status(), [404, 405], true)) {
                $this->handleUnauthorized($user, $resp->status());
                Log::info('FortuneBotClient::interpretTarot non-200, falling back to chat pipeline', [
                    'status' => $resp->status(),
                    'body' => mb_substr((string) $resp->body(), 0, 400),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('FortuneBotClient::interpretTarot threw — falling back to chat', ['err' => $e->getMessage()]);
        }

        return $this->interpretViaChat($user, $payload);
    }

    /**
     * 🎁 (2026-07-26) ดูดวงฟรี 1 ใบ สั้น ๆ ผ่านคลังความรู้ + คีย์พูลของ Thaiprompt
     *
     * ⚠️ **ไม่มี fallback ไป chat pipeline โดยเจตนา** (ต่างจาก interpretTarot)
     *    เส้นนั้นเปิดห้องแชทใหม่ทุกครั้ง = ไปกินโควตา "คุยฟรีต่อวัน" ของลูกค้าเอง
     *    ทั้งที่เขาแค่กดปุ่มดูดวงฟรี — คืน null ให้ผู้เรียกคืนสิทธิ์แล้วให้ลองใหม่ดีกว่า
     *
     * @param  array{card:array,position_name?:string,max_chars?:int,customer_name?:string}  $payload
     * @return array{interpretation:string,next_questions?:array,ai_provider?:string,ai_model?:string}|null
     */
    public function freeTarot(User $user, array $payload): ?array
    {
        try {
            $resp = $this->client($user)->post($this->fortuneUrl('/tarot/free'), $payload);

            if ($resp->successful()) {
                $data = $resp->json('data');
                if (is_array($data) && ! empty($data['interpretation'])) {
                    return $data;
                }
            }

            // 404/405 = ยังไม่ได้ deploy ฝั่ง Thaiprompt — ไม่ใช่ token เสีย อย่าไปล้างทิ้ง
            if (! in_array($resp->status(), [404, 405], true)) {
                $this->handleUnauthorized($user, $resp->status());
            }

            Log::warning('FortuneBotClient::freeTarot non-200', [
                'status' => $resp->status(),
                'body' => mb_substr((string) $resp->body(), 0, 300),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FortuneBotClient::freeTarot threw', ['err' => $e->getMessage()]);
        }

        return null;
    }

    /** True if the upstream Thaiprompt endpoints are configured + reachable. */
    public function isAvailable(?User $user): bool
    {
        if (! $user || empty($user->thaiprompt_token)) {
            return false;
        }

        return $this->base() !== '';
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    /**
     * A 401/403 from the pool means the token is expired/revoked. First try a
     * refresh-token grant; only if that fails do we clear the token (so
     * isAvailable() flips to local fallback and the app's "re-link" path
     * triggers on the next gated call) instead of degrading silently forever.
     */
    private function handleUnauthorized(User $user, int $status): void
    {
        if ($status !== 401 && $status !== 403) {
            return;
        }
        // Renew in place if we hold a refresh token — next call uses the new one.
        if (app(ThaipromptTokenService::class)->refresh($user)) {
            Log::info('FortuneBotClient: refreshed thaiprompt_token after upstream '.$status, ['user_id' => $user->id]);

            return;
        }
        if (! empty($user->thaiprompt_token)) {
            $user->forceFill(['thaiprompt_token' => null])->saveQuietly();
            Log::info('FortuneBotClient: cleared dead thaiprompt_token after upstream '.$status, ['user_id' => $user->id]);
        }
    }

    /** Run a tarot prompt through the chat pipeline as a one-shot. */
    private function interpretViaChat(User $user, array $payload): ?array
    {
        if (! $this->isAvailable($user)) {
            return null;
        }
        $start = $this->start($user);
        $sessionId = $start['session_id'] ?? null;
        if (! $sessionId) {
            return null;
        }
        // Prefer the Card-First Mandate prompt built upstream by
        // TarotPromptBuilder; fall back to the legacy generic builder only if
        // the caller didn't supply one.
        $prompt = ! empty($payload['prompt']) ? $payload['prompt'] : $this->buildTarotPrompt($payload);
        $resp = $this->send($user, $sessionId, $prompt);
        if (! $resp || empty($resp['reply'])) {
            return null;
        }

        return [
            'interpretation' => $resp['reply'],
            'ai_provider' => $resp['ai_provider'] ?? 'thaiprompt',
            'ai_model' => 'pool',
        ];
    }

    private function buildTarotPrompt(array $payload): string
    {
        $spreadName = match ($payload['spread'] ?? '') {
            'tarot_celtic' => 'Celtic Cross 10 ใบ',
            'tarot_three' => 'ไพ่ 3 ใบ (อดีต-ปัจจุบัน-อนาคต)',
            default => 'ไพ่ยิปซี',
        };

        $lines = ['ขอคำพยากรณ์จากการเปิดไพ่ยิปซีในโหมดพรีเมียมดังต่อไปนี้:'];
        if (! empty($payload['question'])) {
            $lines[] = 'คำถามของลูกค้า: '.$payload['question'];
        }
        $lines[] = 'รูปแบบการเปิดไพ่: '.$spreadName;
        $lines[] = 'ไพ่ที่เปิดได้ตามลำดับตำแหน่ง:';
        foreach (($payload['cards'] ?? []) as $c) {
            $dir = ! empty($c['reversed']) ? 'กลับหัว' : 'ตั้งตรง';
            $lines[] = sprintf(
                ' %d. [%s] %s (%s) — %s',
                $c['position'] ?? 0,
                $c['position_label'] ?? '',
                $c['name_th'] ?? ($c['name_en'] ?? ''),
                $dir,
                $c['meaning'] ?? '',
            );
        }
        $lines[] = '';
        $lines[] = 'กรุณาวิเคราะห์ภาพรวม 4-6 ย่อหน้า ภาษาไทยล้วน เป็นกันเอง ไม่ขู่ และปิดท้ายด้วยคำแนะนำเชิงสร้างสรรค์';

        return implode("\n", $lines);
    }

    private function chatUrl(string $suffix): string
    {
        return $this->base().'/api/v1/juntra/chat/mae-mor'.$suffix;
    }

    /**
     * บัญชีรับเงินของแม่หมอ — ตัวเดียวกับที่บอท FB/LINE ใช้
     *
     * เจ้าของกำหนด (2026-07-25): ค่าการชำระเงินให้ใช้ค่าเดิมของแม่หมอชุดเดียวกัน
     * ไม่ให้ตั้งแยกที่เว็บ เพราะถ้าตั้งสองที่แล้วไม่ตรงกัน ลูกค้าเว็บจะโอนเข้า
     * คนละบัญชีกับที่ SlipOK/SMS ผูกไว้ → ตรวจสลิปอัตโนมัติจับปลายทางไม่ได้
     *
     * cache 10 นาที: เป็นข้อมูลที่แทบไม่เปลี่ยน แต่ถูกอ่านทุกครั้งที่ลูกค้ากด
     * เติมเงิน จะยิง upstream ทุกครั้งไม่ไหว — และถ้า upstream ล่มชั่วคราว
     * ค่าที่ cache ไว้ยังทำให้เติมเงินต่อได้
     *
     * @return array{promptpay_id:?string,account_name:?string,bank_name:?string}|null
     */
    public function payoutAccount(?User $user): ?array
    {
        if (! $this->isAvailable($user)) {
            return null;
        }

        return Cache::remember('juntra:payout_account', now()->addMinutes(10), function () use ($user) {
            try {
                $resp = $this->client($user)->get($this->base().'/api/v1/juntra/payment/account');
                if ($resp->successful()) {
                    $data = $resp->json('data');
                    if (is_array($data) && ! empty($data['promptpay_id'])) {
                        return $data;
                    }
                }
                Log::info('FortuneBotClient::payoutAccount unavailable', ['status' => $resp->status()]);
            } catch (\Throwable $e) {
                Log::warning('FortuneBotClient::payoutAccount threw', ['err' => $e->getMessage()]);
            }

            // cache ค่า null ไม่ได้ (Cache::remember จะยิงซ้ำทุกครั้ง) — ยอมรับได้
            // เพราะเคสนี้คือ upstream ล่ม ซึ่งควรลองใหม่ทุกครั้งอยู่แล้ว
            return null;
        });
    }

    /**
     * ดูดวงเชิงลึก 39฿ — แพ็กเดียวกับที่บอท FB/LINE ขาย
     *
     * คืน null เมื่อยังไม่ได้เชื่อมบัญชี / upstream ล่ม / endpoint ยังไม่ deploy
     * ผู้เรียกต้องคืนเครดิตลูกค้าเมื่อได้ null — ห้ามส่งคำทำนายปลอมแทน
     * เพราะนี่คือสินค้าที่ลูกค้าจ่ายเงินซื้อโดยเฉพาะ
     *
     * @param  array<int,string>  $questions
     * @return array{reading:string,ai_provider:?string,ai_model:?string}|null
     */
    public function deepReading(?User $user, array $questions, ?string $birthDate, ?string $name = null): ?array
    {
        if (! $this->isAvailable($user) || empty($questions)) {
            return null;
        }

        try {
            $resp = $this->client($user)->post($this->fortuneUrl('/deep'), array_filter([
                'questions' => array_values($questions),
                'birth_date' => $birthDate,
                'name' => $name,
            ], fn ($v) => $v !== null && $v !== ''));

            if ($resp->successful()) {
                $data = $resp->json('data');
                if (is_array($data) && ! empty($data['reading'])) {
                    return $data;
                }
            }

            if (! in_array($resp->status(), [404, 405], true)) {
                $this->handleUnauthorized($user, $resp->status());
            }
            Log::info('FortuneBotClient::deepReading unavailable', [
                'status' => $resp->status(),
                'body' => mb_substr((string) $resp->body(), 0, 300),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FortuneBotClient::deepReading threw', ['err' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * ตรวจสลิปโอนเงินผ่าน SlipOK ของ Thaiprompt (บัญชี + โควตา + flood guard
     * ก้อนเดียวกับบอท FB/LINE — ไม่แยกโควตาสองที่ให้แอดมินต้องดูแลซ้ำ)
     *
     * คืน null เมื่อยังไม่ได้เชื่อมบัญชี / endpoint ยังไม่ deploy / ตรวจไม่ได้
     * ผู้เรียกต้องถือว่า null = "ตรวจอัตโนมัติไม่ได้" แล้วปล่อยให้แอดมินตรวจมือ
     * ห้ามตีความว่าเป็นการปฏิเสธสลิป
     *
     * @return array{ok:bool,trans_ref:?string,amount:?float,receiver_matches:bool,message:string}|null
     */
    public function verifySlip(?User $user, string $absolutePath): ?array
    {
        if (! $this->isAvailable($user) || ! is_file($absolutePath)) {
            return null;
        }

        try {
            $resp = $this->client($user)
                ->attach('slip', file_get_contents($absolutePath), basename($absolutePath))
                ->post($this->base().'/api/v1/juntra/payment/verify-slip');

            if ($resp->successful()) {
                $data = $resp->json('data');

                return is_array($data) ? $data : null;
            }

            if (! in_array($resp->status(), [404, 405], true)) {
                $this->handleUnauthorized($user, $resp->status());
                Log::info('FortuneBotClient::verifySlip non-200 — falling back to manual review', [
                    'status' => $resp->status(),
                    'body' => mb_substr((string) $resp->body(), 0, 300),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('FortuneBotClient::verifySlip threw — falling back to manual review', [
                'err' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function fortuneUrl(string $suffix): string
    {
        return $this->base().'/api/v1/juntra/fortune'.$suffix;
    }

    private function base(): string
    {
        return rtrim((string) Setting::get('thaiprompt_base_url', 'https://main.thaiprompt.online'), '/');
    }

    private function client(User $user): PendingRequest
    {
        // Tight timeouts so the chat UI never hangs more than ~22s
        // when upstream is unreachable. Single try (no retry storm).
        return Http::acceptJson()
            ->withToken((string) $user->thaiprompt_token)
            ->connectTimeout(4)
            ->timeout(20); // AI calls can be slow on Pro mode
    }
}
