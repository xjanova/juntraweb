<?php

namespace App\Services\Chat;

use App\Http\Controllers\ChatBirthController;
use App\Http\Controllers\ChatTopupController;
use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\ChatCrisis;
use App\Support\ChatPolicy;
use App\Support\ChatRooms;
use App\Support\Pricing;
use App\Support\ReadingRoomLock;
use App\Support\TarotSpreads;
use Illuminate\Support\Facades\Log;

/**
 * 🧭 (2026-11-06) "สถานะห้อง" ที่ส่งไปพร้อมทุกข้อความ — ผู้พยากรณ์รู้ตัวว่าเป็นใคร อยู่ห้องไหน ดูอะไรได้
 * ราคาเท่าไร ลูกค้ากำลังคุยฟรีหรือคุยต่อจากไพ่ที่จ่ายแล้ว เหลือเวลาเท่าไร มีบิลค้าง/การดูที่ค้างอยู่ไหม
 *
 * เจ้าของสั่ง: "บอทต้องทราบดีว่าตัวเองบทบาทเป็นใคร ทำอะไร และอยู่ห้องอะไร ทำอะไร บริบทไหน กำลังดูฟรี
 * ทำนายอยู่ อยู่กับใคร เวลาเหลือเท่าไหร่ ค้างการดูอยู่หรือเปล่า … และเชิญดูดวงต่อได้ เนียน ๆ"
 *
 * เดิมผู้พยากรณ์รู้แค่ persona + บริบทไพ่ (ถ้ามี) → ตอบเรื่องราคา/เครดิต/บิลแบบเดา (เช่นบอกว่า "ได้รับเงินแล้ว"
 * ทั้งที่บิลยังค้าง) และชวนเปิดไพ่ที่ห้องนี้ไม่มีขาย. ทุกตัวเลขในนี้มาจากฐานข้อมูลตอนส่ง ไม่ใช่จากความจำของบอท
 *
 * ส่งเป็นฟิลด์ `room_status` แยกจาก `context` (context = บริบทไพ่ที่ READING_RULES ของ Thaiprompt ใช้)
 * Thaiprompt รุ่นก่อนไม่รู้จักฟิลด์นี้ก็แค่ไม่ใช้ — แชทไม่พัง
 * สร้างไม่สำเร็จด้วยเหตุใดก็ตาม = ส่ง null (สถานะห้องเป็นของเสริม ห้ามทำให้ลูกค้าคุยไม่ได้)
 */
final class RoomStatus
{
    public const MAX_CHARS = 1800;

    public static function safe(User $user, ChatConversation $conversation, ?Reading $reading, ?array $qa): ?string
    {
        try {
            return self::for($user, $conversation, $reading, $qa);
        } catch (\Throwable $e) {
            Log::warning('RoomStatus: build failed', ['user_id' => $user->id, 'err' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
    }

    /** @param array{left:int,minutes:int,warn:int}|null $qa */
    public static function for(User $user, ChatConversation $conversation, ?Reading $reading, ?array $qa): string
    {
        $room   = ChatRooms::normalize($conversation->persona);
        $self   = ChatRooms::selfName($room);
        $crisis = ChatCrisis::active($user);
        $l      = [];

        $l[] = '[สถานะห้อง ณ ' . now('Asia/Bangkok')->format('H:i') . ' น. — ข้อมูลจริงจากระบบ ใช้ตอบให้ถูกต้อง ไม่ต้องอ่านทั้งก้อนให้ลูกค้าฟัง]';
        $l[] = '- คุณคือ "' . ChatRooms::name($room) . '" อยู่ในห้องของตัวเองบนเว็บจันทราพยากรณ์ คุยกับลูกค้า' . self::customerName($user);

        // ---- ไพ่ที่ห้องนี้เปิดได้ (การ์ดบนโต๊ะ) + ห้องอื่นดูอะไร
        //      🤍 ห้องวิกฤต: ไม่ส่งเมนู/ราคา/เครดิต/บิลไปเลย (หน้าจอก็ซ่อนการ์ดอยู่แล้ว) — บอทไม่มีของให้ชวนซื้อ
        $menu  = $crisis ? [] : ChatOffers::roomMenu($room, $user);
        $items = array_map(fn (array $o) => self::menuItem($o), $menu);
        if ($items !== []) {
            $l[] = '- ไพ่ที่ห้องนี้เปิดให้ได้ (ลูกค้ากดการ์ดบนโต๊ะเหนือกล่องแชทเพื่อเปิด — คุณเปิดไพ่เองในแชทไม่ได้): ' . implode(' · ', $items);
        }
        $others = $crisis ? '' : self::otherRooms($room);
        if ($others !== '') {
            $l[] = '- เรื่องที่ห้องนี้ไม่ได้ดู ให้แนะนำห้องอื่น: ' . $others . ' (ลูกค้ากดปุ่ม "เปลี่ยนผู้พยากรณ์" มุมซ้ายบน)';
        }

        // ---- กำลังทำอะไรอยู่: คุยต่อจากไพ่ที่จ่ายแล้ว / พิธีเปิดไพ่ค้าง / คุยฟรี
        $lock = ReadingRoomLock::active(request());
        if ($reading !== null) {
            $name = TarotSpreads::nameForType((string) $reading->type) ?? 'ไพ่ยิปซี';
            $line = "- ตอนนี้: คุยต่อจากไพ่ \"{$name}\" ที่ลูกค้าเปิดและจ่ายแล้ว (คำทำนายอยู่ในบริบทไพ่)";
            if ($qa !== null) {
                $mins = max(1, (int) ceil($qa['left'] / 60));
                $line .= " · เวลาถามต่อเหลือราว {$mins} นาที";
            }
            $l[] = $line . ' — ตอบจากไพ่ชุดนี้ ไม่ต้องชวนซื้อไพ่ใหม่จนกว่าจะหมดเวลาหรือลูกค้าถามเรื่องใหม่';
        } elseif ($lock !== null) {
            $l[] = $lock['reason'] === 'reading'
                ? '- ตอนนี้: ไพ่ชุดที่เพิ่งเปิดกำลังอ่านอยู่ คำทำนายยังไม่เสร็จ — บอกให้รอสักครู่ ห้ามเดาคำทำนายเอง'
                : '- ตอนนี้: ลูกค้าเริ่มพิธีเปิดไพ่ค้างไว้ (ยังเลือกไพ่ไม่ครบ) — ชวนกลับไปเลือกไพ่ให้ครบก่อน';
        } elseif (! $crisis) {
            $l[] = '- ตอนนี้: คุยฟรี ยังไม่ได้เปิดไพ่ในห้องนี้ — ห้ามทำนายเองจากการเดา ถ้าลูกค้าอยากรู้คำตอบ ชวนเลือกการ์ดบนโต๊ะ';
        }

        // ---- เงิน: เครดิต + บิลค้าง (ห้ามบอกว่าได้รับเงินถ้าระบบยังไม่ยืนยัน)
        $bill = $crisis ? null : ChatTopupController::pendingBill($user);
        if (! $crisis) {
            $l[] = '- เครดิตคงเหลือของลูกค้า: ' . Pricing::format((float) app(WalletService::class)->balance($user));
        }
        if ($bill !== null) {
            $l[] = "- มีบิลเติมเงินเลขที่ {$bill->reference_code} ยอด " . Pricing::format((float) $bill->amount)
                . ' รอชำระ ระบบยังไม่ได้รับเงิน — ห้ามบอกว่าได้รับเงินแล้ว ถ้าลูกค้าบอกว่าโอนแล้ว ให้แนบสลิปในการ์ดบิล (ระบบตรวจและเติมให้เอง)';
        }

        // ---- โปรไฟล์
        $birth = $user->profile?->birth_date;
        $l[]   = $birth !== null
            ? '- วันเกิดในโปรไฟล์: ' . ChatBirthController::label($birth) . ' (ถ้าลูกค้าบอกว่าผิด ให้กดปุ่ม 🎂 วันเกิด ใต้กล่องแชทเพื่อแก้)'
            : '- ลูกค้ายังไม่ได้กรอกวันเกิด (ระบบจะถามในแชทเองตอนเปิดไพ่ หรือกดปุ่ม 🎂 วันเกิด ใต้กล่องแชท)';

        $left = ChatPolicy::dailyLeft($user);
        if ($left !== null) {
            $l[] = "- ข้อความคุยฟรีวันนี้เหลือ {$left} ข้อความ";
        }

        // ---- วิธีพูด
        if ($crisis) {
            $l[] = '- ห้องนี้อยู่ในโหมดดูแลใจ: ห้ามชวนเปิดไพ่ ห้ามพูดเรื่องราคา/เติมเงิน ดูแลความรู้สึกลูกค้าอย่างเดียว';
        } else {
            $l[] = "- ตอบคำถามให้ครบก่อนเสมอ ถ้าจังหวะเหมาะค่อยชวนดูต่ออย่างเป็นธรรมชาติหนึ่งประโยคแบบ{$self} (ไม่ชวนทุกข้อความ ไม่ยัดเยียด ไม่แต่งราคาเอง ใช้ราคาตามรายการด้านบนเท่านั้น)";
        }

        return mb_substr(implode("\n", $l), 0, self::MAX_CHARS);
    }

    private static function customerName(User $user): string
    {
        // ชื่อมาจากลูกค้าเอง — ตัดขึ้นบรรทัด/อักขระควบคุม/เครื่องหมายคำพูด/= ไม่ให้แต่งบรรทัดสถานะปลอมได้
        $name = trim((string) preg_replace('/[\p{C}"=]+/u', ' ', (string) $user->name));

        return $name !== '' ? ' ชื่อ "' . mb_substr($name, 0, 40) . '"' : '';
    }

    private static function menuItem(array $o): string
    {
        $price = (float) ($o['price'] ?? 0);
        $label = (string) ($o['label'] ?? '');
        $s     = $label . ' ' . ($price <= 0 ? 'ฟรี' : '฿' . number_format($price, $price == intval($price) ? 0 : 2));
        if (($o['kind'] ?? '') === 'free') {
            if (! empty($o['locked_until'])) {
                $s .= ' (วันนี้ใช้สิทธิ์แล้ว เปิดได้อีก' . $o['locked_until'] . ')';
            } else {
                $s .= ' (วันละใบ ยังไม่ได้ใช้วันนี้)';
            }
        } elseif (! empty($o['locked_until'])) {
            $s .= ' (เพิ่งเปิดไป เปิดซ้ำได้อีก ' . $o['locked_until'] . ')';
        }

        return $s;
    }

    private static function otherRooms(string $room): string
    {
        $out = [];
        foreach (ChatRooms::keys() as $key) {
            if ($key === $room || ! ChatRooms::isOpen($key)) {
                continue;
            }
            $names = [];
            foreach (ChatOffers::roomMenu($key) as $o) {
                if (($o['kind'] ?? '') !== 'free') {
                    $names[] = (string) $o['label'];
                }
            }
            if ($names !== []) {
                $out[] = ChatRooms::name($key) . ' (' . implode(', ', array_slice($names, 0, 5)) . ')';
            }
        }

        return implode(' · ', $out);
    }
}
