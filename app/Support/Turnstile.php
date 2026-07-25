<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile — กันบอทสมัครสมาชิกรัว
 *
 * ทำไมต้องมี: หน้าสมัครเปิดให้กรอกแค่เบอร์โทรได้ (เจ้าของสั่งให้ลดแรงเสียดทาน
 * ก่อนจ่ายเงิน) ซึ่งแปลว่าไม่มีอีเมลให้ยืนยันตัวตนเลย ถ้าไม่มีตัวกันสแปม
 * บอทจะสมัครทิ้งไว้เป็นพัน ๆ บัญชีแล้วดูดโควตาคุยฟรี (ซึ่งตอนนี้ไม่จำกัด)
 *
 * ปิดเองอัตโนมัติเมื่อยังไม่ได้ตั้งคีย์ — เพื่อให้เครื่อง dev และเทสต์
 * ทำงานได้โดยไม่ต้องต่อเน็ต แต่พอตั้งคีย์บน production แล้วจะบังคับทันที
 * (ดังนั้น "ยังไม่ได้ตั้งคีย์" = ไม่มีการป้องกัน ต้องตั้งก่อนเปิดรับลูกค้าจริง)
 */
class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function siteKey(): string
    {
        return (string) config('services.turnstile.site_key', '');
    }

    private static function secret(): string
    {
        return (string) config('services.turnstile.secret', '');
    }

    /** เปิดใช้เมื่อมีคีย์ครบทั้งคู่เท่านั้น */
    public static function enabled(): bool
    {
        return self::siteKey() !== '' && self::secret() !== '';
    }

    /**
     * ตรวจ token ที่ widget ส่งมากับฟอร์ม
     *
     * คืน true เมื่อปิดใช้งาน (ไม่มีคีย์) เพื่อไม่ให้ระบบสมัครตายทั้งระบบ
     * เพราะแอดมินยังไม่ได้ตั้งค่า — แต่เมื่อเปิดใช้แล้ว token ผิด/หาย = false
     *
     * เครือข่ายพังก็คืน false: ปล่อยผ่านเวลาโดน DDoS คือช่วงที่ต้องกันที่สุด
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        try {
            $resp = Http::asForm()
                ->connectTimeout(4)
                ->timeout(8)
                ->post(self::VERIFY_URL, array_filter([
                    'secret'   => self::secret(),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));

            if (! $resp->successful()) {
                Log::warning('Turnstile verify HTTP error', ['status' => $resp->status()]);

                return false;
            }

            $ok = (bool) $resp->json('success', false);
            if (! $ok) {
                Log::info('Turnstile rejected a submission', [
                    'errors' => $resp->json('error-codes', []),
                ]);
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::warning('Turnstile verify threw', ['err' => $e->getMessage()]);

            return false;
        }
    }
}
