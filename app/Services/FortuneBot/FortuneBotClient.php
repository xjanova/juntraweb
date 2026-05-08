<?php

namespace App\Services\FortuneBot;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP wrapper around Thaiprompt's "Mae Mor Chantra" web/mobile chat API.
 *
 *   POST /api/v1/juntra/chat/mae-mor/start         → {data: {session_id, greeting}}
 *   POST /api/v1/juntra/chat/mae-mor/send          → {data: {session_id, reply, ai_provider}}
 *   GET  /api/v1/juntra/chat/mae-mor/sessions/{id} → {data: {history: [...]}}
 *
 * The remote endpoints reuse the exact same FortuneAIService chat path that
 * powers the Facebook Messenger bot, so juntra users get identical
 * persona + AI quality. juntra holds NO API keys for AI providers — all
 * key rotation, sensitive-mode detection, billing, etc. happen upstream.
 */
class FortuneBotClient
{
    /** Start a new conversation. Returns ['session_id' => str, 'greeting' => str] or null on failure. */
    public function start(User $user): ?array
    {
        try {
            $resp = $this->client($user)->post($this->url('/start'));
            if (!$resp->successful()) {
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
            $resp = $this->client($user)->post($this->url('/send'), [
                'session_id' => $sessionId,
                'text'       => $text,
            ]);
            if (!$resp->successful()) {
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

    /** True if the upstream Thaiprompt chat endpoints are configured + reachable. */
    public function isAvailable(User $user): bool
    {
        if (empty($user->thaiprompt_token)) {
            return false;
        }
        $base = (string) Setting::get('thaiprompt_base_url');
        return $base !== '';
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    private function url(string $suffix): string
    {
        $base = rtrim((string) Setting::get('thaiprompt_base_url', 'https://thaiprompt.com'), '/');
        return $base . '/api/v1/juntra/chat/mae-mor' . $suffix;
    }

    private function client(User $user): PendingRequest
    {
        // Tight timeouts so the chat UI never hangs more than ~12s
        // when upstream is unreachable. Single try (no retry storm).
        return Http::acceptJson()
            ->withToken((string) $user->thaiprompt_token)
            ->connectTimeout(4)
            ->timeout(20); // AI calls can be slow on Pro mode
    }
}
