<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * บัญชีที่ผูกกับแม่หมอไว้ ใช้ token ของคนนี้ถาม upstream แทน "งานของระบบ"
 * ที่ไม่ได้ผูกกับผู้ใช้คนใดคนหนึ่ง เช่น บัญชีรับเงินของร้าน หรือดวงรายวันของราศี
 *
 * ทำไมต้องยืม: ทุก endpoint ของ Thaiprompt ตรวจ token ของผู้เรียก แต่ข้อมูลพวกนี้
 * เป็นของร้าน/ของสาธารณะ ไม่ใช่ข้อมูลส่วนตัวของเจ้าของ token จึงไม่มีข้อมูลใคร
 * รั่วไปหาใคร ถ้าไม่ยืม ลูกค้าที่สมัครด้วยเบอร์/อีเมล กับผู้เยี่ยมชมที่ยังไม่ล็อกอิน
 * จะใช้ฟีเจอร์เหล่านี้ไม่ได้เลย (ดูบั๊กบัญชีรับเงิน 2026-07-26 ใน App\Support\PayoutAccount)
 *
 * เลือกแอดมินก่อน (บัญชีอายุยืนที่สุด ไม่ค่อยเลิกใช้) แล้วค่อยไล่จากคนที่ sync ล่าสุด
 */
final class ThaipromptServiceAccount
{
    private const CACHE_KEY = 'juntra:service_account_id';

    /**
     * @param  User|null  $exclude  คนที่เพิ่งลองแล้วไม่ผ่าน — ไม่ต้องลองซ้ำ
     */
    public static function resolve(?User $exclude = null): ?User
    {
        $id = Cache::get(self::CACHE_KEY);

        if ($id === null) {
            $id = (int) (User::query()
                ->whereNotNull('thaiprompt_token')
                ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
                ->orderByDesc('thaiprompt_synced_at')
                ->orderBy('id')
                ->value('id') ?? 0);

            // cache id ไว้เพราะถูกเรียกทุก request และ thaiprompt_token ไม่มี index
            // — แต่กรณี "ยังไม่มีใครผูกเลย" จำสั้น ๆ พอ เว็บใหม่จะได้ใช้งานได้ทันที
            // ที่คนแรกเชื่อมบัญชี ไม่ใช่รออีก 5 นาที
            Cache::put(self::CACHE_KEY, $id, $id === 0 ? now()->addSeconds(60) : now()->addMinutes(5));
        }

        $id = (int) $id;
        if ($id === 0 || ($exclude && (int) $exclude->getKey() === $id)) {
            return null;
        }

        return User::find($id);
    }

    /** ล้าง cache — เรียกเมื่อรู้ว่า token ของบัญชีที่ยืมอยู่ตายแล้ว */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
