<?php

namespace App\Services;

use App\Models\Reading;
use App\Models\Setting;
use App\Services\FortuneBot\TarotPromptBuilder;
use App\Support\TarotSpreads;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Single AI gateway for all fortune-telling features.
 * Reads provider/model/api_key from Settings (set via Filament admin panel).
 * Falls back to deterministic heuristic responses when API key is missing.
 */
class AiOracle
{
    public function provider(): string
    {
        return Setting::get('ai_provider', 'gemini');
    }

    public function model(): string
    {
        return Setting::get('ai_model', 'gemini-2.0-flash-exp');
    }

    public function systemPrompt(): string
    {
        return Setting::get('ai_system_prompt')
            ?: 'คุณคือ "แม่หมอจันทรา" หมอดูออนไลน์ที่สุภาพ อบอุ่น ตอบเป็นภาษาไทยล้วน เข้าใจง่าย ไม่ขู่ ให้คำแนะนำเชิงสร้างสรรค์เสมอ';
    }

    public function isConfigured(): bool
    {
        return !empty(Setting::get('ai_api_key'));
    }

    public function interpretTarotReading(Reading $reading): string
    {
        $cards = $reading->tarotCards()->with('card')->orderBy('position')->get();

        // Same Card-First Mandate prompt the upstream Mae-Mor bot uses, so the
        // local fallback reads card × position (ตรง/ฟันธง) instead of generic.
        $prompt = TarotPromptBuilder::userPrompt($reading);

        return $this->complete($prompt, fallback: $this->fallbackTarot($reading, $cards));
    }

    /* ⛔ ลบ generateDailyHoroscope() ออกแล้ว (2026-07-26)
     *
     * fallback ของเมธอดนั้นคืนข้อความชุดเดียวกันเป๊ะให้ทั้ง 12 ราศี ต่างกันแค่
     * เลขนำโชค/สี/ไพ่ ที่สุ่มจาก crc32 — และเพราะ `ai_api_key` บนโปรดักชันเป็น
     * ค่าว่างมาตลอด เส้นทางนั้นจึงเป็น DEFAULT ไม่ใช่ทางสำรอง
     *
     * ตอนนี้ดวงรายวันไปที่ App\Services\DailyHoroscopeWriter แทน — คีย์พูลก่อน
     * แล้วค่อยเขียนเองจากธาตุ/ดาวเจ้าเรือนของราศี + ดิถีและวารจริงของวันนั้น
     */

    /* ⛔ ลบ adviseAuspiciousDates() ออกแล้ว (2026-07-26)
     *
     * เมธอดนั้นคืน fallback ตายตัวประโยคเดียวเมื่อไม่มีคีย์ Gemini ซึ่งเป็นสภาพจริง
     * บนโปรดักชัน (`ai_api_key` = '' มาตลอด) → ลูกค้าจ่าย ฿19 แล้วได้ข้อความเดียวกัน
     * ทุกคน ทุกโอกาส ทุกช่วงวัน ไม่อ้างถึงวันที่คำนวณได้เลยสักวัน
     *
     * ตอนนี้ฤกษ์ยามไปที่ App\Services\AuspiciousAdvisor แทน — ใช้คีย์พูลของ Thaiprompt
     * ชุดเดียวกับไพ่/แชท และมี fallback ที่เขียนจากผลคำนวณจริงของลูกค้าคนนั้น
     * อย่าเพิ่มเมธอดแนวนี้กลับเข้ามาโดยไม่มีทางที่คืน "เนื้อหาจริง" ให้ครบทุกเส้นทาง
     */

    /**
     * Palmistry REQUIRES a vision model — there is NO heuristic fallback for
     * reading an image. So this THROWS on any failure (unconfigured OR a
     * dead/empty Gemini response) instead of returning a "sorry, not ready"
     * string. That matters because the controller debits the wallet BEFORE
     * calling this: a thrown error trips the controller's refund path, while
     * a returned string would silently charge the user for a non-answer.
     * Callers should ALSO gate on isConfigured() before debiting so the
     * common "no key set" case never charges at all.
     *
     * @throws \RuntimeException when no AI is configured or the call fails.
     */
    public function analyzePalmImage(string $absolutePath, ?string $question): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('palmistry: AI not configured');
        }

        // Gemini supports image input via inlineData; this method only attempts when configured.
        $base64 = base64_encode(file_get_contents($absolutePath));
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => 'คุณคือแม่หมอจันทรา ช่วยอ่านลายมือจากรูปที่แนบมา วิเคราะห์เส้นชีวิต เส้นใจ เส้นปัญญา ตอบ 4-5 ย่อหน้า ภาษาไทย ' . ($question ? "คำถาม: {$question}" : '')],
                    ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $base64]],
                ],
            ]],
        ];

        $reply = $this->callGemini($payload);
        if (!$reply || trim($reply) === '') {
            throw new \RuntimeException('palmistry: empty AI response');
        }
        return $reply;
    }

    public function chat(array $messages): string
    {
        $prompt = collect($messages)->map(function ($m) {
            $who = $m->role === 'user' ? 'ลูกค้า' : 'แม่หมอ';
            return "{$who}: {$m->content}";
        })->implode("\n");

        $prompt .= "\nแม่หมอ:";
        return $this->complete($prompt, fallback: $this->fallbackChat($messages));
    }

    /* ============================================================
       LOW LEVEL
       ============================================================ */

    private function complete(string $userPrompt, ?string $fallback = null): string
    {
        if (!$this->isConfigured()) {
            return $fallback ?? 'ระบบ AI ยังไม่พร้อมในตอนนี้ กรุณารอสักครู่';
        }

        try {
            $payload = [
                'contents' => [[
                    'parts' => [['text' => $this->systemPrompt() . "\n\n" . $userPrompt]],
                ]],
            ];
            $reply = $this->callGemini($payload);
            // Blank-check, not null-check: Gemini can return HTTP 200 with an
            // empty/whitespace text part (e.g. truncated or filtered output).
            // `?? ` only catches null, so "" would slip through and get saved
            // as an empty reading — charged but blank. Treat empty as failure.
            return (is_string($reply) && trim($reply) !== '')
                ? $reply
                : ($fallback ?? 'ระบบไม่สามารถตอบในขณะนี้');
        } catch (\Throwable $e) {
            Log::warning('AiOracle complete failed: ' . $e->getMessage());
            return $fallback ?? 'ระบบไม่สามารถตอบในขณะนี้';
        }
    }

    private function callGemini(array $payload): ?string
    {
        $apiKey = Setting::get('ai_api_key');
        $model  = $this->model();
        if (!$apiKey) return null;

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $resp = Http::timeout(30)->post($url, $payload);
        if (!$resp->successful()) {
            Log::warning('Gemini call failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return null;
        }

        return $resp->json('candidates.0.content.parts.0.text');
    }

    /**
     * Deterministic reading used only when no AI key is configured. Still
     * reads card × position (using each slot's `asks`) so even the offline
     * path is on-topic rather than a generic blurb.
     */
    private function fallbackTarot(Reading $reading, $cards): string
    {
        $key      = TarotSpreads::keyFromType($reading->type);
        $asks     = $key ? array_column(TarotSpreads::positions($key), 'asks') : [];
        $question = trim((string) $reading->question);

        $intro = $question !== ''
            ? "เกี่ยวกับคำถาม \"{$question}\" ไพ่ที่คุณเปิดได้บอกเล่าทีละตำแหน่งดังนี้:"
            : "ไพ่ที่คุณเปิดได้บอกเล่าทีละตำแหน่งดังนี้:";

        $body = $cards->map(function ($pc) use ($asks) {
            $dir     = $pc->reversed ? '(กลับหัว)' : '(ตั้งตรง)';
            $meaning = $pc->reversed ? $pc->card->reversed_meaning_th : $pc->card->upright_meaning_th;
            $ask     = $asks[$pc->position - 1] ?? null;
            $head    = "**{$pc->position_label}** — {$pc->card->name_th} {$dir}";
            $hint    = $ask ? "_({$ask})_\n" : '';
            return "{$head}\n{$hint}{$meaning}";
        })->implode("\n\n");

        return "{$intro}\n\n{$body}\n\nคำแนะนำของแม่หมอ: อ่านไพ่แต่ละใบตามตำแหน่งของมัน แล้วร้อยเป็นเรื่องเดียวกัน — ไพ่ตั้งตรงคือพลังที่ไหลลื่น ไพ่กลับหัวคือสิ่งที่ยังติดขัดหรือต้องระวัง เลือกก้าวไปในทางที่ไพ่ส่วนใหญ่ชี้ด้วยความมั่นใจนะคะ";
    }

    private function fallbackChat(array $messages): string
    {
        $last = end($messages);
        $userText = $last->content ?? '';
        return "แม่หมอได้ยินสิ่งที่คุณเล่าแล้ว — \"{$userText}\" \n\nในขณะนี้ระบบ AI ของแม่หมอกำลังปรับเทียบ กรุณาลองใหม่อีกครั้งในอีกสักครู่ หรือลองใช้บริการเปิดไพ่ยิปซีและดวงรายวันของราศีของคุณก่อนได้นะคะ";
    }
}
