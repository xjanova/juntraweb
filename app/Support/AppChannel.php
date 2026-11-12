<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * 📱 ช่องทางของแอพที่ยิงคำขอนี้ — header `X-App-Channel: play|direct` (แอพตั้งแต่รุ่น web-parity 2026-11)
 *
 * แอพช่อง Google Play ห้ามได้ข้อมูลที่พาไปจ่ายนอก Play (QR พร้อมเพย์ · เลขบัญชีธนาคาร · บิลเติมเงิน)
 * ตามนโยบาย Payments ของ Play — เครดิตในแอพนั้นขายผ่าน Play Billing เท่านั้น
 * ไม่มี header (หน้าเว็บ / แอพ 0.6.0 ที่เป็น APK ตรง) = ไม่ใช่ play
 */
final class AppChannel
{
    public const HEADER = 'X-App-Channel';

    public static function isPlay(Request $request): bool
    {
        return strtolower(trim((string) $request->header(self::HEADER, ''))) === 'play';
    }
}
