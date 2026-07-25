<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use App\Services\FortuneBot\FortuneBotClient;

/**
 * บัญชีรับเงินที่เว็บใช้สร้าง QR ให้ลูกค้าสแกนจ่าย
 *
 * เจ้าของกำหนด (2026-07-25): "ค่าการชำระเงิน ใช้ค่าเดิมของดูดวงแม่หมอเดียวกันเลย"
 *
 * ลำดับการเลือก:
 *   1. บัญชีจากระบบแม่หมอ (Thaiprompt) — ตัวเดียวกับที่บอท FB/LINE ใช้
 *   2. ค่าที่ตั้งไว้ในหน้าแอดมินของเว็บ — เหลือไว้เป็นทางสำรองเมื่อ upstream
 *      ล่ม/ยังไม่เชื่อมบัญชี ไม่งั้นระบบเติมเงินจะตายตามกันทั้งหมด
 *
 * ทำไมต้องบัญชีเดียวกัน: SlipOK และตัวจับ SMS ผูกกับบัญชีของบอท ถ้าลูกค้าเว็บ
 * โอนเข้าอีกบัญชี ตัวตรวจสลิปจะเห็นว่า "ปลายทางไม่ใช่บัญชีเรา" แล้วปฏิเสธ
 * ทั้งที่ลูกค้าโอนถูก — กลายเป็นต้องให้แอดมินตรวจมือทุกใบ
 */
class PayoutAccount
{
    /**
     * @return array{promptpay_id:string,name:string,source:'maemor'|'local'}|null
     */
    public static function resolve(?User $user = null): ?array
    {
        $remote = app(FortuneBotClient::class)->payoutAccount($user);
        if ($remote && ! empty($remote['promptpay_id'])) {
            return [
                'promptpay_id' => (string) $remote['promptpay_id'],
                'name'         => (string) ($remote['account_name'] ?: 'แม่หมอจันทรา'),
                'source'       => 'maemor',
            ];
        }

        $localId = (string) Setting::get('promptpay_id', config('pricing.promptpay_id', ''));
        if ($localId !== '') {
            return [
                'promptpay_id' => $localId,
                'name'         => (string) Setting::get('promptpay_name', config('pricing.promptpay_name', '')),
                'source'       => 'local',
            ];
        }

        return null;
    }

    /** เลข PromptPay ที่จะใช้สร้าง QR (null = ยังตั้งค่าไม่ได้ทั้งสองทาง) */
    public static function promptpayId(?User $user = null): ?string
    {
        return self::resolve($user)['promptpay_id'] ?? null;
    }

    /** ชื่อบัญชีที่โชว์ใต้ QR ให้ลูกค้าตรวจก่อนโอน */
    public static function name(?User $user = null): string
    {
        return self::resolve($user)['name'] ?? '';
    }
}
