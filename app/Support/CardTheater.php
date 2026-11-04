<?php

namespace App\Support;

use App\Models\Reading;
use App\Models\TarotCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 🃏 ข้อมูลของโรงละครเปิดไพ่ (resources/js/card-theater) — 2026-11-05
 *
 * สองโหมด ใช้คอมโพเนนต์ <x-card-theater> ตัวเดียว:
 *   forPick()    หน้าเลือกไพ่: สำรับจริง (id ไพ่ 78 ใบ สุ่มลำดับ) · ค่าดูดวง/ยอดคงเหลือ · วันเกิด · ปลายทาง tarot.cast
 *   forReading() หน้าผล: ไพ่ที่เปิดได้จริง (ภาพ/ชื่อ/กลับหัว/ความหมายสั้น) · สถานะการอ่าน · ลิงก์ถามสถานะ
 *
 * ห้อง = ผู้พยากรณ์ที่อ่านไพ่ชุดนั้น (OracleMedia::personaFor) — ฉากโต๊ะ/ตัวละคร/สี อยู่ใน config/card_theater.php
 */
final class CardTheater
{
    /**
     * @param  Collection<int, TarotCard>  $deck
     */
    public static function forPick(string $spreadKey, Collection $deck, array $ctx): array
    {
        $cost = (float) ($ctx['cost'] ?? 0);
        $balance = $ctx['balance'] ?? null;

        return [
            'mode'      => 'pick',
            'room'      => self::room(OracleMedia::personaFor($spreadKey)),
            'spread'    => self::spread($spreadKey, TarotSpreads::positionLabels($spreadKey)),
            'deck'      => $deck->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'cardBack'  => self::cardBack(),
            'question'  => (string) ($ctx['question'] ?? ''),
            'cost'      => $cost,
            'costText'  => Pricing::format($cost),
            'balance'   => $balance === null ? null : (float) $balance,
            // เทียบด้วย bccomp ทศนิยม 2 ตำแหน่ง — float ตรง ๆ อาจตัดสินผิดที่หลักสตางค์
            'enough'    => $balance === null || bccomp(number_format((float) $balance, 2, '.', ''), number_format($cost, 2, '.', ''), 2) >= 0,
            'topupUrl'  => route('wallet.topup'),
        ];
    }

    public static function forReading(Reading $reading): array
    {
        $key = TarotSpreads::keyFromType((string) $reading->type) ?? 'three';
        $rows = $reading->tarotCards->sortBy('position')->values();
        $persona = data_get($reading->payload, 'room') ?: OracleMedia::personaFor($key);

        return [
            'mode'      => 'result',
            'readingId' => $reading->id,
            'status'    => $reading->isInProgress() ? 'reading' : ($reading->isFailed() ? 'failed' : 'done'),
            'statusUrl' => route('tarot.status', $reading),
            'room'      => self::room((string) $persona),
            // ป้ายตำแหน่งของใบนั้นจริง (บันทึกไว้ตอนเปิด) — แพ็กเกจถูกแก้ชื่อตำแหน่งทีหลังก็ไม่เพี้ยน
            'spread'    => self::spread($key, $rows->pluck('position_label')->map(fn ($l) => (string) $l)->all()),
            'cardBack'  => self::cardBack(),
            'question'  => (string) $reading->question,
            'cards'     => $rows->map(fn ($rc) => [
                'img'      => $rc->card?->imageUrl() ?? self::cardBack(),
                'name'     => (string) ($rc->card?->name_th ?? ''),
                'reversed' => (bool) $rc->reversed,
                'meaning'  => self::shortMeaning($rc->card, (bool) $rc->reversed),
            ])->all(),
        ];
    }

    /** เปิดโรงละครบนหน้าผลได้ไหม — เจ้าของคำทำนาย · ไม่ใช่รายการที่อ่านไม่สำเร็จ · มีไพ่ครบ */
    public static function availableFor(Reading $reading, $viewer): bool
    {
        return $viewer && $reading->user_id === $viewer->id
            && ! $reading->isFailed()
            && $reading->tarotCards->isNotEmpty();
    }

    /** ห้อง + ฉากโต๊ะ (เส้นทางรูปเป็น URL เต็ม) */
    public static function room(string $persona): array
    {
        $key = ChatRooms::normalize($persona);
        $all = (array) config('card_theater.rooms', []);
        $r = $all[$key] ?? $all[ChatRooms::DEFAULT];

        return [
            'key'     => $key,
            'title'   => $r['title'] ?? ('ห้อง' . ChatRooms::name($key)),
            'name'    => ChatRooms::name($key),
            'self'    => ChatRooms::selfName($key),
            'plate'   => [
                'src'   => asset($r['plate']['src']),
                'w'     => (int) $r['plate']['w'],
                'h'     => (int) $r['plate']['h'],
                'table' => (float) $r['plate']['table'],
                'posY'  => (float) $r['plate']['pos_y'],
            ],
            'sprite'  => asset($r['sprite']),
            'accent'  => $r['accent'],
            'accent2' => $r['accent_2'],
            'glow'    => $r['glow'],
            'star'    => $r['star'],
        ];
    }

    /** @param  list<string>  $labels */
    private static function spread(string $key, array $labels): array
    {
        $meta = TarotSpreads::get($key) ?? (TarotSpreads::registry()[$key] ?? []);

        return [
            'key'    => $key,
            'name'   => (string) ($meta['name_th'] ?? 'ไพ่ยิปซี'),
            'labels' => array_values($labels),
            'layout' => self::layout($key, count($labels), (string) ($meta['layout'] ?? 'grid')),
        ];
    }

    /**
     * ท่าวางไพ่บนโต๊ะ (ตรงกับ spreadLayout() ฝั่ง JS)
     *   celtic → กากบาท+ไม้เท้า · kunsai → แท่นบูชา 4/3/3 · year/12 ใบ → วงล้อ 12 เดือน
     *   ≤ 4 ใบ → แถวเดียว · 5 → โค้ง · 9 → ตาราง 3×3 · อื่น ๆ → แถวละไม่เกิน 5
     */
    public static function layout(string $key, int $count, string $metaLayout = 'grid'): string
    {
        return match (true) {
            $key === 'kunsai'       => 'shrine',
            $metaLayout === 'celtic' && $count >= 6 => 'celtic',
            $key === 'year' || $count === 12 => 'year',
            $count <= 4             => 'row',
            $count === 5            => 'arch',
            $count === 9            => 'grid',
            default                 => 'rows',
        };
    }

    public static function cardBack(): string
    {
        return (new TarotCard)->cardBackUrl() ?: asset((string) config('card_theater.card_back'));
    }

    /** ความหมายสั้นของไพ่ตามด้าน (คำสำคัญก่อน — ยาวไปตัดเหลือบรรทัดเดียว) */
    private static function shortMeaning(?TarotCard $card, bool $reversed): string
    {
        if (! $card) {
            return '';
        }
        $text = $reversed ? $card->reversed_meaning_th : ($card->keywords_th ?: $card->upright_meaning_th);

        return Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text))), 90);
    }
}
