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
 *   1. บัญชีจากระบบแม่หมอ (Thaiprompt) ด้วย token ของคนที่กำลังเปิดหน้าอยู่
 *   2. บัญชีจากระบบแม่หมอ ด้วย token ของ "บัญชีที่ผูกไว้" ตัวใดตัวหนึ่ง (แอดมินก่อน)
 *   3. สำเนาบัญชีล่าสุดที่ดึงมาได้จริง (snapshot ใน settings)
 *   4. ค่าที่ตั้งไว้ในหน้าแอดมินของเว็บ — เหลือไว้เป็นทางสำรองตอนยังไม่เคยดึงสำเร็จ
 *
 * 🔴 (2026-07-26 FIX) เดิมมีแค่ข้อ 1 กับข้อ 4 — upstream ตรวจ token ของ "คนที่
 * กำลังดู" ทำให้ลูกค้าที่สมัครด้วยเบอร์/อีเมล (ไม่เคย SSO ผ่านแม่หมอ = ไม่มี
 * thaiprompt_token) ดึงบัญชีไม่ได้เลยสักคน แล้ว promptpay_id ในเว็บก็ว่างอยู่
 * → หน้า /wallet/topup ขึ้น "แอดมินยังไม่ได้ตั้งค่า PromptPay" = จ่ายเงินไม่ได้
 * (ยืนยันบน prod 2026-07-26) ข้อ 2 กับ 3 จึงตัดขาด "ต้องมี token ของตัวเอง"
 * ออกจากการดึงบัญชีร้าน โดยยังไม่ต้องไปตั้งเลขพร้อมเพย์ซ้ำที่เว็บ
 *
 * ทำไมต้องบัญชีเดียวกัน: SlipOK และตัวจับ SMS ผูกกับบัญชีของบอท ถ้าลูกค้าเว็บ
 * โอนเข้าอีกบัญชี ตัวตรวจสลิปจะเห็นว่า "ปลายทางไม่ใช่บัญชีเรา" แล้วปฏิเสธ
 * ทั้งที่ลูกค้าโอนถูก — กลายเป็นต้องให้แอดมินตรวจมือทุกใบ
 */
class PayoutAccount
{
    /** สำเนาบัญชีล่าสุดที่ upstream ตอบกลับมาจริง (JSON) */
    private const SNAPSHOT_KEY = 'payout_account_snapshot';


    /**
     * @return array{promptpay_id:string,name:string,source:'maemor'|'snapshot'|'local'}|null
     */
    public static function resolve(?User $user = null): ?array
    {
        $client = app(FortuneBotClient::class);

        $remote = $client->payoutAccount($user);
        if (! $remote || empty($remote['promptpay_id'])) {
            // ลูกค้าคนนี้ยังไม่ได้ผูกบัญชีแม่หมอ (หรือ token ตาย) — ยืม token ของ
            // บัญชีที่ผูกไว้มาถามแทน บัญชีรับเงินเป็นของร้าน ไม่ใช่ข้อมูลส่วนตัว
            // ของเจ้าของ token จึงไม่มีข้อมูลใครรั่วไปหาใคร
            $service = ThaipromptServiceAccount::resolve($user);
            $remote = $service ? $client->payoutAccount($service) : null;
        }

        if ($remote && ! empty($remote['promptpay_id'])) {
            $account = [
                'promptpay_id' => (string) $remote['promptpay_id'],
                'name'         => (string) ($remote['account_name'] ?: 'แม่หมอจันทรา'),
                'source'       => 'maemor',
            ];
            self::rememberSnapshot($account);

            return $account;
        }

        // upstream ล่ม / ไม่มีใครผูกบัญชีไว้เลยตอนนี้ — ใช้ใบล่าสุดที่ดึงมาได้จริง
        // ดีกว่าเลขที่แอดมินพิมพ์เองซึ่งมีสิทธิ์ไม่ตรงกับที่ SlipOK ผูกไว้
        $snapshot = self::snapshot();
        if ($snapshot) {
            return [
                'promptpay_id' => $snapshot['promptpay_id'],
                'name'         => $snapshot['name'],
                'source'       => 'snapshot',
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

    /** เลข PromptPay ที่จะใช้สร้าง QR (null = ยังตั้งค่าไม่ได้ทุกทาง) */
    public static function promptpayId(?User $user = null): ?string
    {
        return self::resolve($user)['promptpay_id'] ?? null;
    }

    /** ชื่อบัญชีที่โชว์ใต้ QR ให้ลูกค้าตรวจก่อนโอน */
    public static function name(?User $user = null): string
    {
        return self::resolve($user)['name'] ?? '';
    }

    /* ============================================================
       INTERNAL
       ============================================================ */

    /**
     * เก็บบัญชีที่ดึงมาได้ไว้เป็น "ใบล่าสุดที่ยืนยันแล้ว" ให้ตอน upstream ล่ม
     *
     * เขียนเฉพาะตอนบัญชีเปลี่ยนจริง — resolve() ถูกเรียกทุกครั้งที่มีคนเปิดหน้า
     * เติมเงิน ถ้าเขียนทุกครั้งจะกลายเป็น write ลง settings ทุก request
     *
     * @param  array{promptpay_id:string,name:string}  $account
     */
    private static function rememberSnapshot(array $account): void
    {
        $current = self::snapshot();
        if ($current
            && $current['promptpay_id'] === $account['promptpay_id']
            && $current['name'] === $account['name']) {
            return;
        }

        Setting::put(self::SNAPSHOT_KEY, json_encode([
            'promptpay_id' => $account['promptpay_id'],
            'name'         => $account['name'],
            // เวลาที่ "เห็นบัญชีใบนี้ครั้งแรก" ไม่ใช่เวลาที่ตรวจสอบล่าสุด
            // (ถ้าบัญชีไม่เปลี่ยน เราไม่เขียนซ้ำ) — มีไว้ให้ไล่ย้อนตอนดีบักบนโปรดักชัน
            'saved_at'     => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE), 'payment');
    }

    /** @return array{promptpay_id:string,name:string}|null */
    private static function snapshot(): ?array
    {
        $raw = (string) Setting::get(self::SNAPSHOT_KEY, '');
        if ($raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || empty($data['promptpay_id'])) {
            return null;
        }

        return [
            'promptpay_id' => (string) $data['promptpay_id'],
            'name'         => (string) ($data['name'] ?? 'แม่หมอจันทรา'),
        ];
    }
}
