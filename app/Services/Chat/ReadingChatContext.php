<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Services\FortuneBot\FortuneAiService;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Support\TarotSpreads;

/**
 * 💬 (2026-09-21) บริบทคำพยากรณ์ที่แม่หมอใช้คุยต่อกับลูกค้า — ไพ่ทุกใบ + คำพยากรณ์ฉบับเต็ม
 *
 * เจ้าของอยากให้แม่หมอ "รู้จริง" ว่าลูกค้าได้คำพยากรณ์อะไรไป แล้วคุยต่อได้ต่อเนื่อง
 * ("เดือนมีนาที่ไพ่บอกให้ระวัง หมายถึงอะไร") แต่ของเดิมส่งบริบทเป็น "ข้อความแชท" ซึ่งถูกตัดที่ 1,000 ตัว
 * — ตรวจบน prod: คำพยากรณ์ 12 เดือน (ยาว 7,496 ตัว) ไปถึงแม่หมอแค่ไพ่เดือน 1-4 เนื้อคำพยากรณ์ไม่ถึงเลย
 * ตำแหน่งก็เป็นแค่ "เดือนที่ N" ไม่มีชื่อเดือนจริง
 *
 * ตอนนี้ส่งเป็นฟิลด์ `context` ให้ Thaiprompt วางไว้ใน system message (ไม่กินรอบสนทนา ไม่เลื่อนหลุดประวัติ)
 * และสร้างใหม่จากฐานข้อมูลทุกครั้ง — ไม่พึ่ง session ของเบราว์เซอร์อีกต่อไป
 */
final class ReadingChatContext
{
    /** เพดานของ Thaiprompt — เกินนี้โดน 422 ทั้งคำขอ จึงตัดท้ายคำพยากรณ์เองก่อน */
    public const MAX_CHARS = JuntraServerClient::CHAT_CONTEXT_MAX;

    /** ความหมายพื้นฐานต่อใบ — คำพยากรณ์ฉบับเต็มคือของหลัก ความหมายไพ่แค่ช่วยให้อธิบายไพ่ใบเดียวได้ตรง */
    private const MEANING_CHARS = 200;

    private const TRIMMED = "\n…(คำพยากรณ์ยาวเกินเพดาน ตัดท้ายออก)";

    private const TH_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    /**
     * ไพ่ชุดนี้ใช้เปิดห้องคุยต่อได้ไหม — ไพ่ยิปซีที่แม่หมออ่านเสร็จแล้ว
     * (กำลังอ่าน = ยังไม่มีคำพยากรณ์ให้คุยต่อ · ไม่สำเร็จ = คืนเงินแล้ว ไม่มีอะไรให้อ่าน)
     */
    public static function usable(Reading $reading): bool
    {
        return TarotSpreads::isTarotType((string) $reading->type)
            && ! $reading->isInProgress()
            && ! $reading->isFailed();
    }

    /**
     * ไพ่ที่ห้องนี้ผูกอยู่ — เฉพาะไพ่ของเจ้าของห้องเอง (กันห้องของคนหนึ่งไปอ่านคำพยากรณ์ของอีกคน)
     */
    public static function readingOf(ChatConversation $conversation): ?Reading
    {
        if (! $conversation->reading_id || ! $conversation->user_id) {
            return null;
        }
        $reading = $conversation->relationLoaded('reading')
            ? $conversation->reading
            : Reading::find($conversation->reading_id);

        return $reading
            && (int) $reading->user_id === (int) $conversation->user_id
            && TarotSpreads::isTarotType((string) $reading->type)
            && ! $reading->isFailed()
                ? $reading
                : null;
    }

    /** บริบทเต็มสำหรับฟิลด์ `context` ของ Thaiprompt (และทางสำรอง AiOracle ในเครื่อง) */
    public static function for(Reading $reading): string
    {
        $reading->loadMissing('tarotCards.card');
        $key = TarotSpreads::keyFromType((string) $reading->type);
        $spreadName = TarotSpreads::nameForType((string) $reading->type) ?? 'ไพ่ยิปซี';
        $cards = $reading->tarotCards;

        // 12 เดือน: ชื่อเดือนจริงต่อตำแหน่ง ชุดเดียวกับที่ส่งให้แม่หมอตอนอ่านไพ่ (หัวข้อ "## 📅 มี.ค. 2570")
        // ลูกค้าถาม "เดือนมีนา" แม่หมอจับคู่กับไพ่ใบที่ถูกได้ ไม่ต้องนับเอง
        $months = $key === 'year' ? FortuneAiService::yearMonths($reading) : [];
        $asks = $key && $key !== 'year' ? array_column(TarotSpreads::positions($key), 'asks') : [];

        $lines = [];
        $lines[] = "แพ็กเกจ: {$spreadName} (ไพ่ {$cards->count()} ใบ)";
        $lines[] = 'เปิดไพ่เมื่อ: '.self::thaiDate($reading);
        $question = trim((string) $reading->question);
        $lines[] = 'คำถามของลูก: '.($question !== '' ? $question : 'ไม่ได้ระบุ (ดูภาพรวม)');
        $lines[] = '';
        $lines[] = 'ไพ่ที่เปิดได้ตามตำแหน่ง:';
        foreach ($cards as $rc) {
            $card = $rc->card;
            $name = trim(($card?->name_th ?? '').($card?->name_en ? " ({$card->name_en})" : ''));
            $month = $months[$rc->position - 1] ?? null;
            $ask = $asks[$rc->position - 1] ?? null;
            $meaning = trim((string) ($rc->reversed ? $card?->reversed_meaning_th : $card?->upright_meaning_th));

            $lines[] = sprintf(
                '%d. %s%s%s: %s · %s%s',
                $rc->position,
                $rc->position_label,
                $month ? " · {$month}" : '',
                $ask ? " (ตำแหน่งนี้ถามถึง: {$ask})" : '',
                $name !== '' ? $name : 'ไม่ทราบชื่อไพ่',
                $rc->reversed ? 'กลับหัว' : 'ตั้งตรง',
                $meaning !== '' ? ' — ความหมายพื้นฐาน: '.mb_substr($meaning, 0, self::MEANING_CHARS) : '',
            );
        }
        $lines[] = '';
        $lines[] = 'คำพยากรณ์ฉบับเต็มที่ลูกได้รับไปแล้ว:';

        $head = implode("\n", $lines)."\n";
        $result = trim((string) $reading->result);
        if ($result === '') {
            $result = '(ไม่มีข้อความคำพยากรณ์ — คุยจากไพ่ด้านบนเท่านั้น)';
        }

        // เกินเพดาน → ตัดท้ายคำพยากรณ์ (ไพ่ต้องครบทุกใบเสมอ) — คำพยากรณ์จริงบน prod ยังห่างเพดานมาก
        $budget = self::MAX_CHARS - mb_strlen($head);
        if (mb_strlen($result) > $budget) {
            $result = mb_substr($result, 0, max(0, $budget - mb_strlen(self::TRIMMED))).self::TRIMMED;
        }

        return mb_substr($head.$result, 0, self::MAX_CHARS);
    }

    /** คำทักทายของห้องที่ผูกกับไพ่ — ประกอบในเครื่อง ไม่ต้องเสียรอบถาม AI (ไม่คิดเงิน) */
    public static function greeting(Reading $reading): string
    {
        $n = $reading->tarotCards()->count();
        $spreadName = TarotSpreads::nameForType((string) $reading->type) ?? 'ไพ่ยิปซี';
        $hint = TarotSpreads::keyFromType((string) $reading->type) === 'year'
            ? 'จะถามเจาะเดือนไหนก็ได้เลยค่ะ'
            : 'พิมพ์คำถามด้านล่างได้เลยค่ะ';

        return "แม่หมอเห็นไพ่ทั้ง {$n} ใบจากการเปิด \"{$spreadName}\" ของลูก และคำพยากรณ์ฉบับเต็มแล้วนะคะ ✨ "
            ."อยากให้แม่หมอเจาะลึกเรื่องไหนเป็นพิเศษจากไพ่ชุดนี้คะ? {$hint}";
    }

    /** ชื่อห้องในประวัติแชท / รายการห้องในแอพ */
    public static function title(Reading $reading): string
    {
        return mb_substr('คุยต่อจากไพ่ '.(TarotSpreads::nameForType((string) $reading->type) ?? 'ไพ่ยิปซี'), 0, 128);
    }

    /** "21 ก.ย. 2569" (เวลาไทย) */
    private static function thaiDate(Reading $reading): string
    {
        $t = ($reading->created_at ?? now())->copy()->timezone('Asia/Bangkok');

        return $t->day.' '.self::TH_MONTHS[$t->month - 1].' '.($t->year + 543);
    }
}
