<?php

namespace App\Support;

/**
 * 🔮 ห้องพยากรณ์ในหน้าแชท (config/chat_rooms.php) — แหล่งความจริงเดียวว่าห้องไหนเป็นของใคร ขายอะไร
 *
 * key ของห้อง = persona ที่ส่งไป Thaiprompt ทั้งแชท คำทำนาย และเสียงพากย์
 * ห้องมีแพ็กเกจประจำ (spread) + แพ็กเกจที่รับอ่านเพิ่ม (also) จึงหา persona ของคำทำนายจากแพ็กเกจได้เลย (personaForSpread)
 * ไม่ต้องลากชื่อห้องผ่านหน้าเลือกไพ่ — ซื้อจากหน้า /tarot ตรง ๆ ก็ได้เสียงผู้พยากรณ์ที่ถูกห้อง
 */
final class ChatRooms
{
    public const DEFAULT = 'maemor';

    /** ห้องทั้งหมดตามลำดับในหน้าเลือก (ถูก → แพง) */
    /** ท้ายประโยคราคาแชทบนหน้าเลือกห้อง (เว็บ pages/chat/rooms + แอพ GET /v1/chat/rooms meta.lede) */
    public const PICKER_TAIL = 'อยากให้เปิดไพ่เมื่อไรค่อยเลือก ราคาไพ่หักตอนเปิดไพ่เท่านั้น';

    /**
     * 🤖 (2026-11-04, owner) บอกตรง ๆ ว่าผู้พยากรณ์ทุกห้องคือ AI — คำเดียวกับหน้าเลือกห้องของเว็บ
     * (pages/chat/rooms.blade.php เน้นคำว่า "หมอดู AI" ด้วยตัวหนา) · แก้คำต้องแก้ทั้งสองที่
     */
    public const AI_DISCLOSURE = 'ผู้พยากรณ์ทุกห้องคือหมอดู AI เปิดไพ่แล้วอ่านจากความหมายในตำราไพ่ 78 ใบ ตามใบและตำแหน่งที่ท่านเปิดได้จริง — ไม่เดา ไม่มโน';

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
     * persona ของคำทำนายจากแพ็กเกจ — ผู้พยากรณ์ที่อ่านไพ่ชุดนั้น
     *
     * 1. แพ็กเกจประจำห้อง (quick → บุษบา · kunsai → คุณไสย · celtic → แม่หมอ) อ่านโดยห้องนั้นเสมอ
     * 2. แพ็กเกจใน `also` ของห้อง (ไพ่ไม่ถึง 9 ใบ → บุษบา) — เฉพาะตอนห้องนั้นเปิด
     *    ห้องปิดอยู่ ไพ่ชุดนั้นขายในห้องแม่หมอ จึงต้องเป็นแม่หมอทั้งหน้าตา เสียง และคำทำนาย
     * 3. ไม่มีห้องไหนรับ → แม่หมอ
     */
    public static function personaForSpread(?string $spread): string
    {
        if ($spread === null) {
            return self::DEFAULT;
        }
        foreach (self::all() as $key => $room) {
            if (($room['spread'] ?? null) === $spread) {
                return $key;
            }
        }
        foreach (self::all() as $key => $room) {
            if (in_array($spread, (array) ($room['also'] ?? []), true) && self::isOpen($key)) {
                return $key;
            }
        }

        return self::DEFAULT;
    }

    /** มีห้องไหนประกาศว่าเป็นเจ้าของแพ็กเกจนี้ไหม (spread หรือ also) — ไม่มี = ใช้ห้องที่ลูกค้าอยู่ */
    public static function hasOwner(?string $spread): bool
    {
        if ($spread === null) {
            return false;
        }
        foreach (self::all() as $room) {
            if (($room['spread'] ?? null) === $spread || in_array($spread, (array) ($room['also'] ?? []), true)) {
                return true;
            }
        }

        return false;
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

    /** แพ็กเกจประจำห้อง (ขึ้นก่อนในรายการไพ่ของห้อง · การ์ดที่ห้องยื่นในแชท) */
    public static function spread(string $key): string
    {
        return (string) (self::get(self::normalize($key))['spread'] ?? 'celtic');
    }

    public static function name(string $key): string
    {
        return (string) (self::get(self::normalize($key))['name'] ?? 'แม่หมอจันทรา');
    }

    /**
     * 🌸 (2026-11-11) ชื่อผู้อ่านไพ่ชุดนี้ในข้อความบนเว็บ — "น้องบุษบา" / "แม่หมอ" (ตามห้องที่ขายจริงตอนนี้)
     *   แม่หมอใช้ชื่อสั้นเหมือนข้อความเดิมทั้งเว็บ · ห้องอื่นใช้ชื่อเต็มเพราะ "บุษบา" เฉย ๆ ห้วนเกินในประโยคบรรยาย
     */
    public static function readerName(?string $spread): string
    {
        $key = self::roomForSpread($spread);

        return $key === self::DEFAULT ? self::short($key) : self::name($key);
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

    /** คำที่ผู้พยากรณ์ห้องนี้ใช้เรียกลูกค้า — ทุกห้องเรียก "คุณ" (เจ้าของสั่ง 2026-11-10 เลิกใช้ "ลูก") */
    public static function customer(string $key): string
    {
        return (string) (self::get(self::normalize($key))['customer'] ?? 'คุณ');
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
                'image'   => OracleMedia::asset(is_file(public_path($room['image'])) ? $room['image'] : 'images/hero-chantra.webp'),
                'accent'  => $room['accent'] ?? 'violet',
                'open'    => self::isOpen($key),
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
