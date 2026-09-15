<?php

namespace App\Services\Chat;

use App\Support\Pricing;
use App\Support\TarotSpreads;

/**
 * การ์ดแพ็กเกจที่แม่หมอยื่นให้ในแชท เมื่อลูกค้าขอให้ทำนาย — จุดที่ "คุยฟรี" กลายเป็น "เปิดไพ่หักเครดิต"
 *
 * ราคาอ่านจาก Pricing::for() ตัวเดียวกับหน้าเปิดไพ่เสมอ (สวิตช์เก็บเงิน/ราคาที่แอดมินตั้งมีผลตรงนี้ด้วย)
 * การ์ดไพ่ยิปซีส่งลูกค้าไปขั้นเลือกไพ่ทันที (POST /tarot/begin พร้อมคำถามที่เพิ่งพิมพ์) — เงินถูกหัก
 * ตอนเปิดไพ่จริงในหน้านั้น ด้วยกติกาเดิมที่ผ่าน audit แล้ว (หักก่อน · AI ล่ม = คืนเงิน · กันกดซ้ำ)
 */
final class ChatOffers
{
    /** แพ็กเกจที่เหมาะกับแต่ละเรื่อง — ไม่เกิน 3 ใบ ลูกค้าหลักเป็นผู้สูงอายุ ตัวเลือกมากไปคือไม่เลือก */
    private const BY_TOPIC = [
        'love'    => ['love', 'three', 'deep'],
        'career'  => ['career', 'decision', 'deep'],
        'money'   => ['career', 'three', 'deep'],
        'health'  => ['three', 'single', 'deep'],
        'general' => ['three', 'celtic', 'deep'],
    ];

    private const TOPIC_WORD = [
        'love'    => 'เรื่องหัวใจ',
        'career'  => 'เรื่องการงาน',
        'money'   => 'เรื่องการเงิน',
        'health'  => 'เรื่องสุขภาพกายใจ',
        'general' => 'ดวงชะตา',
    ];

    /** คำชวนของแม่หมอเมื่อด่านแรก (ChatReadingIntent) จับได้ — ไม่ต้องถาม AI */
    public static function invite(string $topic): string
    {
        $word = self::TOPIC_WORD[ChatReadingIntent::normalizeTopic($topic)];

        return "ได้เลยค่ะลูก แม่หมอจะเปิดไพ่ดู{$word}ให้นะคะ ✨ "
            . 'เลือกแบบที่ลูกอยากให้แม่หมอดูด้านล่างได้เลย — ตั้งจิตถึงคำถามของลูกไว้ แล้วแม่หมอจะอ่านไพ่ให้ละเอียดค่ะ ☾';
    }

    /**
     * รายการแพ็กเกจเป็นข้อความ — ต่อท้ายคำตอบฝั่งแอพ เพราะแอพรุ่นที่ออกไปแล้วไม่รู้จัก `offers`
     * (ไม่ต่อท้าย = ลูกค้าเห็นคำว่า "เลือกด้านล่าง" แต่ไม่มีอะไรให้เลือก)
     */
    public static function asText(array $offers): string
    {
        if ($offers === []) {
            return '';
        }
        $lines = [];
        foreach ($offers as $o) {
            $price   = $o['price'] > 0 ? Pricing::format($o['price']) : 'ฟรี';
            $lines[] = '• ' . $o['label'] . ($o['cards'] ? " ({$o['cards']} ใบ)" : '') . ' — ' . $price;
        }

        return "\n\n" . implode("\n", $lines) . "\nเลือกได้จากเมนูดูดวงในแอพเลยนะคะ";
    }

    /**
     * @return array<int,array{key:string,kind:string,spread:?string,label:string,cards:?int,price:float,blurb:string,url:string}>
     */
    public static function for(string $topic): array
    {
        $out = [];
        foreach (self::BY_TOPIC[ChatReadingIntent::normalizeTopic($topic)] as $key) {
            if ($key === 'deep') {
                $out[] = [
                    'key'    => 'deep',
                    'kind'   => 'deep',
                    'spread' => null,
                    'label'  => 'ดูดวงเชิงลึก',
                    'cards'  => null,
                    'price'  => (float) Pricing::for('deep'),
                    'blurb'  => 'แม่หมอผสานดวงดาววันเกิด เลขศาสตร์ และไพ่ ตอบคำถามของลูกแบบเจาะลึก',
                    'url'    => route('deep.index'),
                ];

                continue;
            }
            $meta = TarotSpreads::get($key);
            if ($meta === null) {
                continue;
            }
            $out[] = [
                'key'    => 'tarot_' . $key,
                'kind'   => 'tarot',
                'spread' => $key,
                'label'  => (string) ($meta['name_th'] ?? $key),
                'cards'  => TarotSpreads::cardCount($key),
                'price'  => (float) Pricing::for(TarotSpreads::priceKey($key)),
                'blurb'  => (string) ($meta['tagline'] ?? ''),
                'url'    => route('tarot.index'),
            ];
        }

        return $out;
    }
}
