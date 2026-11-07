<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Support\ChatRooms;
use App\Support\FreeReadingPolicy;
use App\Support\Pricing;
use App\Support\ReadingCooldown;
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
        // 🪬 แพ็กเกจคุณไสยยังไม่เปิดขาย → ข้ามไปเอง เหลือ Celtic + เชิงลึก (ดู for())
        'kunsai'  => ['kunsai', 'celtic', 'deep'],
        'general' => ['three', 'celtic', 'deep'],
    ];

    private const TOPIC_WORD = [
        'love'    => 'เรื่องหัวใจ',
        'career'  => 'เรื่องการงาน',
        'money'   => 'เรื่องการเงิน',
        'health'  => 'เรื่องสุขภาพกายใจ',
        'kunsai'  => 'เรื่องของ-คุณไสย์',
        'general' => 'ดวงชะตา',
    ];

    /**
     * คำชวนเมื่อด่านแรก (ChatReadingIntent) จับได้ — ไม่ต้องถาม AI
     *
     * 🔮 (2026-11-03) ห้องอื่นนอกจากแม่หมอใช้คำชวนในน้ำเสียงของห้องนั้น (config/chat_rooms.php `invite`)
     *    ห้องแม่หมอคงข้อความเดิมทุกตัวอักษร
     */
    public static function invite(string $topic, string $room = ChatRooms::DEFAULT, ?User $user = null): string
    {
        $word = self::TOPIC_WORD[ChatReadingIntent::normalizeTopic($topic)];
        $room = ChatRooms::normalize($room);

        // คำพูดต้องตรงกับการ์ดที่ลูกค้าเห็นจริง (forRoom) — ไม่งั้นบุษบาบอก "ไพ่ 3 ใบ" แต่การ์ดเป็นคุณไสย
        // หรือบอก "กดการ์ดด้านล่าง" ตอนที่ไม่มีการ์ด (ติดข้อห้ามเปิดซ้ำ)
        if (ChatRooms::personaUpstream()) {
            $keys = array_column(self::forRoom($room, $topic, $user), 'key');
            $line = match (true) {
                $keys === [] => ChatRooms::line($room, 'no_offer'),
                $room !== 'kunsai' && $keys === ['tarot_kunsai'] => ChatRooms::line($room, 'handoff'),
                default => '',
            };
            if ($line !== '') {
                return $line;
            }
        }

        $own = $room !== ChatRooms::DEFAULT ? ChatRooms::invite($room) : '';
        if ($own !== '') {
            return str_replace('{topic}', $word, $own);
        }

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
     * 🔮 (2026-11-03) การ์ดของห้องผู้พยากรณ์ — ห้องละ 1 แบบทำนาย (เจ้าของเลือก)
     *
     * 1. เรื่องของ/คุณไสยที่หลุดมาห้องอื่น (topic kunsai) และห้องคุณไสยเปิดอยู่ → การ์ดคุณไสยใบเดียว
     *    (บุษบา/แม่หมอบอกลูกค้าว่า "เรื่องนี้ต้องให้แม่หมอส่องให้โดยเฉพาะ" — การ์ดต้องตรงกับคำพูด)
     * 2. แพ็กเกจของห้องซื้อได้ → การ์ดของห้องใบเดียว
     * 3. ซื้อไม่ได้ (ซ่อน / ติดข้อห้ามเปิดซ้ำ) → ห้องแม่หมอถอยไปการ์ดตามเรื่องแบบเดิม (ห้องหลักของเว็บ ต้องมีของให้เลือกเสมอ)
     *    ห้องอื่นไม่ยื่นการ์ด — ยื่นแพ็กเกจของอีกคนในห้องบุษบา = คำพูด "ไพ่ 3 ใบ" ไม่ตรงกับการ์ดที่เห็น
     */
    public static function forRoom(string $room, string $topic, ?User $user = null): array
    {
        // ยังไม่เปิดห้องผู้พยากรณ์ (CHAT_PERSONA_UPSTREAM) = มีห้องแม่หมอห้องเดียว → การ์ดตามเรื่องแบบเดิมทุกอย่าง
        if (! ChatRooms::personaUpstream()) {
            return self::for($topic, $user);
        }
        $room = ChatRooms::normalize($room);
        if (ChatReadingIntent::normalizeTopic($topic) === 'kunsai' && $room !== 'kunsai' && ChatRooms::isOpen('kunsai')) {
            $card = self::roomCard('kunsai', $user);
            if ($card !== null) {
                return [$card];
            }
        }

        // 🃏 (2026-11-06) ผู้พยากรณ์ยื่นเฉพาะไพ่ที่ตัวเองดู — ห้องแม่หมอเรียงตามเรื่องที่ถามก่อน
        $menu = array_values(array_filter(self::roomMenu($room, $user), fn ($o) => $o['locked_until'] === null));
        if ($room === ChatRooms::DEFAULT) {
            $own = array_column($menu, null, 'key');
            $byTopic = array_values(array_filter(self::for($topic, $user), fn ($o) => isset($own[$o['key']])));
            if ($byTopic !== []) {
                return $byTopic;
            }
        }

        return array_slice($menu, 0, 3);
    }

    /** การ์ดแพ็กเกจของห้อง — null เมื่อซื้อไม่ได้ตอนนี้ */
    private static function roomCard(string $room, ?User $user): ?array
    {
        $spread = ChatRooms::spread($room);
        $meta   = TarotSpreads::all()[$spread] ?? null;
        if ($meta === null || ReadingCooldown::blockingReading($user, $spread) !== null) {
            return null;
        }

        return self::tarotCard($spread, $meta);
    }

    /**
     * @param  User|null  $user  ลูกค้าที่คุยอยู่ — แพ็กเกจที่เขายังติดข้อห้ามเปิดซ้ำ (ReadingCooldown) ไม่ยื่นให้
     *                           (ยื่นไปก็กดซื้อไม่ได้) · null = ไม่กรอง
     * @return array<int,array{key:string,kind:string,spread:?string,label:string,cards:?int,price:float,blurb:string,url:string}>
     */
    public static function for(string $topic, ?User $user = null): array
    {
        $out = [];
        foreach (self::BY_TOPIC[ChatReadingIntent::normalizeTopic($topic)] as $key) {
            if ($key === 'deep') {
                // ดูดวงเชิงลึกปิดอยู่ (ServiceGate) → ไม่ยื่นการ์ดที่กดไปแล้วเจอหน้าปิดปรับปรุง
                if (\App\Support\ServiceGate::isClosed('deep')) {
                    continue;
                }
                $out[] = self::deepCard();

                continue;
            }
            // all() = เฉพาะที่เปิดขาย — แพ็กเกจที่ซ่อนอยู่ห้ามโผล่เป็นการ์ด (กดไปก็ซื้อไม่ได้)
            $meta = TarotSpreads::all()[$key] ?? null;
            if ($meta === null || ReadingCooldown::blockingReading($user, $key) !== null) {
                continue;
            }
            $out[] = self::tarotCard($key, $meta);
        }

        return $out;
    }

    /**
     * 🔮 (2026-11-06) ทุกแบบการเปิดไพ่ของห้อง — แทนหน้ารวมแพ็กเกจ /tarot ที่ปิดไปแล้ว
     *    (เจ้าของสั่ง "หน้าเลือกเปิดไพ่ ชนิดไพ่ ไม่ต้องมีแล้ว ให้ไปเลือกในห้องแทน")
     *
     * แพ็กเกจประจำห้อง (ChatRooms::spread) ขึ้นก่อน ตามด้วยแบบอื่นของห้องตามลำดับในทะเบียน · ห้องแม่หมอปิดท้ายด้วยดูดวงเชิงลึก
     * แบบที่ติดข้อห้ามเปิดซ้ำยังอยู่ในรายการ พร้อม `locked_until` (หน้าเดิมก็บอกบนการ์ดว่าเปิดได้อีกเมื่อไหร่)
     *
     * @return list<array{key:string,kind:string,spread:?string,label:string,cards:?int,price:float,blurb:string,url:string,locked_until:?string}>
     */
    public static function roomMenu(string $room, ?User $user = null): array
    {
        $room  = ChatRooms::normalize($room);
        $order = array_flip(ChatRooms::spreads($room));
        $rows  = [];
        foreach (TarotSpreads::all() as $key => $meta) {
            if (self::menuRoomOf($key) !== $room) {
                continue;
            }
            $blocking = ReadingCooldown::blockingReading($user, $key);
            // แบบที่ไม่อยู่ในรายการของห้อง (ห้องเจ้าของปิด → ตกมาห้องแม่หมอ) ต่อท้ายตามลำดับทะเบียน
            $rows[] = [$order[$key] ?? 1000 + count($rows), self::tarotCard($key, $meta) + [
                'locked_until' => $blocking ? ReadingCooldown::thaiDate(ReadingCooldown::availableAt($blocking, $key)) : null,
            ]];
        }
        usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = array_column($rows, 1);
        if ($room === ChatRooms::DEFAULT && ! \App\Support\ServiceGate::isClosed('deep')) {
            $out[] = self::deepCard() + ['locked_until' => null];
        }
        $free = self::freeCard($room, $user);
        if ($free !== null) {
            array_unshift($out, $free);
        }

        return $out;
    }

    /**
     * 🎁 (2026-11-06) ไพ่ 1 ใบฟรีวันละใบ — การ์ดแรกบนโต๊ะของห้องที่ดูแลไพ่ฟรี (บุษบา)
     *
     * ใช้สิทธิ์วันนี้แล้ว / เครื่องนี้รับแล้ว / ครบเพดานรวม → การ์ดยังอยู่ แต่ล็อก "พรุ่งนี้" (ลูกค้ารู้ว่ามีของฟรีทุกวัน)
     * ยังไม่มีวันเกิด = กดได้ (needs_birth) หน้าแชทขอวันเกิดในแชทก่อนแล้วค่อยพาไปเปิดไพ่
     */
    public static function freeCard(string $room, ?User $user): ?array
    {
        if ($user === null || ChatRooms::freeRoom() !== ChatRooms::normalize($room) || ! FreeReadingPolicy::enabled()) {
            return null;
        }
        $gate = FreeReadingPolicy::gate($user, FreeReadingPolicy::deviceId(request()));
        $locked = match ($gate['code']) {
            null, 'need_birth' => null,
            'in_flight' => 'อีกสักครู่',
            'used', 'device', 'daily_cap' => 'พรุ่งนี้',
            default => false,   // ปิด/ไปถึงฝั่งทำนายไม่ได้ → ไม่วางการ์ด
        };
        if ($locked === false) {
            return null;
        }
        $self = ChatRooms::selfName($room);

        return [
            'key'          => 'free',
            'kind'         => 'free',
            'spread'       => 'single',
            'label'        => 'ไพ่ 1 ใบ ฟรีวันนี้',
            'cards'        => 1,
            'price'        => 0.0,
            'blurb'        => "{$self}เปิดให้ฟรีวันละใบ ตอบไว ตรงประเด็น",
            'url'          => route('tarot.free'),
            'needs_birth'  => $gate['code'] === 'need_birth',
            'locked_until' => $locked,
            'reading_url'  => $gate['code'] === 'used' && ! empty($gate['reading_id']) ? route('tarot.show', $gate['reading_id']) : null,
        ];
    }

    /**
     * ห้องที่ขายแบบไพ่นี้ — แบบประจำห้องบุษบา/คุณไสยอยู่ห้องนั้นเมื่อห้องเปิด ที่เหลือทั้งหมดอยู่ห้องแม่หมอ
     * (ยังไม่เปิดห้องผู้พยากรณ์ = มีห้องแม่หมอห้องเดียว → ทุกแบบอยู่ห้องแม่หมอ)
     */
    public static function menuRoomOf(string $spread): string
    {
        if (! ChatRooms::personaUpstream()) {
            return ChatRooms::DEFAULT;
        }
        $owner = ChatRooms::personaForSpread($spread);

        return ChatRooms::isOpen($owner) ? $owner : ChatRooms::DEFAULT;
    }

    private static function deepCard(): array
    {
        return [
            'key'    => 'deep',
            'kind'   => 'deep',
            'spread' => null,
            'label'  => 'ดูดวงเชิงลึก',
            'cards'  => null,
            'price'  => (float) Pricing::for('deep'),
            'blurb'  => 'แม่หมอผสานดวงดาววันเกิด เลขศาสตร์ และไพ่ ตอบคำถามของลูกแบบเจาะลึก',
            'url'    => route('deep.index'),
        ];
    }

    private static function tarotCard(string $key, array $meta): array
    {
        return [
            'key'    => 'tarot_' . $key,
            'kind'   => 'tarot',
            'spread' => $key,
            'label'  => (string) ($meta['name_th'] ?? $key),
            'cards'  => TarotSpreads::cardCount($key),
            'price'  => (float) Pricing::for(TarotSpreads::priceKey($key)),
            'blurb'  => (string) ($meta['tagline'] ?? ''),
            'url'    => route('chat.index', ['room' => self::menuRoomOf($key)]),
        ];
    }
}
