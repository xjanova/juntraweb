<?php

namespace App\Support;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * ข้อห้ามเปิดซ้ำ — ครูบาอาจารย์ห้ามเปิดไพ่ชุดเดิมซ้ำภายในห้วงเวลาหนึ่ง (เจ้าของสั่ง 2026-09-21)
 *
 * ตัวอย่างของเจ้าของ: ไพ่ 12 เดือนเปิดได้เดือนละครั้ง — ตั้งเป็น "ห้ามเปิดซ้ำภายใน N วัน" แยกรายแพ็กเกจ
 * แอดมินปรับได้ที่หน้า "ตั้งค่าวอลเลต/ราคา" (Setting `tarot_<key>_cooldown_days`) ไม่ได้ตั้ง = ค่าใน
 * config/tarot_spreads.php (`cooldown_days`) · 0 = ไม่จำกัด
 *
 * นับเป็นวันแบบเลื่อนจากเวลาที่เปิดจริง ไม่ใช่เดือนปฏิทิน — นับตามปฏิทิน ลูกค้าเปิด 30 ก.ย.
 * แล้วเปิดอีกได้ 1 ต.ค. ซึ่งขัดกับเจตนาของข้อห้าม
 *
 * ที่ไม่นับว่า "เปิดไปแล้ว":
 *   - รายการที่อ่านไม่สำเร็จ (failed — ระบบคืนเงินแล้ว ไม่มีคำทำนายให้อ่าน)
 *   - บิลที่แอดมินคืนเงิน (payload.refunded_at — ReadingBillActions::refund)
 *   - ไพ่ฟรีจากปุ่มดูดวงฟรีของบอท (payload.free) — มีกติกาของมันเองที่ FreeReadingPolicy
 */
final class ReadingCooldown
{
    private const TH_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    /** จำนวนวันที่ห้ามเปิดซ้ำของแพ็กเกจ (0 = ไม่จำกัด) */
    public static function days(string $spreadKey): int
    {
        $v = Setting::get(self::settingKey($spreadKey));
        if ($v !== null && $v !== '' && is_numeric($v)) {
            return max(0, (int) $v);
        }

        return max(0, (int) (TarotSpreads::get($spreadKey)['cooldown_days'] ?? 0));
    }

    public static function settingKey(string $spreadKey): string
    {
        return "tarot_{$spreadKey}_cooldown_days";
    }

    /** คำทำนายที่ยังอยู่ในห้วงห้ามของลูกค้าคนนี้ — null = เปิดได้ */
    public static function blockingReading(?User $user, string $spreadKey): ?Reading
    {
        $days = self::days($spreadKey);
        if (! $user || $days <= 0) {
            return null;
        }

        return Reading::query()
            ->where('user_id', $user->id)
            ->where('type', TarotSpreads::typeFromKey($spreadKey))
            ->where('created_at', '>', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', Reading::STATUS_FAILED))
            ->whereNull('payload->free')
            ->whereNull('payload->refunded_at')
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /** วันเวลาที่เปิดแพ็กเกจนี้ได้อีกครั้ง */
    public static function availableAt(Reading $blocking, string $spreadKey): CarbonInterface
    {
        return $blocking->created_at->copy()->addDays(self::days($spreadKey));
    }

    /** ข้อความบอกลูกค้า (หน้าเว็บ · แอพ) — บอกว่าเปิดไปเมื่อไหร่ เปิดใหม่ได้เมื่อไหร่ และยังไม่หักเงิน */
    public static function message(Reading $blocking, string $spreadKey): string
    {
        $name = TarotSpreads::get($spreadKey)['name_th'] ?? 'ไพ่ชุดนี้';
        $days = self::days($spreadKey);

        return "ครูบาอาจารย์ห้ามเปิด{$name}ซ้ำภายใน {$days} วันค่ะลูก — ลูกเปิดไปเมื่อ "
            .self::thaiDate($blocking->created_at).' คำทำนายเดิมยังใช้ได้อยู่ '
            .'เปิดใหม่ได้ตั้งแต่ '.self::thaiDate(self::availableAt($blocking, $spreadKey))
            .' (ยังไม่มีการหักเครดิต)';
    }

    /** "21 ต.ค. 2569 10:41 น." (เวลาไทย) */
    public static function thaiDate(CarbonInterface $at): string
    {
        $t = $at->copy()->timezone('Asia/Bangkok');

        return $t->day.' '.self::TH_MONTHS[$t->month - 1].' '.($t->year + 543).' '.$t->format('H:i').' น.';
    }
}
