<?php

namespace App\Services;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\Zodiac;
use App\Services\FortuneBot\TarotPromptBuilder;
use App\Support\TarotSpreads;
use Carbon\Carbon;
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

    public function generateDailyHoroscope(Zodiac $zodiac, Carbon $date): array
    {
        $prompt = "เขียนคำพยากรณ์ประจำวันสำหรับราศี{$zodiac->name_th} วันที่ {$date->format('d/m/Y')} แบ่ง 4 หัวข้อ: ความรัก, การงาน, การเงิน, สุขภาพ — แต่ละหัวข้อ 2-3 ประโยค ปิดท้ายด้วยเลขนำโชค (1-99) สีมงคล (สีไทย) และไพ่ประจำวัน (เลือกจากไพ่ Major Arcana 1 ใบ) — ตอบในรูปแบบ JSON: {\"summary\": \"...\", \"love\": \"...\", \"career\": \"...\", \"money\": \"...\", \"health\": \"...\", \"lucky_number\": \"...\", \"lucky_color\": \"...\", \"lucky_card\": \"...\"}";

        $raw = $this->complete($prompt, fallback: null);

        $parsed = $this->extractJson($raw);
        if ($parsed) {
            return [
                'summary' => $parsed['summary'] ?? "วันนี้ราศี{$zodiac->name_th}จะมีพลังบวกในหลายด้าน",
                'love'    => $parsed['love']    ?? 'ความรักก้าวหน้าอย่างเรียบง่าย',
                'career'  => $parsed['career']  ?? 'มีโอกาสใหม่เข้ามา',
                'money'   => $parsed['money']   ?? 'การเงินเริ่มเข้าสู่ความสมดุล',
                'health'  => $parsed['health']  ?? 'พักผ่อนให้พอเพียง',
                'lucky_number' => (string) ($parsed['lucky_number'] ?? random_int(1, 99)),
                'lucky_color'  => $parsed['lucky_color'] ?? 'ทองอ่อน',
                'lucky_card'   => $parsed['lucky_card']  ?? 'The Star',
                'ai_generated' => $this->isConfigured(),
            ];
        }

        // Heuristic fallback (so site works without API key configured)
        $luckyColors = ['ทองอ่อน','ม่วงเข้ม','ฟ้าคราม','เขียวมรกต','ขาวงาช้าง','ชมพูกุหลาบ','แดงทับทิม'];
        $luckyCards  = ['The Magician','The Sun','The Star','The World','The Empress','The Lovers','Wheel of Fortune'];
        $seed = crc32($zodiac->slug . $date->toDateString());
        return [
            'summary' => "วันนี้ราศี{$zodiac->name_th}เปิดรับพลังจักรวาลที่หล่อเลี้ยงด้วยความหวังและสติ",
            'love'    => 'ความรักจะเดินไปอย่างนุ่มนวล หากใจเปิด คนรอบข้างจะเข้าหาเอง',
            'career'  => 'งานที่ค้างไว้จะคืบหน้า ผู้ใหญ่เริ่มเห็นความตั้งใจของคุณ',
            'money'   => 'การเงินสมดุลขึ้น ระวังการใช้จ่ายฟุ่มเฟือยเพียงเล็กน้อย',
            'health'  => 'พักผ่อนเพียงพอ ดื่มน้ำ และยืดเส้นยืดสายช่วยให้สดชื่น',
            'lucky_number' => (string) (($seed % 99) + 1),
            'lucky_color'  => $luckyColors[$seed % count($luckyColors)],
            'lucky_card'   => $luckyCards[$seed % count($luckyCards)],
            'ai_generated' => false,
        ];
    }

    public function adviseAuspiciousDates(string $occasion, array $candidates): string
    {
        $list = collect($candidates)->take(10)->map(fn($d) => '- ' . $d['label'] . " (คะแนน {$d['score']}/10)")->implode("\n");
        $prompt = "ผู้ใช้กำลังหาฤกษ์ดีสำหรับ: {$occasion}\nวันที่ระบบคำนวณว่าเหมาะสม:\n{$list}\n\nช่วยอธิบายว่าวันที่เหล่านี้ดีอย่างไรในบริบทไทย เลือก 3 วันที่ดีที่สุด แนะนำเวลาที่เหมาะสมในวันนั้น และข้อแนะนำการเตรียมตัว — ตอบเป็นภาษาไทย 4-6 ย่อหน้า";
        return $this->complete($prompt, fallback: "ระบบได้คำนวณวันมงคลตามหลักเลขศาสตร์และเลขผลรวมหารด้วย 9 ลงตัว — แนะนำให้เลือกวันที่คะแนนสูงสุด 3 อันดับ และทำพิธีในช่วงเช้า 06.09–09.09 น. ซึ่งเป็นเวลามงคลตามคติไทย");
    }

    public function analyzePalmImage(string $absolutePath, ?string $question): string
    {
        if (!$this->isConfigured()) {
            return "ระบบดูลายมือ AI ยังไม่ได้เชื่อมต่อกับ API — กรุณาให้ผู้ดูแลเปิดใช้งานในหน้า Admin → Settings (AI). คำถามของคุณถูกบันทึกไว้แล้ว เมื่อระบบพร้อม คำตอบจะถูกส่งไปที่อีเมลของคุณ";
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

        return $this->callGemini($payload) ?? 'ระบบไม่สามารถอ่านลายมือได้ในตอนนี้ กรุณาลองใหม่อีกครั้ง';
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
            return $reply ?? ($fallback ?? 'ระบบไม่สามารถตอบในขณะนี้');
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

    private function extractJson(?string $raw): ?array
    {
        if (!$raw) return null;
        if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
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
