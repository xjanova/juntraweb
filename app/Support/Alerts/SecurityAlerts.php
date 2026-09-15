<?php

namespace App\Support\Alerts;

use App\Models\User;
use App\Support\AdminAlerts;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "ความปลอดภัย": password guessing, admin sign-ins, somebody being handed admin rights, and forged
 * calls to the SMS-payment gateway (the endpoint that can credit wallets).
 *
 * Keyed so an attacker can't turn us into a phone flood: guesses are counted and reported once per
 * IP per hour; the priority ceiling in AdminAlerts keeps CRITICAL cards visible even then.
 */
final class SecurityAlerts
{
    /** Failed logins from one IP in 15 minutes before it counts as guessing. */
    private const GUESS_THRESHOLD = 5;

    public static function loginFailed(?string $identifier, ?string $ip): void
    {
        try {
            if (! AdminAlerts::wants('security') || ! $ip) {
                return;
            }
            $key = 'sec:fail:' . sha1($ip);
            Cache::add($key, 0, now()->addMinutes(15));
            $n = (int) Cache::increment($key);
            $ids = Cache::get($key . ':ids', []);
            $ids = is_array($ids) ? $ids : [];
            if ($identifier) {
                $ids[self::maskId($identifier)] = true;
                Cache::put($key . ':ids', array_slice($ids, -10, null, true), now()->addMinutes(15));
            }
            if ($n < self::GUESS_THRESHOLD) {
                return;
            }

            AdminAlerts::send(new Alert(
                key: 'bruteforce:' . $ip,
                level: count($ids) > 3 ? Alert::CRITICAL : Alert::WARNING,
                title: 'มีคนเดารหัสผ่านเข้าเว็บ',
                body: 'ล็อกอินผิดติดกันจาก IP เดียว' . (count($ids) > 3 ? ' และลองหลายบัญชี (น่าจะเป็นบอท)' : '')
                    . "\nบัญชีที่ถูกลอง: " . implode(', ', array_keys($ids)),
                facts: ['IP' => $ip, 'ผิดใน 15 นาที' => $n . ' ครั้ง', 'บัญชีที่ลอง' => count($ids) . ' บัญชี'],
                url: url('/admin'),
                urlLabel: 'เปิดหน้าแอดมิน',
                category: 'security',
            ), 60);
        } catch (Throwable) {
        }
    }

    public static function lockout(?string $identifier, ?string $ip): void
    {
        try {
            AdminAlerts::send(new Alert(
                key: 'lockout:' . ($ip ?? '-'),
                level: Alert::WARNING,
                title: 'ระบบล็อกการเข้าสู่ระบบชั่วคราว (เดารหัสผิดเกินกำหนด)',
                body: 'IP นี้ถูกหน่วงการล็อกอินอัตโนมัติแล้ว ไม่ต้องทำอะไรถ้าไม่ใช่คนในทีม',
                facts: array_filter(['IP' => $ip, 'บัญชี' => $identifier ? self::maskId($identifier) : null]),
                category: 'security',
            ), 60);
        } catch (Throwable) {
        }
    }

    /** An admin/editor signed in — once per person per IP per 12 hours, silently. */
    public static function staffLogin(User $user, ?string $ip, ?string $agent): void
    {
        try {
            if (! in_array($user->role, ['admin', 'editor'], true)) {
                return;
            }
            AdminAlerts::send(new Alert(
                key: 'staff-login:' . $user->id . ':' . ($ip ?? '-'),
                level: Alert::INFO,
                title: ($user->role === 'admin' ? 'แอดมิน' : 'ผู้ช่วยแอดมิน') . 'เข้าสู่ระบบ: ' . mb_substr((string) $user->name, 0, 40),
                body: 'ถ้าไม่ใช่คุณหรือทีม ให้เปลี่ยนรหัสผ่านทันที และออกจากระบบทุกอุปกรณ์ในหน้า ความปลอดภัยบัญชี',
                facts: array_filter(['IP' => $ip, 'อุปกรณ์' => $agent ? self::device($agent) : null]),
                url: url('/account/security'),
                urlLabel: 'ความปลอดภัยบัญชี',
                category: 'security',
            ), 720);
        } catch (Throwable) {
        }
    }

    /** Someone was just given staff rights — the classic first step after a break-in. */
    public static function roleChanged(User $user, ?string $from, ?string $to, ?User $by): void
    {
        try {
            if (! in_array($to, ['admin', 'editor'], true) && ! in_array($from, ['admin', 'editor'], true)) {
                return;
            }
            $granted = in_array($to, ['admin', 'editor'], true);
            AdminAlerts::send(new Alert(
                key: 'role:' . $user->id . ':' . $to,
                level: $granted ? Alert::CRITICAL : Alert::INFO,
                title: $granted
                    ? 'มีการให้สิทธิ์ ' . $to . ' กับบัญชี ' . mb_substr((string) $user->name, 0, 40)
                    : 'ถอดสิทธิ์ ' . $from . ' จากบัญชี ' . mb_substr((string) $user->name, 0, 40),
                body: $granted ? 'สิทธิ์นี้อนุมัติเงิน/ปรับยอดวอลเลตได้ — ถ้าไม่ได้ตั้งเอง ให้ถอดสิทธิ์และเปลี่ยนรหัสผ่านแอดมินทันที' : '',
                facts: array_filter([
                    'บัญชี' => mb_substr((string) $user->name, 0, 40),
                    'จาก' => $from ?: 'สมาชิก',
                    'เป็น' => $to ?: 'สมาชิก',
                    'ทำโดย' => $by ? mb_substr((string) $by->name, 0, 40) : 'ระบบ/คอนโซล',
                ]),
                url: url('/admin/users/' . $user->id),
                urlLabel: 'ดูบัญชีนี้',
                category: 'security',
            ), 5);
        } catch (Throwable) {
        }
    }

    /**
     * A call to the SMS-payment gateway was refused (unknown API key, bad signature, replayed nonce,
     * stale timestamp). That gateway can credit wallets, so a stranger knocking on it is worth
     * knowing about — once per IP per hour.
     */
    public static function smsGatewayRejected(string $reason, ?string $ip, ?string $deviceId = null): void
    {
        try {
            AdminAlerts::send(new Alert(
                key: 'sms-gw:' . ($ip ?? '-') . ':' . $reason,
                level: Alert::WARNING,
                title: 'มีการเรียก API ของ SMS Checker ที่ไม่ผ่านการยืนยันตัวตน',
                body: 'สาเหตุ: ' . $reason . "\nถ้าเป็นมือถือของร้านเอง ให้สแกน QR ตั้งค่าใหม่จากหน้า อุปกรณ์ SMS Checker · ถ้าไม่ใช่ อาจมีคนพยายามปลอมแจ้งเงินเข้า",
                facts: array_filter(['IP' => $ip, 'อุปกรณ์' => $deviceId ? mb_substr($deviceId, 0, 30) : null]),
                url: url('/admin/sms-checker-devices'),
                urlLabel: 'ดูอุปกรณ์ SMS Checker',
                category: 'security',
            ), 60);
        } catch (Throwable) {
        }
    }

    /** a***@gmail.com / 08****5678 — enough to recognise, not to harvest. */
    private static function maskId(string $id): string
    {
        $id = trim($id);
        if (str_contains($id, '@')) {
            [$u, $d] = explode('@', $id, 2);

            return mb_substr($u, 0, 1) . '***@' . $d;
        }
        $digits = preg_replace('/\D/', '', $id) ?? '';
        if (strlen($digits) >= 8) {
            return substr($digits, 0, 2) . '****' . substr($digits, -4);
        }

        return mb_substr($id, 0, 2) . '***';
    }

    private static function device(string $agent): string
    {
        $os = match (true) {
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            default => 'อื่นๆ',
        };
        $app = match (true) {
            str_contains($agent, 'Dart'), str_contains($agent, 'okhttp') => 'แอพ',
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            str_contains($agent, 'Firefox') => 'Firefox',
            default => 'เบราว์เซอร์',
        };

        return $app . ' · ' . $os;
    }
}
