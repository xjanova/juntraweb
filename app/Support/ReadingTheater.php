<?php

namespace App\Support;

use App\Models\Reading;
use Illuminate\Support\Carbon;

/**
 * 🎭 โรงละครคำทำนาย (2026-11-04) — แปลงคำทำนายไพ่ 1 ใบรายการเป็น "บท" ให้หน้าเว็บเล่น
 *
 * ลำดับบทตายตัว (voice-mode-plan ข้อ 21.3 / สัญญา 23.1):
 *   birth (หัวข้อ 🌠 — ไม่มีก็ข้าม) → graph (กราฟชีวิต) → card ทีละใบตามตำแหน่ง → summary (🎯 + ทำ/ระวัง) → outro (คุยต่อ)
 *
 * สองโหมด:
 *   - เงียบ: สร้างบทเองจาก ReadingSections::parse() — คำทำนายเก่าก็เปิดโรงละครได้
 *   - มีเสียง: ใช้ readings.payload.voice ตามสัญญา 23.2 ตรง ๆ (start/end เป็นวินาทีในไฟล์รวม)
 *     ไฟล์/เวลาไม่ครบตามสัญญา = ถอยเป็นโหมดเงียบ ไม่เดาเวลาเอง
 *
 * ภาพต้องตรงคำทำนายเสมอ (ข้อ 19.4): ไพ่/ตำแหน่ง/กลับหัว มาจาก reading_tarot_cards ·
 * สีดี-กลาง-ระวังของกราฟรายปีมาจากป้ายเดือนในคำทำนายเอง (tone จาก AI ใช้เฉพาะแบบที่ไม่มีป้าย)
 */
final class ReadingTheater
{
    /** ชื่อราศีไทยในข้อความ 🌠 → slug (ชุดเดียวกับ ZodiacSeeder) */
    private const ZODIAC = [
        'เมษ' => 'aries', 'พฤษภ' => 'taurus', 'เมถุน' => 'gemini', 'กรกฎ' => 'cancer',
        'สิงห์' => 'leo', 'กันย์' => 'virgo', 'ตุล' => 'libra', 'พิจิก' => 'scorpio',
        'ธนู' => 'sagittarius', 'มังกร' => 'capricorn', 'กุมภ์' => 'aquarius', 'มีน' => 'pisces',
    ];

    private const ZODIAC_GLYPH = [
        'aries' => '♈', 'taurus' => '♉', 'gemini' => '♊', 'cancer' => '♋', 'leo' => '♌', 'virgo' => '♍',
        'libra' => '♎', 'scorpio' => '♏', 'sagittarius' => '♐', 'capricorn' => '♑', 'aquarius' => '♒', 'pisces' => '♓',
    ];

    /** ชนิดบทที่หน้าเว็บรู้จัก */
    public const KINDS = ['birth', 'graph', 'card', 'summary', 'outro'];

    /**
     * โรงละครเปิดได้ไหม — คำทำนายต้องเสร็จแล้ว มีไพ่ และเป็นไพ่ยิปซี
     */
    public static function available(Reading $reading): bool
    {
        return TarotSpreads::isTarotType((string) $reading->type)
            && ! $reading->isInProgress()
            && ! $reading->isFailed()
            && trim((string) $reading->result) !== ''
            && $reading->tarotCards->isNotEmpty();
    }

    /**
     * ข้อมูลทั้งหมดที่หน้าเว็บต้องใช้ (ส่งเป็น JSON ให้ Alpine)
     *
     * @param  array{ok?:bool, sections?:array, by_type?:array}|null  $parsed  ผลจาก ReadingSections::parse() (ไม่ส่ง = แยกเอง)
     * @return array<string, mixed>
     */
    public static function build(Reading $reading, ?array $parsed = null): array
    {
        $parsed ??= ReadingSections::parse($reading->result);
        $by = $parsed['by_type'] ?? [];
        $spread = TarotSpreads::keyFromType((string) $reading->type);
        $persona = OracleMedia::personaFor($spread);
        $room = ChatRooms::get($persona) ?? [];

        $rows = $reading->tarotCards->sortBy('position')->values();
        $cards = [];
        $back = null;
        foreach ($rows as $rc) {
            $card = $rc->card;
            $back ??= $card?->cardBackUrl();
            $cards[] = [
                'pos'      => (int) $rc->position,
                'label'    => (string) ($rc->position_label ?: 'ตำแหน่ง '.$rc->position),
                'name'     => (string) ($card->name_th ?? ''),
                'reversed' => (bool) $rc->reversed,
                'img'      => $card ? $card->imageUrl() : null,
            ];
        }

        $isYear = $spread === 'year';
        $layout = match (true) {
            $isYear => 'wheel',
            ($spread ? TarotSpreads::layout($spread) : 'grid') === 'celtic' => 'celtic',
            default => 'row',
        };

        $verdict = self::verdict($by);
        $graph = self::graph($cards, $by, $isYear);
        $zodiac = self::zodiac($by['birth'][0] ?? null, data_get($reading->payload, 'birth_date'));

        $silent = self::silentChapters($reading, $by, $cards, $graph, $verdict, $isYear, (string) ($room['short'] ?? 'แม่หมอ'));
        $voice = self::voice($reading, $cards, $isYear);

        // มีเสียงจริง → tone/energy จากบทเสียงใช้กับกราฟแบบที่ไม่มีป้าย (รายปีใช้ป้ายของคำทำนายเท่านั้น)
        if ($voice !== null && ! $isYear) {
            foreach ($voice['chapters'] as $ch) {
                if ($ch['kind'] !== 'card' || $ch['pos'] === null) {
                    continue;
                }
                foreach ($graph['points'] as &$pt) {
                    if ($pt['pos'] === $ch['pos']) {
                        if (in_array($ch['tone'], ['good', 'neutral', 'caution'], true)) {
                            $pt['tone'] = $ch['tone'];
                        }
                        if (is_numeric($ch['energy'])) {
                            $pt['h'] = round(max(1, min(5, (float) $ch['energy'])) / 5, 3);
                        }
                    }
                }
                unset($pt);
            }
        }

        return [
            'id'       => $reading->id,
            'persona'  => $persona,
            'oracle'   => (string) ($room['short'] ?? 'แม่หมอ'),
            'customer' => (string) ($room['customer'] ?? 'ลูก'),
            'spread'   => $spread,
            'layout'   => $layout,
            'cards'    => $cards,
            'back'     => $back,
            'zodiac'   => $zodiac,
            'verdict'  => $verdict,
            'graph'    => $graph,
            'chapters' => $silent,
            'voice'    => $voice,
            'music'    => OracleMedia::music($persona),
        ];
    }

    /**
     * บทแบบเงียบจากหัวข้อคำทำนาย
     *
     * @return list<array<string, mixed>>
     */
    private static function silentChapters(Reading $reading, array $by, array $cards, array $graph, array $verdict, bool $isYear, string $oracle): array
    {
        $out = [];

        if ($birth = $by['birth'][0] ?? null) {
            $out[] = self::chapter('birth', 'พื้นดวง', '🌠 '.$birth['title'], self::plain($birth['body']));
        }

        // กราฟชีวิต — ใช้ 📖 เรื่องราว/⏳ จังหวะ เป็นคำบรรยายถ้ามี ไม่มีก็สรุปเส้นทางจากข้อมูลจริง
        $story = trim(implode("\n\n", array_filter([
            self::plain($by['story'][0]['body'] ?? ''),
            self::plain($by['timing'][0]['body'] ?? ''),
        ])));
        if ($story === '') {
            if ($isYear) {
                $count = array_count_values(array_column($graph['points'], 'tone'));
                $story = sprintf('ภาพรวม 12 เดือนข้างหน้า · เดือนดี %d · เดือนกลาง ๆ %d · เดือนต้องระวัง %d',
                    $count['good'] ?? 0, $count['neutral'] ?? 0, $count['caution'] ?? 0);
            } else {
                $first = $cards[0]['label'] ?? '';
                $last = $cards[count($cards) - 1]['label'] ?? '';
                $story = count($cards) > 1
                    ? "เส้นทางของไพ่ ".count($cards)." ใบ ตั้งแต่{$first} ไปจนถึง{$last}"
                    : "ไพ่ 1 ใบ ตอบเรื่องที่ถาม";
            }
        }
        $out[] = self::chapter('graph', 'กราฟชีวิต', $isYear ? 'กราฟดวง 12 เดือน' : 'เส้นทางของไพ่', $story);

        // ไพ่ทีละใบ — หัวข้อ 🃏 ตามเลขตำแหน่ง · รายปีใช้ 📅 ตามลำดับเดือน · ไม่มีหัวข้อ = ความหมายตำราของใบนั้น
        $cardSections = [];
        foreach ($by['card'] ?? [] as $s) {
            if (isset($s['n'])) {
                $cardSections[$s['n']] ??= $s;
            }
        }
        $months = array_values($by['month'] ?? []);
        $rowsByPos = $reading->tarotCards->keyBy('position');

        foreach ($cards as $i => $c) {
            $sec = $isYear ? ($months[$i] ?? null) : ($cardSections[$c['pos']] ?? null);
            $text = $sec ? self::plain(trim(($sec['theme'] ?? '') !== '' ? $sec['theme']."\n".$sec['body'] : $sec['body'])) : '';
            if ($text === '') {
                $model = $rowsByPos[$c['pos']]->card ?? null;
                $text = (string) ($c['reversed'] ? ($model->reversed_meaning_th ?? '') : ($model->upright_meaning_th ?? ''));
            }
            // รายปี: ป้ายสั้นบนสารบัญ = ชื่อเดือนย่อ ("ต.ค. 2569" → "ต.ค.")
            $short = $isYear ? explode(' ', (string) ($graph['points'][$i]['label'] ?? $c['pos']))[0] : (string) $c['pos'];
            $title = $c['label'].' · '.$c['name'].($c['reversed'] ? ' (กลับหัว)' : '');
            $ch = self::chapter('card', $short, $title, $text);
            $ch['pos'] = $c['pos'];
            $ch['tone'] = $isYear ? ($graph['points'][$i]['tone'] ?? null) : null;
            $out[] = $ch;
        }

        $out[] = self::chapter('summary', 'สรุป', $verdict['label'] !== '' ? '🎯 '.$verdict['label'] : '🎯 สรุปดวง', self::summaryText($by));
        $out[] = self::chapter('outro', 'คุยต่อ', 'นั่งคุยซักถามต่อ', "ตรงไหนยังค้างใจ ถาม{$oracle}ต่อได้เลย ไพ่ชุดนี้ยังวางอยู่บนโต๊ะ");

        return $out;
    }

    /** @return array<string, mixed> */
    private static function chapter(string $kind, string $short, string $title, string $text): array
    {
        return ['kind' => $kind, 'short' => $short, 'title' => $title, 'text' => $text, 'pos' => null, 'tone' => null, 'start' => null, 'end' => null];
    }

    /**
     * ข้อความบทสรุป: ฟันธง → สิ่งที่ควรทำ → เดือนทอง → ต้องระวัง → ทางแก้ → สายด่วน (ถ้ามี)
     */
    private static function summaryText(array $by): string
    {
        $parts = [];
        if ($v = $by['verdict'][0] ?? null) {
            $parts[] = trim(($v['result'] ?? '') !== '' ? $v['result']."\n".self::plain($v['body']) : self::plain($v['body']));
        }
        if ($d = $by['diagnosis'][0] ?? null) {
            $rows = [];
            foreach ($d['fields'] as $k => $val) {
                if ($val !== '' && $val !== '—') {
                    $rows[] = "{$k}: {$val}";
                }
            }
            $parts[] = trim(implode("\n", $rows)."\n".self::plain($d['body']));
        }
        foreach (['advice' => '🧭', 'golden' => '🌟', 'caution' => '⚠', 'remedy' => '🙏'] as $type => $icon) {
            foreach ($by[$type] ?? [] as $s) {
                $lines = array_map(fn ($it) => '• '.$it, $s['items']);
                $body = self::plain($s['body']);
                $parts[] = trim($icon.' '.$s['title']."\n".implode("\n", $lines).($body !== '' ? "\n".$body : ''));
            }
        }
        foreach ($by['safety'] ?? [] as $s) {
            $parts[] = '☎ '.$s['title']."\n".self::plain($s['body']);
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    /**
     * ป้ายฟันธง + วงแหวน
     *
     * วงแหวนไม่มีคะแนนแต่ง: รายปี = สัดส่วนเดือนดีจากป้ายเดือนจริง · แบบอื่น = เต็มวงตามสีผลฟันธง
     *
     * @return array{label:string, tone:string}
     */
    private static function verdict(array $by): array
    {
        if ($d = $by['diagnosis'][0] ?? null) {
            $hit = (string) ($d['fields']['พบของ'] ?? '');

            return match (true) {
                str_starts_with($hit, 'ใช่') => ['label' => 'พบสัญญาณของ', 'tone' => 'red'],
                str_starts_with($hit, 'ไม่ใช่') => ['label' => 'ไพ่ไม่ชี้ว่าโดนของ', 'tone' => 'green'],
                default => ['label' => 'สัญญาณยังไม่ชัด', 'tone' => 'amber'],
            };
        }
        $result = (string) ($by['verdict'][0]['result'] ?? '');

        return ['label' => $result, 'tone' => ReadingSections::verdictTone($result)];
    }

    /**
     * จุดบนกราฟชีวิต — รายปี 12 เดือนจากป้ายเดือนจริง · แบบอื่นไล่ตามตำแหน่งไพ่ (อดีต → อนาคต)
     *
     * h (0–1) = ความสูงของจุด: ป้ายดี/กลาง/ระวัง · ไม่มีป้าย = หัวตั้งสูงกว่ากลับหัวเล็กน้อย (ข้อมูลจริงจาก DB)
     *
     * @return array{kind:string, points:list<array{pos:int,label:string,tone:string,h:float}>, ring:array{fill:float,caption:?string}}
     */
    private static function graph(array $cards, array $by, bool $isYear): array
    {
        $months = array_values($by['month'] ?? []);
        $points = [];
        foreach ($cards as $i => $c) {
            if ($isYear && isset($months[$i])) {
                $tone = $months[$i]['tone'];
                $label = $months[$i]['month'] !== '' ? $months[$i]['month'] : $c['label'];
            } else {
                $tone = 'neutral';
                $label = $c['label'];
            }
            $h = match ($tone) {
                'good' => 0.85,
                'caution' => 0.22,
                default => $c['reversed'] ? 0.4 : 0.6,
            };
            if ($isYear && $tone === 'neutral') {
                $h = 0.52;
            }
            $points[] = ['pos' => $c['pos'], 'label' => $label, 'tone' => $tone, 'h' => $h];
        }

        $ring = ['fill' => 1.0, 'caption' => null];
        if ($isYear && $months !== []) {
            $good = count(array_filter($points, fn ($p) => $p['tone'] === 'good'));
            $ring = ['fill' => round($good / max(1, count($points)), 3), 'caption' => "เดือนดี {$good}/".count($points)];
        }

        return ['kind' => $isYear ? 'year' : 'path', 'points' => $points, 'ring' => $ring];
    }

    /**
     * ราศีของลูกค้าสำหรับกลุ่มดาวในบทพื้นดวง
     *
     * ยึดราศีที่หัวข้อ 🌠 พูดถึงก่อน (ภาพต้องตรงกับที่เล่า) · ไม่เอ่ยชื่อราศี = คำนวณจากวันเกิดที่เก็บไว้
     * (Astrology::westernZodiacSlug) · ไม่มีหัวข้อ 🌠 = ไม่มีบทพื้นดวง → null
     *
     * @return array{slug:string, name:string, glyph:string}|null
     */
    private static function zodiac(?array $birth, mixed $birthDate): ?array
    {
        if ($birth === null) {
            return null;
        }
        $text = $birth['title'].' '.$birth['body'];
        $slug = null;
        if (preg_match('/ราศี\s*('.implode('|', array_map('preg_quote', array_keys(self::ZODIAC))).')/u', $text, $m)) {
            $slug = self::ZODIAC[$m[1]];
        } elseif (is_string($birthDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            $slug = Astrology::westernZodiacSlug(Carbon::parse($birthDate));
        }
        if ($slug === null) {
            return null;
        }

        return ['slug' => $slug, 'name' => 'ราศี'.array_search($slug, self::ZODIAC, true), 'glyph' => self::ZODIAC_GLYPH[$slug]];
    }

    /**
     * เสียงคำทำนายตามสัญญา 23.2 — คืน null เมื่อใช้ไม่ได้ (โรงละครเล่นแบบเงียบ)
     *
     * ต้องมี: status ready|partial · url เป็น path ในเว็บเดียวกัน (ตัววัดความดังของ WebAudio ใช้ได้เฉพาะ same-origin)
     * · บทที่มี start/end เป็นตัวเลขเรียงกันอย่างน้อย 1 บท · บทที่ไม่มีเวลา (partial) ถูกตัดออก
     *
     * @return array{url:string, duration:float, chapters:list<array<string,mixed>>}|null
     */
    private static function voice(Reading $reading, array $cards, bool $isYear): ?array
    {
        $v = data_get($reading->payload, 'voice');
        if (! is_array($v) || ! in_array($v['status'] ?? null, ['ready', 'partial'], true)) {
            return null;
        }
        $url = $v['url'] ?? null;
        if (! is_string($url) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        $positions = array_column($cards, 'pos');
        $chapters = [];
        $lastEnd = 0.0;
        foreach ((array) ($v['chapters'] ?? []) as $c) {
            if (! is_array($c) || ! is_numeric($c['start'] ?? null) || ! is_numeric($c['end'] ?? null)) {
                continue;
            }
            $start = (float) $c['start'];
            $end = (float) $c['end'];
            if ($end <= $start || $start < $lastEnd - 0.05) {
                continue;   // เวลาย้อน/ทับกัน = ข้อมูลเสีย ไม่ใช้บทนั้น
            }
            $anchor = (string) ($c['anchor'] ?? '');
            $kind = in_array($c['kind'] ?? null, self::KINDS, true) ? $c['kind'] : null;
            $pos = null;
            if (preg_match('/^(card|month):(\d+)$/', $anchor, $m)) {
                $pos = (int) $m[2];
                $kind = 'card';
            }
            $kind ??= match ($anchor) {
                'birth' => 'birth', 'graph' => 'graph', 'outro' => 'outro',
                default => 'summary',   // verdict/advice/caution/remedy/general
            };
            if ($kind === 'card' && ! in_array($pos, $positions, true)) {
                $kind = 'summary';
                $pos = null;
            }

            $short = match ($kind) {
                'birth' => 'พื้นดวง', 'graph' => 'กราฟชีวิต', 'summary' => 'สรุป', 'outro' => 'คุยต่อ',
                default => (string) $pos,
            };
            $card = $pos !== null ? ($cards[array_search($pos, $positions, true)] ?? null) : null;
            $chapters[] = [
                'kind'   => $kind,
                'short'  => $short,
                'title'  => $card ? $card['label'].' · '.$card['name'].($card['reversed'] ? ' (กลับหัว)' : '') : $short,
                'text'   => self::stripTags((string) ($c['say'] ?? '')),
                'pos'    => $pos,
                'tone'   => in_array($c['tone'] ?? null, ['good', 'neutral', 'caution'], true) ? $c['tone'] : null,
                'energy' => is_numeric($c['energy'] ?? null) ? (float) $c['energy'] : null,
                'start'  => round($start, 3),
                'end'    => round($end, 3),
            ];
            $lastEnd = $end;
        }
        if ($chapters === []) {
            return null;
        }

        return [
            'url'      => $url,
            'duration' => is_numeric($v['duration'] ?? null) ? (float) $v['duration'] : $lastEnd,
            'chapters' => $chapters,
        ];
    }

    /** ตัดแท็กอารมณ์ ElevenLabs ([softly]) ออกจากคำบรรยายบนจอ */
    private static function stripTags(string $say): string
    {
        return trim((string) preg_replace('/\s*\[[a-z _-]{2,24}\]\s*/iu', ' ', $say));
    }

    /** markdown → ข้อความล้วนสำหรับคำบรรยาย (หัวข้อ/ตัวหนา/ลิงก์ ออก · รายการเป็น •) */
    public static function plain(?string $md): string
    {
        $t = str_replace("\r\n", "\n", (string) $md);
        $t = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $t);
        $t = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $t);
        $t = (string) preg_replace('/^\s*#{1,6}\s*/mu', '', $t);
        $t = (string) preg_replace('/^\s*[-*•]\s+/mu', '• ', $t);
        $t = str_replace(['**', '__', '`'], '', $t);
        $t = strip_tags($t);
        $t = (string) preg_replace("/\n{3,}/", "\n\n", $t);

        return trim($t);
    }
}
