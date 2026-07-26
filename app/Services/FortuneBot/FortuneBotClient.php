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
    /** บัญชีรับเงินของร้าน — ค่าเดียวกันทั้งเว็บ ไม่ผูกกับผู้ใช้คนไหน */
    public const PAYOUT_CACHE_KEY = 'juntra:payout_account';

    /** ค่าที่ใส่แทน null ใน cache เพื่อกันยิง upstream ซ้ำตอนล่ม */
    private const PAYOUT_MISS = 'miss';

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

    /**
     * คำแนะนำฤกษ์ยาม — ใช้คีย์พูลชุดเดียวกับบอท FB/LINE
     *
     * (2026-07-26) ก่อนหน้านี้ฤกษ์ยามเป็นบริการเดียวที่ยังเรียก AiOracle (Gemini
     * ในเครื่อง) ตรง ๆ ซึ่ง `ai_api_key` บนโปรดักชันเป็นค่าว่าง → ลูกค้าจ่าย ฿19
     * แล้วได้ข้อความ fallback ตายตัวบรรทัดเดียวเหมือนกันทุกคน
     *
     * โครงเดียวกับ interpretTarot: ลอง endpoint เฉพาะก่อน ถ้ายังไม่ deploy ฝั่ง
     * Thaiprompt (404/405) ค่อยไหลผ่านท่อแชท ซึ่งใช้พูลเดียวกันและใช้ได้จริงบน prod
     * (ต่างจาก freeTarot ที่ห้ามไหลไปท่อแชทเพราะเป็นของฟรี — อันนี้ลูกค้าจ่ายแล้ว)
     *
     * @param  array{prompt:string}  $payload
     * @return array{advice:string,ai_provider?:string,ai_model?:string}|null
     */
    public function auspiciousAdvice(?User $user, array $payload): ?array
    {
        if (! $this->isAvailable($user) || empty($payload['prompt'])) {
            return null;
        }

        try {
            $resp = $this->client($user)->post($this->fortuneUrl('/auspicious'), $payload);
            if ($resp->successful()) {
                $data = $resp->json('data');
                if (is_array($data) && ! empty($data['advice'])) {
                    return $data;
                }
            }
            if (! in_array($resp->status(), [404, 405], true)) {
                $this->handleUnauthorized($user, $resp->status());
                Log::info('FortuneBotClient::auspiciousAdvice non-200 — falling back to chat pipeline', [
                    'status' => $resp->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('FortuneBotClient::auspiciousAdvice threw — falling back to chat', ['err' => $e->getMessage()]);
        }

        $start = $this->start($user);
        $sessionId = $start['session_id'] ?? null;
        if (! $sessionId) {
            return null;
        }
        $reply = $this->send($user, $sessionId, $payload['prompt']);
        if (! $reply || empty($reply['reply'])) {
            return null;
        }

        return [
            'advice'      => $reply['reply'],
            'ai_provider' => $reply['ai_provider'] ?? 'thaiprompt',
            'ai_model'    => 'pool',
        ];
    }

    /**
     * ดวงรายวันของราศี — ใช้คลังความรู้ + คีย์พูลเดียวกับบอท FB/LINE
     *
     * ⚠️ **ไม่มี fallback ไปท่อแชท** โดยเจตนา (เหมือน freeTarot): ดวงรายวันเป็น
     * ของฟรีและเปิดดูได้โดยไม่ต้องล็อกอิน ถ้าไหลไปท่อแชทจะไปกินโควตาแชทของบัญชี
     * ที่เรายืม token มา ทั้งที่ไม่มีใครจ่ายอะไร — ให้ผู้เรียกไปเขียนเองดีกว่า
     *
     * @param  array{zodiac:string,zodiac_th:string,date:string}  $payload
     * @return array<string,mixed>|null
     */
    public function dailyHoroscope(?User $user, array $payload): ?array
    {
        if (! $this->isAvailable($user)) {
            return null;
        }

        try {
            $resp = $this->client($user)->post($this->fortuneUrl('/horoscope/daily'), $payload);
            if ($resp->successful()) {
                $data = $resp->json('data');
                if (is_array($data) && ! empty($data['summary'])) {
                    return $data;
                }
            }
            // 404/405 = ยังไม่ได้ deploy ฝั่ง Thaiprompt — ไม่ใช่ token เสีย อย่าล้างทิ้ง
            if (! in_array($resp->status(), [404, 405], true)) {
                $this->handleUnauthorized($user, $resp->status());
                Log::info('FortuneBotClient::dailyHoroscope non-200', ['status' => $resp->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('FortuneBotClient::dailyHoroscope threw', ['err' => $e->getMessage()]);
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
     * cache 60 วินาที: ถูกอ่านทุกครั้งที่ลูกค้ากดเติมเงิน จะยิง upstream ทุกครั้ง
     * ไม่ไหว — แต่ตั้งสั้น ๆ เพราะเป็น "บัญชีที่แอดมินติ๊กไว้" ซึ่งสลับได้ตลอด
     * ถ้า cache ยาว หลังสลับบัญชีเว็บจะยังสร้าง QR เป็นบัญชีเก่า ลูกค้าโอนเข้า
     * บัญชีที่ SlipOK ไม่ได้ผูกไว้ → ตรวจสลิปตีกลับทั้งที่โอนถูก
     * (ฝั่งแม่หมอเองก็ cache settings แค่ 5 วินาทีด้วยเหตุผลเดียวกัน)
     *
     * ⚠️ อ่าน cache "ก่อน" เช็ค token โดยเจตนา (2026-07-26): ค่าที่ได้คือบัญชีของ
     * ร้าน ไม่ใช่ข้อมูลส่วนตัวของผู้เรียก ใครดึงมาได้ก็ใช้ร่วมกันได้ทั้งเว็บ
     * เดิมเช็ค token ก่อน → ลูกค้าที่สมัครด้วยเบอร์/อีเมล (ไม่มี thaiprompt_token)
     * ได้ null เสมอ แม้ cache จะอุ่นอยู่ = หน้าเติมเงินขึ้น "แอดมินยังไม่ได้ตั้งค่า"
     *
     * @return array{promptpay_id:?string,account_name:?string,bank_name:?string}|null
     */
    public function payoutAccount(?User $user): ?array
    {
        $cached = Cache::get(self::PAYOUT_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        // negative cache: upstream เพิ่งล่ม — อย่ายิงซ้ำทุก request ไม่งั้นหน้า
        // เติมเงินจะค้างรอ connect timeout ทุกครั้งที่เปิด (ยิ่งตอนนี้ resolve()
        // ยืม token ของบัญชีที่ผูกไว้มาเรียกแทนลูกค้าที่ยังไม่ผูก = โดนทุกคน)
        if ($cached === self::PAYOUT_MISS) {
            return null;
        }
        if (! $this->isAvailable($user)) {
            return null;
        }

        try {
            $resp = $this->client($user)->get($this->base().'/api/v1/juntra/payment/account');
            if ($resp->successful()) {
                $data = $resp->json('data');
                if (is_array($data) && ! empty($data['promptpay_id'])) {
                    Cache::put(self::PAYOUT_CACHE_KEY, $data, now()->addSeconds(60));

                    return $data;
                }
            }
            // token ตายให้ต่ออายุ/ล้างทิ้ง ไม่งั้น PayoutAccount::serviceUser()
            // จะหยิบบัญชีเดิมที่ยิงไม่ผ่านซ้ำ ๆ ไม่ขยับไปตัวถัดไปสักที
            $this->handleUnauthorized($user, $resp->status());
            Log::info('FortuneBotClient::payoutAccount unavailable', ['status' => $resp->status()]);
        } catch (\Throwable $e) {
            Log::warning('FortuneBotClient::payoutAccount threw', ['err' => $e->getMessage()]);
        }

        Cache::put(self::PAYOUT_CACHE_KEY, self::PAYOUT_MISS, now()->addSeconds(30));

        return null;
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
