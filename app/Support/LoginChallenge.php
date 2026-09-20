<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * ด่าน Turnstile แบบ "โผล่เมื่อกรอกผิดซ้ำ" ของหน้าล็อกอินลูกค้า (/login)
 *
 * ทำไมต้องมีที่หน้าลูกค้า ไม่ใช่แค่ /admin/login:
 * หลังบ้านกับหน้าบ้านใช้ guard `web` ตัวเดียวกัน และ User::canAccessPanel()
 * ปล่อย role admin/editor เข้า /admin ได้ — แปลว่าใครเดารหัสแอดมินสำเร็จ
 * ที่ /login ก็เดินเข้าหลังบ้านได้เลย ถ้าใส่ Turnstile แค่ /admin/login
 * คนที่ตั้งใจเดารหัสจะย้ายมายิง /login แทน ด่านหลังบ้านก็เหลือแค่ของประดับ
 *
 * ทำไมไม่บังคับทุกครั้ง: เจ้าของสั่งให้ลดแรงเสียดทานก่อนจ่ายเงิน
 * (ดู {@see Turnstile} — ลูกค้าหลักเป็นผู้สูงอายุ) คนที่กรอกรหัสถูกตั้งแต่
 * ครั้งแรกจึงไม่ต้องเจออะไรเลย ด่านจะโผล่เฉพาะกับคนที่พลาดซ้ำ ๆ
 *
 * นับสองแกน เพราะกันคนละท่า:
 *  - ต่อ IP    — สคริปต์ตัวเดียวไล่สุ่มหลายบัญชี (เปลี่ยนอีเมลทุกครั้ง)
 *  - ต่อบัญชี  — บ็อตเน็ตหลาย IP รุมเดารหัสแอดมินคนเดียว (แกนนี้คือแกน
 *                ที่กันหลังบ้านจริง เพราะ IP ไม่ซ้ำกันเลยจึงนับต่อ IP ไม่ติด)
 *
 * ปิดเองเมื่อยังไม่ได้ตั้งคีย์ Turnstile — ดังนั้นบน production ต้องตั้งคีย์
 * ก่อน ไม่งั้นทั้งไฟล์นี้ไม่ทำอะไรเลย
 */
class LoginChallenge
{
    /** กรอกผิดกี่ครั้งจาก IP เดียวถึงเริ่มท้าทาย (คนพิมพ์พลาด 1–2 ครั้งยังไม่เจอ) */
    public const IP_THRESHOLD = 3;

    /** อายุตัวนับต่อ IP (วินาที) — ผิด 3 ครั้งใน 15 นาทีถือว่าน่าสงสัย */
    public const IP_DECAY = 900;

    /**
     * กรอกผิดกี่ครั้งใส่บัญชีเดียวกัน (รวมทุก IP) ถึงเริ่มท้าทาย
     * ตั้งสูงกว่าแกน IP เพราะคนใช้หลายเครื่อง/หลายเน็ตเป็นเรื่องปกติ
     */
    public const IDENTITY_THRESHOLD = 5;

    /** อายุตัวนับต่อบัญชี (วินาที) — กว้างกว่าเพราะบ็อตเน็ตยิงช้า ๆ ได้ */
    public const IDENTITY_DECAY = 3600;

    /**
     * ธงใน session ของเบราว์เซอร์ที่โดนท้าทายแล้ว
     *
     * จำเป็นเพราะหน้า GET /login ยังไม่รู้ว่าจะกรอกบัญชีไหน จึงเช็คได้แค่
     * แกน IP ถ้าแกนบัญชีเป็นตัวที่สะดุด (บ็อตเน็ตรุมบัญชีเดียว) ฟอร์มจะขอ
     * token ที่ไม่มี widget ให้กรอก ธงนี้ทำให้รอบถัดไป widget โผล่จริง
     */
    private const SESSION_KEY = 'login_challenge_required';

    /**
     * ต้องผ่าน Turnstile ในรอบนี้ไหม
     *
     * @param  string|null  $identity  อีเมล/เบอร์ที่กรอกมา (หน้า GET ยังไม่รู้ ส่ง null)
     */
    public static function required(?string $ip, ?string $identity = null): bool
    {
        // ไม่ได้ตั้งคีย์ = ไม่มีด่านให้ผ่าน อย่าไปขอ token ที่ผู้ใช้ให้ไม่ได้
        if (! Turnstile::enabled()) {
            return false;
        }

        if (self::isSticky()) {
            return true;
        }

        if (RateLimiter::attempts(self::ipKey($ip)) >= self::IP_THRESHOLD) {
            return true;
        }

        $key = self::identityKey($identity);

        return $key !== null
            && RateLimiter::attempts($key) >= self::IDENTITY_THRESHOLD;
    }

    /**
     * บันทึกว่ากรอกรหัสผิดอีกครั้ง
     *
     * เรียกเฉพาะตอน "รหัสไม่ถูก" เท่านั้น — ห้ามเรียกตอน validation ล้ม
     * หรือตอน token ไม่ผ่าน ไม่งั้นคนที่ captcha หมดอายุจะถูกนับเป็นคนเดารหัส
     */
    public static function recordFailure(?string $ip, ?string $identity = null): void
    {
        // RateLimiter::hit ตั้งวันหมดอายุให้ในตัว (ต่างจาก Cache::increment
        // ที่ไม่ตั้ง TTL ในรอบแรกบาง driver — ตัวนับจะค้างตลอดไป)
        RateLimiter::hit(self::ipKey($ip), self::IP_DECAY);

        if (($key = self::identityKey($identity)) !== null) {
            RateLimiter::hit($key, self::IDENTITY_DECAY);
        }

        if (self::required($ip, $identity)) {
            self::markRequired();
        }
    }

    /**
     * ล็อกอินสำเร็จ — ล้างตัวนับทั้งสองแกนและธงใน session
     *
     * ต้องล้างแกนบัญชีด้วย ไม่ใช่แค่แกน IP ไม่งั้นเจ้าของบัญชีที่ลืมรหัส
     * แล้วกรอกถูกในที่สุด จะยังถูกท้าทายต่ออีกชั่วโมงเต็มจากทุกเครื่อง
     */
    public static function clear(?string $ip, ?string $identity = null): void
    {
        RateLimiter::clear(self::ipKey($ip));

        if (($key = self::identityKey($identity)) !== null) {
            RateLimiter::clear($key);
        }

        if (Session::isStarted()) {
            Session::forget(self::SESSION_KEY);
        }
    }

    /**
     * ปักธงว่าเบราว์เซอร์นี้ต้องเจอด่านไปจนล็อกอินสำเร็จ
     *
     * ต้องเปิดให้เรียกจากข้างนอกด้วย เพราะกรณีที่สะดุด "แกนบัญชี" (บ็อตเน็ต
     * หลาย IP รุมบัญชีเดียว) หน้า GET /login ยังไม่รู้ว่าจะกรอกบัญชีไหน จึง
     * ไม่ได้แสดง widget มาแต่แรก — POST รอบนั้นจะขอ token ที่ผู้ใช้ไม่มีทางมี
     * ถ้าไม่ปักธงตอนนั้น เจ้าของบัญชีจะวนอยู่ตรงนั้นตลอดไป ล็อกอินไม่ได้เลย
     */
    public static function markRequired(): void
    {
        if (Session::isStarted()) {
            Session::put(self::SESSION_KEY, true);
        }
    }

    private static function isSticky(): bool
    {
        // เช็ค isStarted ก่อนทุกครั้ง เพราะคลาสนี้อาจถูกเรียกจากทางที่ไม่มี
        // session (artisan, /api/* ที่เป็น stateless) แล้ว Session::get จะพัง
        return Session::isStarted() && Session::get(self::SESSION_KEY) === true;
    }

    private static function ipKey(?string $ip): string
    {
        return 'login-challenge:ip:'.($ip ?: 'unknown');
    }

    /**
     * คีย์ต่อบัญชี — ต้อง normalise ให้ตรงกับที่ฝั่งล็อกอินใช้จริง
     *
     * เบอร์ต้องผ่าน PhoneNumber::normalise() ไม่งั้น "081-234-5678" กับ
     * "0812345678" จะกลายเป็นสองคีย์ แล้วคนเดารหัสก็แค่สลับรูปแบบการพิมพ์
     * เพื่อรีเซ็ตตัวนับ
     */
    private static function identityKey(?string $identity): ?string
    {
        $raw = trim((string) $identity);
        if ($raw === '') {
            return null;
        }

        $normalised = PhoneNumber::normalise($raw) ?? Str::lower($raw);

        return 'login-challenge:id:'.hash('sha256', $normalised);
    }
}
