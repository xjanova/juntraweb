<?php

namespace App\Services\FortuneBot;

use App\Models\Reading;
use App\Support\TarotSpreads;

/**
 * Builds the tarot-interpretation prompt the way "แม่หมอจันทรา" reads on
 * Thaiprompt — the CARD-FIRST MANDATE.
 *
 * The whole point: the answer must come FROM the drawn cards × their
 * positions (weight 100%), not from generic life-coaching that is true for
 * anyone. This is what makes the reading land as ฟันธง/แม่นยำ instead of
 * safe filler. Shared by:
 *   - AiOracle::interpretTarotReading()           (local Gemini fallback)
 *   - FortuneBotClient chat-pipe fallback          (Thaiprompt pool)
 * so EVERY path that ever renders a juntra reading uses the same method as
 * the upstream Mae-Mor bot.
 *
 * The dedicated upstream endpoint receives the same material as structured
 * `cards[]` (incl. each position's `asks`) via payloadCards().
 */
class TarotPromptBuilder
{
    /**
     * The supreme read-FIRST rulebook, injected at the top of every prompt.
     * Lifted from the production Mae-Mor "buildCardFirstMandate".
     */
    public static function mandate(): string
    {
        return <<<TXT
คุณคือ "แม่หมอจันทรา" หมอดูไพ่ยิปซี (Rider-Waite) ที่อ่านไพ่เก่ง อบอุ่น แต่ฟันธงตรงไปตรงมา

[กฎเหล็กในการอ่านไพ่ — สำคัญที่สุด อ่านก่อนเสมอ]
1. ไพ่ที่เปิดได้ × ตำแหน่งของมัน = แหล่งความจริงเดียว น้ำหนัก 100% ของทุกประโยค ห้ามทำนายลอย ๆ ตามหลักชีวิตทั่วไป
2. ทุกข้อสรุปต้องงอกจากไพ่ใบใดใบหนึ่งที่ตำแหน่งใดตำแหน่งหนึ่ง — ถ้าบอกไม่ได้ว่ามาจากไพ่ใบไหน "อย่าพูด"
3. วิธีอ่าน: ความหมายไพ่ × สิ่งที่ตำแหน่งนั้นถาม = ข้อสรุปเฉพาะของลูกค้าคนนี้ (อ่านไพ่ใบเดียวกันคนละตำแหน่งต้องได้คำตอบคนละแบบ)
4. ตั้งตรง = พลังไหลลื่น/ด้านบวกของไพ่ ; กลับหัว = ติดขัด ตรงกันข้าม หรือพลังที่เก็บกดอยู่ภายใน — กลับหัว/ตั้งตรงต้องเปลี่ยนคำทำนายจริง ไม่ใช่พูดเหมือนกัน
5. ฟันธงตามที่ไพ่บอก ถ้าไพ่ดีบอกว่าดี ถ้าไพ่เตือนก็เตือนตรง ๆ ห้ามกลบไพ่ร้ายด้วยคำปลอบใจ และห้ามเซฟตัวจนไม่ฟันธง
6. ห้ามใช้ประโยคที่ "จริงกับทุกคน" เช่น "ทุกอย่างอยู่ที่ใจเรา / แล้วแต่กรรม / อยู่ที่ตัวคุณเอง" — แบบทดสอบ: ถ้าประโยคนี้เอาไปแปะกับดวงของลูกค้าคนอื่นได้ แปลว่าไม่ได้มาจากไพ่ → ตัดทิ้ง
7. เตือนเฉพาะสิ่งที่ไพ่ชี้จริง (เช่น สุขภาพ การเงิน การเดินทาง คนรอบข้าง) บอกระดับความแรงได้ (แรงมาก/เล็กน้อย) แต่ห้ามขายความกลัว ห้ามปั้นเรื่องร้ายที่ไพ่ไม่ได้บอก
8. ถ้าลูกค้าดูเปราะบาง/อยู่ในภาวะวิกฤต ให้เตือนอย่างอ่อนโยน ไม่ขยี้ซ้ำ
9. ห้ามสุ่มจับหรือเพิ่มไพ่นอกเหนือจากที่เปิดได้ ตอบจากไพ่ที่ให้มาเท่านั้น
10. ภาษาไทยล้วน โทนแม่หมอจันทรา เป็นกันเอง อ่านเข้าใจง่าย ไม่ทับศัพท์เกินจำเป็น
TXT;
    }

    /**
     * The full user-turn prompt for a reading (mandate + spread context +
     * cards-by-position + output shape). Used by the local and chat-pipe paths.
     */
    public static function userPrompt(Reading $reading): string
    {
        $key  = TarotSpreads::keyFromType($reading->type);
        $meta = $key ? TarotSpreads::get($key) : null;

        $spreadName = $meta['name_th'] ?? 'ไพ่ยิปซี';
        $depth      = $meta['depth']   ?? '3-5 ย่อหน้า';
        $question   = trim((string) $reading->question);

        $lines = [];
        $lines[] = static::mandate();
        $lines[] = '';
        $lines[] = "── ข้อมูลการเปิดไพ่ครั้งนี้ ──";
        $lines[] = "รูปแบบการวางไพ่: {$spreadName} (" . static::cardCountLabel($reading) . ")";
        $lines[] = $question !== ''
            ? "คำถามของลูกค้า: \"{$question}\""
            : "ลูกค้าไม่ได้ระบุคำถาม — ให้อ่านภาพรวมดวงชะตาตามไพ่ที่เปิดได้";
        $lines[] = '';
        $lines[] = "ไพ่ที่ลูกค้าเปิดได้ตามลำดับตำแหน่ง (อ่านไพ่ × หน้าที่ของตำแหน่ง):";
        $lines[] = static::cardBlock($reading);
        $lines[] = '';
        $lines[] = "── สิ่งที่ต้องส่งมอบ ──";
        $lines[] = "1) อ่านทีละตำแหน่งก่อน: ไพ่ใบนี้ที่ตำแหน่งนี้ (ตั้งตรง/กลับหัว) กำลังบอกอะไรเกี่ยวกับสิ่งที่ตำแหน่งนั้นถาม";
        $lines[] = "2) ร้อยทุกตำแหน่งเข้าเป็นเรื่องเดียวกัน ว่าไพ่ทั้งหมดเชื่อมโยงเป็นเรื่องราวของลูกค้าอย่างไร";
        if ($question !== '') {
            $lines[] = "3) ฟันธงตอบคำถาม \"{$question}\" ให้ชัด โดยอ้างอิงว่ามาจากไพ่ใบใดตำแหน่งใด";
        } else {
            $lines[] = "3) ฟันธงสรุปแนวโน้มดวงชะตาให้ชัด โดยอ้างอิงไพ่ที่เปิดได้";
        }
        $lines[] = "4) ปิดท้ายด้วยคำแนะนำที่ทำได้จริง ซึ่งงอกมาจากไพ่ (ไม่ใช่คำแนะนำทั่วไป)";
        $lines[] = "ความยาวที่เหมาะสม: {$depth}";

        return implode("\n", $lines);
    }

    /**
     * Structured cards for the dedicated upstream endpoint — same material,
     * machine-readable, INCLUDING each position's `asks` so the pool reads
     * card × position too.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function payloadCards(Reading $reading): array
    {
        $cards = $reading->tarotCards()->with('card')->orderBy('position')->get();
        $key   = TarotSpreads::keyFromType($reading->type);
        $asks  = $key ? array_column(TarotSpreads::positions($key), 'asks') : [];

        return $cards->map(function ($pc) use ($asks) {
            $card = $pc->card;
            $reversed = (bool) $pc->reversed;

            return [
                'position'       => $pc->position,
                'position_label' => $pc->position_label,
                'position_asks'  => $asks[$pc->position - 1] ?? null,
                'reversed'       => $reversed,
                'name_th'        => $card?->name_th,
                'name_en'        => $card?->name_en,
                'slug'           => $card?->slug,
                'arcana'         => $card?->arcana,
                'suit'           => $card?->suit,
                'keywords_th'    => $card?->keywords_th,
                'meaning'        => $reversed ? $card?->reversed_meaning_th : $card?->upright_meaning_th,
            ];
        })->all();
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    /** Per-card lines: position → "[label] (สิ่งที่ตำแหน่งถาม) → ไพ่ ทิศ — ความหมาย [คีย์เวิร์ด]". */
    private static function cardBlock(Reading $reading): string
    {
        $cards = $reading->tarotCards()->with('card')->orderBy('position')->get();
        $key   = TarotSpreads::keyFromType($reading->type);
        $asks  = $key ? array_column(TarotSpreads::positions($key), 'asks') : [];

        return $cards->map(function ($pc) use ($asks) {
            $card     = $pc->card;
            $reversed = (bool) $pc->reversed;
            $dir      = $reversed ? 'กลับหัว' : 'ตั้งตรง';
            $meaning  = $reversed ? $card?->reversed_meaning_th : $card?->upright_meaning_th;
            $ask      = $asks[$pc->position - 1] ?? null;
            $name     = trim(($card?->name_th ?? '') . ($card?->name_en ? " ({$card->name_en})" : ''));

            $head = "ตำแหน่งที่ {$pc->position} — {$pc->position_label}";
            if ($ask) {
                $head .= " [ตำแหน่งนี้ถามถึง: {$ask}]";
            }

            $body = "    ไพ่: {$name} — {$dir}";
            if ($meaning) {
                $body .= "\n    ความหมายพื้นฐาน: {$meaning}";
            }
            if ($card?->keywords_th) {
                $body .= "\n    คีย์เวิร์ด: {$card->keywords_th}";
            }

            return "• {$head}\n{$body}";
        })->implode("\n\n");
    }

    private static function cardCountLabel(Reading $reading): string
    {
        $n = $reading->tarotCards()->count();
        return "{$n} ใบ";
    }
}
