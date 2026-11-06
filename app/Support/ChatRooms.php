<?php

namespace App\Support;

/**
 * 🔮 ห้องพยากรณ์ในหน้าแชท (config/chat_rooms.php) — แหล่งความจริงเดียวว่าห้องไหนเป็นของใคร ขายอะไร
 *
 * key ของห้อง = persona ที่ส่งไป Thaiprompt ทั้งแชท คำทำนาย และเสียงพากย์
 * ห้องกับแพ็กเกจเป็นคู่ 1:1 จึงหา persona ของคำทำนายจากแพ็กเกจได้เลย (personaForSpread)
 * ไม่ต้องลากชื่อห้องผ่านหน้าเลือกไพ่ — ซื้อจากหน้า /tarot ตรง ๆ ก็ได้เสียงผู้พยากรณ์ที่ถูกห้อง
 */
final class ChatRooms
{
    public const DEFAULT = 'maemor';

    /** ห้องทั้งหมดตามลำดับในหน้าเลือก (ถูก → แพง) */
    public static function all(): array
    {
        return config('chat_rooms.rooms', []);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** ค่าแปลก/ไม่มี → ห้องแม่หมอ (ห้องเดิมของเว็บ) */
    public static function normalize(?string $key): string
    {
        return is_string($key) && self::get($key) !== null ? $key : self::DEFAULT;
    }

    /**
     * ห้องนี้เปิดให้คุยแล้วหรือยัง
     *
     * ห้องที่ต้องให้ Thaiprompt รู้จัก persona ก่อน (บุษบา/คุณไสย) ปิดไว้จนกว่าจะเปิด
     * CHAT_PERSONA_UPSTREAM — ไม่งั้นลูกค้าเข้าห้องบุษบาแล้วเจอแม่หมอตอบ (บุคลิกไม่ตรงห้อง)
     * และแพ็กเกจของห้องต้องเปิดขายอยู่ (ห้อง 1 ห้อง = 1 แบบทำนาย ไม่มีของขาย = ไม่มีห้อง)
     * และไม่อยู่ใน CHAT_ROOMS_CLOSED (ปิดทีละห้อง — ห้องหลักปิดไม่ได้)
     */
    public static function isOpen(string $key): bool
    {
        $room = self::get($key);
        if ($room === null) {
            return false;
        }
        if (! empty($room['needs_upstream']) && ! self::personaUpstream()) {
            return false;
        }
        if ($key !== self::DEFAULT && in_array($key, (array) config('chat_rooms.closed', []), true)) {
            return false;
        }

        return array_key_exists($room['spread'], TarotSpreads::all());
    }

    /** Thaiprompt รับฟิลด์ persona ของแชทแล้วหรือยัง */
    public static function personaUpstream(): bool
    {
        return (bool) config('chat_rooms.persona_upstream', false);
    }

    /**
     * persona ที่ส่งไปกับแชท — null = ไม่ส่งฟิลด์เลย (Thaiprompt รุ่นที่ยังไม่รู้จัก persona ได้คำขอหน้าตาเดิมทุกไบต์)
     */
    public static function upstreamPersona(string $key): ?string
    {
        return self::personaUpstream() ? self::normalize($key) : null;
    }

    /**
     * persona ของคำทำนายจากแบบไพ่ — ผู้พยากรณ์ที่มีแบบนี้ใน `spreads` ของตัวเอง
     * (single/three/quick → บุษบา · kunsai → คุณไสย · ที่เหลือ/แบบที่ไม่มีเจ้าของ → แม่หมอ)
     */
    public static function personaForSpread(?string $spread): string
    {
        if ($spread === null) {
            return self::DEFAULT;
        }
        foreach (self::all() as $key => $room) {
            if (in_array($spread, self::spreadsOf($room), true)) {
                return $key;
            }
        }

        return self::DEFAULT;
    }

    /**
     * 🃏 (2026-11-06) แบบไพ่ทั้งหมดที่ผู้พยากรณ์คนนี้ดู ตามลำดับเมนู (config `spreads`)
     *
     * @return list<string>
     */
    public static function spreads(string $key): array
    {
        $room = self::get($key);

        return $room !== null ? self::spreadsOf($room) : [];
    }

    /**
     * 🎁 ห้องที่ดูแลไพ่ 1 ใบฟรีรายวัน — ห้องที่ตั้ง `free` และเปิดอยู่ ไม่งั้นห้องแม่หมอ
     * (เจ้าของ 2026-11-06: "ไพ่ 1 ใบ บุษบาเป็นคนดูแลไม่ใช่แม่หมอ")
     */
    public static function freeRoom(): string
    {
        foreach (self::all() as $key => $room) {
            if (! empty($room['free']) && self::isOpen($key)) {
                return $key;
            }
        }

        return self::DEFAULT;
    }

    /** @return list<string> */
    private static function spreadsOf(array $room): array
    {
        $list = array_values(array_filter((array) ($room['spreads'] ?? []), 'is_string'));

        return $list !== [] ? $list : array_values(array_filter([(string) ($room['spread'] ?? '')]));
    }

    /**
     * ห้องคุยต่อจากคำทำนาย — ห้องของผู้พยากรณ์ที่อ่านไพ่ชุดนั้น ถ้าห้องยังปิดอยู่ถอยไปห้องแม่หมอ
     * (เช่น ยังไม่เปิด CHAT_PERSONA_UPSTREAM แต่ลูกค้าซื้อไพ่คุณไสยแล้วกด "ถามแม่หมอ" — ต้องได้คุยต่อ ไม่ใช่เจอหน้า "เร็ว ๆ นี้")
     */
    public static function roomForSpread(?string $spread): string
    {
        $key = self::personaForSpread($spread);

        return self::isOpen($key) ? $key : self::DEFAULT;
    }

    /** แพ็กเกจเดียวของห้อง */
    public static function spread(string $key): string
    {
        return (string) (self::get(self::normalize($key))['spread'] ?? 'celtic');
    }

    public static function name(string $key): string
    {
        return (string) (self::get(self::normalize($key))['name'] ?? 'แม่หมอจันทรา');
    }

    public static function short(string $key): string
    {
        return (string) (self::get(self::normalize($key))['short'] ?? 'แม่หมอ');
    }

    /** คำเรียกตัวเองของผู้พยากรณ์ห้องนี้ — ใช้ในข้อความที่เว็บประกอบเอง */
    public static function selfName(string $key): string
    {
        return (string) (self::get(self::normalize($key))['self'] ?? 'แม่หมอ');
    }

    /** คำที่ผู้พยากรณ์ห้องนี้ใช้เรียกลูกค้า (บุษบา → คุณ · แม่หมอ/คุณไสย → ลูก) */
    public static function customer(string $key): string
    {
        return (string) (self::get(self::normalize($key))['customer'] ?? 'ลูก');
    }

    /**
     * คำทักทายแรกของห้อง — ประกอบในเครื่อง ไม่ถาม AI (เดิมเปิดหน้าแชท = ยิง Thaiprompt ทุกครั้ง
     * แม้คนที่ไม่ได้พิมพ์อะไรเลย ถือ worker ของเซิร์ฟเวอร์ค้างได้ถึง 14 วิ)
     */
    public static function greeting(string $key): string
    {
        $room = self::get(self::normalize($key));
        $all  = array_values(array_filter((array) ($room['greetings'] ?? [])));

        return $all !== [] ? $all[array_rand($all)] : (string) ($room['quote'] ?? '');
    }

    /**
     * คำตอบเมื่อผู้พยากรณ์ตอบไม่ทันเวลา/ระบบแน่น — ไม่คิดเงิน ลูกค้ากดส่งใหม่ได้
     * (ประกอบจากคำเรียกของห้อง จะได้ไม่ใช่แม่หมอพูดแทนในห้องบุษบา)
     */
    public static function busyReply(string $key): string
    {
        $room = self::get(self::normalize($key));
        if (! empty($room['busy_reply'])) {
            return (string) $room['busy_reply'];
        }

        return 'ขอโทษนะคะ ตอนนี้' . self::selfName($key) . 'มีคนรอคิวอยู่เยอะ ตอบไม่ทัน '
            . 'รอสักครู่แล้วส่งข้อความเดิมมาใหม่ได้เลยค่ะ (ข้อความนี้ไม่คิดเครดิตนะคะ)';
    }

    /** คำชวนเปิดไพ่เมื่อด่านแรก (ChatReadingIntent) จับได้ว่าลูกค้าขอให้ทำนาย — ไม่ต้องถาม AI */
    public static function invite(string $key): string
    {
        $room = self::get(self::normalize($key));

        return (string) ($room['invite'] ?? '');
    }

    /** ประโยคสำเร็จรูปของห้อง (handoff = ส่งต่อห้องคุณไสย · no_offer = ยังเปิดไพ่ซ้ำไม่ได้) — ว่าง = ใช้คำชวนปกติ */
    public static function line(string $key, string $name): string
    {
        return (string) (self::get(self::normalize($key))[$name] ?? '');
    }

    /** ข้อมูลห้องสำหรับหน้าเลือกห้อง/หัวห้องแชท — ราคาอ่านสดจาก Pricing */
    public static function cards(): array
    {
        $out = [];
        foreach (self::all() as $key => $room) {
            $spread = (string) $room['spread'];
            $meta   = TarotSpreads::get($spread);
            // 🃏 (2026-11-06) ไพ่ทุกแบบของห้อง (บุษบา 1 ใบ · 3 ใบ · ไพ่ด่วน) — หน้าเลือกผู้พยากรณ์โชว์ครบพร้อมราคา
            $products = [];
            foreach (self::spreadsOf($room) as $s) {
                if (! array_key_exists($s, TarotSpreads::all())) {
                    continue;
                }
                $m = TarotSpreads::get($s);
                $products[] = [
                    'spread' => $s,
                    'name'   => (string) ($m['name_th'] ?? $s),
                    'cards'  => TarotSpreads::cardCount($s),
                    'price'  => (float) Pricing::for(TarotSpreads::priceKey($s)),
                ];
            }
            $out[]  = [
                'key'     => $key,
                'name'    => $room['name'],
                'short'   => $room['short'],
                'eyebrow' => $room['eyebrow'] ?? '',
                'title'   => $room['title'] ?? '',
                'quote'   => $room['quote'] ?? '',
                'tags'    => $room['tags'] ?? [],
                'busy'    => $room['busy'] ?? '',
                // รูปห้องยังไม่ได้ขึ้นเซิร์ฟเวอร์ (deploy ไม่ครบ) → ใช้รูปแม่หมอเดิม ดีกว่าการ์ดรูปแตก
                'image'   => asset(is_file(public_path($room['image'])) ? $room['image'] : 'images/hero-chantra.webp'),
                'accent'  => $room['accent'] ?? 'violet',
                'open'    => self::isOpen($key),
                'free'    => ! empty($room['free']) && FreeReadingPolicy::enabled() && self::freeRoom() === $key,
                'products' => $products,
                'product' => [
                    'spread' => $spread,
                    'name'   => (string) ($meta['name_th'] ?? $spread),
                    'cards'  => TarotSpreads::cardCount($spread),
                    'price'  => (float) Pricing::for(TarotSpreads::priceKey($spread)),
                ],
            ];
        }

        return $out;
    }
}
