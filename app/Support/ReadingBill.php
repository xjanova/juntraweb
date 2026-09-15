<?php

namespace App\Support;

use App\Models\Reading;
use App\Models\WalletTransaction;

/**
 * มุมมอง "บิลดูดวง" ของรายการคำทำนาย — หลังบ้านแบบเดียวกับหน้าจัดการบิลของ Thaiprompt
 * (เจ้าของสั่ง 2026-09-15: บิล/คำทำนายของเว็บแยกเก็บจาก Thaiprompt แต่ต้องมีหลังบ้านจัดการเหมือนกัน)
 *
 * บิลของเว็บ = รายการคำทำนาย 1 แถว + แถวตัดเงินในวอลเลต (payload.wallet_tx_id) ถ้าเป็นบริการที่คิดเงิน
 * สถานะคิดจากแถวคำทำนายเองทั้งหมด (ไม่ต้องโหลดธุรกรรมทีละแถวในตาราง):
 *   กำลังอ่าน (pending/working) · ไม่สำเร็จ-คืนเงิน (failed) · คืนเงินแล้ว (แอดมินคืน: payload.refunded_at)
 *   · ฟรี (cost 0) · สำเร็จ
 */
final class ReadingBill
{
    public const STATUSES = [
        'paid' => ['สำเร็จ', 'success'],
        'reading' => ['กำลังอ่านไพ่', 'warning'],
        'failed' => ['ไม่สำเร็จ · คืนเงินแล้ว', 'danger'],
        'refunded' => ['คืนเงินแล้ว', 'gray'],
        'free' => ['ฟรี', 'info'],
    ];

    public const SERVICE_LABELS = [
        'numerology' => 'เลขศาสตร์',
        'palmistry' => 'ลายมือ',
        'auspicious' => 'ฤกษ์ยาม',
        'deep' => 'ดูดวงเชิงลึก',
        'horoscope' => 'ดวงรายวัน',
        'chat' => 'แชท',
    ];

    /** เลขบิลอ่านง่าย คงที่ตลอดอายุรายการ — JTR-ปีเดือนวัน-เลขรายการ */
    public static function number(Reading $r): string
    {
        return 'JTR-'.optional($r->created_at)->format('ymd').'-'.str_pad((string) $r->id, 5, '0', STR_PAD_LEFT);
    }

    /** ย้อนจากเลขบิล (หรือเลขรายการเฉย ๆ) เป็น id — ใช้กับช่องค้นหา */
    public static function idFromNumber(string $q): ?int
    {
        $q = trim($q);
        if (preg_match('/^(?:JTR-\d{6}-)?0*(\d{1,9})$/i', $q, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public static function amount(Reading $r): float
    {
        return (float) data_get($r->payload, 'cost', 0);
    }

    public static function status(Reading $r): string
    {
        return match (true) {
            $r->isInProgress() => 'reading',
            $r->isFailed() => 'failed',
            data_get($r->payload, 'refunded_at') !== null => 'refunded',
            self::amount($r) <= 0 => 'free',
            default => 'paid',
        };
    }

    public static function statusLabel(Reading $r): string
    {
        return self::STATUSES[self::status($r)][0];
    }

    public static function statusColor(Reading $r): string
    {
        return self::STATUSES[self::status($r)][1];
    }

    public static function package(Reading $r): string
    {
        if (TarotSpreads::isTarotType($r->type)) {
            $name = TarotSpreads::nameForType($r->type) ?? 'ไพ่ยิปซี';

            return data_get($r->payload, 'free') ? "{$name} (ฟรี 1 ใบ)" : $name;
        }

        return self::SERVICE_LABELS[$r->type] ?? $r->type;
    }

    public static function channel(Reading $r): string
    {
        return data_get($r->payload, 'source') === 'mobile' ? 'แอพ' : 'เว็บ';
    }

    /** แถวตัดเงินของบิล (โหลดสดเสมอ) */
    public static function debit(Reading $r): ?WalletTransaction
    {
        $id = data_get($r->payload, 'wallet_tx_id');

        return $id ? WalletTransaction::find($id) : null;
    }

    /** แอดมินคืนเงินได้ไหม — มีแถวตัดเงินที่ยังไม่ถูกคืน และไม่ใช่รายการที่ระบบกำลังจัดการอยู่ */
    public static function refundable(Reading $r): bool
    {
        if ($r->isInProgress() || $r->isFailed() || self::amount($r) <= 0) {
            return false;
        }

        return self::debit($r)?->status === 'success';
    }

    /** ทำนายใหม่ได้ไหม — ไพ่ที่ไม่ได้กำลังอ่านอยู่ (ไพ่ฟรี 1 ใบใช้ทางอื่น ไม่รองรับ) */
    public static function retryable(Reading $r): bool
    {
        return TarotSpreads::isTarotType($r->type) && ! $r->isInProgress() && ! data_get($r->payload, 'free');
    }

    /** หน้าเดียวกับที่ลูกค้าเห็น */
    public static function customerUrl(Reading $r): string
    {
        return TarotSpreads::isTarotType($r->type) ? route('tarot.show', $r) : route('reading.show', $r);
    }
}
