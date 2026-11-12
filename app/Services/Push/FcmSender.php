<?php

namespace App\Services\Push;

use App\Models\PushDevice;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 📲 (2026-11-12) ส่งข้อความหนึ่งก้อนไปยังแอป Android หนึ่งเครื่อง ผ่าน Firebase Cloud Messaging HTTP v1
 *
 * ช่องทางคู่กับ WebPushSender — PushNotifier ตัดสินว่าส่งอะไรถึงใคร คลาสนี้แค่ส่ง
 * ตั้งค่าใน config/fcm.php (.env FCM_SERVICE_ACCOUNT_JSON / FCM_PROJECT_ID)
 *   ไม่ตั้ง หรืออ่านคีย์ไม่ออก = configured() เป็น false และทุกอย่างเงียบ: ไม่ยิง HTTP ไม่โยน exception ไม่เขียน log
 *
 * access token: เซ็น JWT RS256 ด้วยคีย์ service account แล้วแลกที่ oauth2.googleapis.com (RFC 7523)
 *   แบบเดียวกับ GooglePlayClient ไม่ดึง google/apiclient ทั้งก้อน · token อายุ 1 ชม. แคชไว้จนเหลือ 5 นาที
 *   แลกไม่ผ่าน = พัก 5 นาทีก่อนลองใหม่ (ไม่ยิง Google รัวทุกเครื่อง ไม่เขียน log ทุกข้อความ)
 *
 * ผลลัพธ์:
 *   ok    FCM รับแล้ว
 *   gone  token นี้ตายแล้ว (UNREGISTERED = ถอนแอป/ล้างข้อมูล · INVALID_ARGUMENT ที่ token) → ลบแถวทิ้งทันที
 *   fail  อย่างอื่น (429, 5xx, เน็ตหลุด) → นับ fail_count ถึงเพดานแล้วลบ เหมือน WebPushSender
 *         ยกเว้นปัญหาฝั่งเรา (401/403/404 ที่ไม่ใช่ UNREGISTERED = คีย์/โปรเจกต์ผิด, แลก token ไม่ผ่าน)
 *         ไม่นับเป็นความผิดของเครื่อง — ไม่งั้นตั้งค่าผิดครั้งเดียว เครื่องของทุกคนถูกลบหมด
 *   off   ยังไม่ได้ตั้งค่า FCM
 *
 * ไม่มี queue worker บน prod — ส่งตรงในโปรเซสเดียวกัน timeout สั้นพอไม่ให้คำสั่งตามเวลาค้าง
 */
final class FcmSender
{
    /** พลาดติดกันกี่ครั้งถึงลบเครื่องทิ้ง — เท่ากับ Web Push */
    public const MAX_FAILS = WebPushSender::MAX_FAILS;

    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** ปลายทางแลก token ตายตัว — ไม่อ่าน token_uri จากไฟล์คีย์ (ไฟล์ผิด = ห้ามพาเซิร์ฟเวอร์ไปยิงที่อื่น) */
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    /** แลก token ไม่ผ่านแล้วพักกี่วินาที */
    private const AUTH_COOLDOWN = 300;

    /** @var array<string, array{project:string, email:string, key:string, kid:?string}|false> ผลอ่านคีย์ ตามค่าใน config */
    private static array $parsed = [];

    public static function configured(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * @param  array<string,mixed>  $message  title/body/url(เต็ม)/type/tag/ts
     * @param  string  $urgency  very-low | low | normal | high (ชุดเดียวกับ Web Push — high = priority HIGH)
     * @return string ok | gone | fail | off
     */
    public function send(PushDevice $device, array $message, string $urgency = 'normal', ?string $topic = null): string
    {
        $cred = self::credentials();
        if ($cred === null) {
            return 'off';
        }

        try {
            $accessToken = $this->accessToken($cred);
            if ($accessToken === null) {
                return 'fail';
            }

            try {
                $res = Http::withToken($accessToken)
                    ->acceptJson()
                    ->connectTimeout(4)->timeout(8)
                    ->withOptions(['allow_redirects' => false])
                    ->post(sprintf(self::SEND_URL, rawurlencode($cred['project'])), [
                        'message' => self::payload($device->token, $message, $urgency, $topic),
                    ]);
            } catch (\Throwable $e) {
                Log::info('FCM: send threw', ['device' => $device->id, 'err' => mb_substr($e->getMessage(), 0, 160)]);

                return $this->failed($device);
            }

            if ($res->successful()) {
                $device->forceFill(['fail_count' => 0, 'last_success_at' => now()])->save();

                return 'ok';
            }
            if (self::tokenIsDead($res)) {
                $device->delete();

                return 'gone';
            }

            $code = $res->status();
            Log::info('FCM: rejected', ['device' => $device->id, 'status' => $code, 'body' => mb_substr((string) $res->body(), 0, 160)]);
            if ($code === 401) {
                Cache::forget(self::cacheKey($cred)); // token หมดอายุกลางทาง — รอบหน้าแลกใหม่
            }
            if (in_array($code, [401, 403, 404], true)) {
                return 'fail'; // คีย์/สิทธิ์/โปรเจกต์ฝั่งเราผิด ไม่ใช่เครื่องนี้เสีย
            }

            return $this->failed($device);
        } catch (\Throwable $e) {
            // ห้ามหลุดออกไปพังงานหลัก (อ่านไพ่/เติมเงิน/คำสั่งตามเวลา)
            Log::info('FCM: send failed', ['device' => $device->id, 'err' => mb_substr($e->getMessage(), 0, 160)]);

            return 'fail';
        }
    }

    /**
     * ข้อความแบบ notification + data — แอปอยู่เบื้องหลัง Android โชว์เอง · แตะแล้วแอปอ่าน data.url ไปหน้านั้น
     * ค่าใน data ต้องเป็นสตริงทั้งหมด (กติกาของ FCM)
     *
     * @param  array<string,mixed>  $message
     * @return array<string,mixed>
     */
    public static function payload(string $token, array $message, string $urgency = 'normal', ?string $topic = null): array
    {
        $data = [];
        foreach (['type', 'title', 'body', 'url', 'tag', 'ts'] as $k) {
            if (isset($message[$k]) && is_scalar($message[$k]) && (string) $message[$k] !== '') {
                $data[$k] = (string) $message[$k];
            }
        }
        $tag = self::cleanKey($message['tag'] ?? null);

        $android = [
            'priority' => $urgency === 'high' ? 'HIGH' : 'NORMAL',
            'ttl' => max(0, (int) config('fcm.ttl', 21600)).'s',
        ];
        // topic เดียวกันที่ยังค้างใน FCM = ทับของเก่า (เหมือน Topic ของ Web Push) · tag เดียวกันบนเครื่อง = แทนที่กัน
        if ($collapse = self::cleanKey($topic) ?? $tag) {
            $android['collapse_key'] = $collapse;
        }
        if ($tag !== null) {
            $android['notification'] = ['tag' => $tag];
        }

        return array_filter([
            'token' => $token,
            'notification' => [
                'title' => (string) ($message['title'] ?? ''),
                'body' => (string) ($message['body'] ?? ''),
            ],
            'data' => $data,
            'android' => $android,
        ]);
    }

    /** tag/collapse_key ที่ปลอดภัย (ตัวอักษรอังกฤษ ตัวเลข _ - . :) ไม่งั้น null */
    private static function cleanKey(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $value) ? $value : null;
    }

    /** FCM บอกว่า token นี้ใช้ไม่ได้อีกแล้ว (ไม่ใช่ปัญหาชั่วคราว ไม่ใช่ปัญหาคีย์ของเรา) */
    private static function tokenIsDead(Response $res): bool
    {
        $error = $res->json('error');
        if (! is_array($error)) {
            return false;
        }
        $details = array_filter((array) ($error['details'] ?? []), 'is_array');
        $codes = array_column($details, 'errorCode');
        if (in_array('UNREGISTERED', $codes, true)) {
            return true;
        }
        if ($res->status() !== 400 || (($error['status'] ?? null) !== 'INVALID_ARGUMENT' && ! in_array('INVALID_ARGUMENT', $codes, true))) {
            return false;
        }
        // INVALID_ARGUMENT เป็นได้ทั้ง token เสีย และข้อความเราผิดรูป — ลบเฉพาะกรณีที่ FCM ชี้ว่าเป็นที่ token
        $fields = [];
        foreach ($details as $d) {
            foreach ((array) ($d['fieldViolations'] ?? []) as $v) {
                $fields[] = is_array($v) ? ($v['field'] ?? null) : null;
            }
        }

        return in_array('message.token', $fields, true)
            || str_contains(strtolower((string) ($error['message'] ?? '')), 'registration token');
    }

    private function failed(PushDevice $device): string
    {
        $fails = $device->fail_count + 1;
        if ($fails >= self::MAX_FAILS) {
            $device->delete();
        } else {
            $device->forceFill(['fail_count' => $fails, 'last_failure_at' => now()])->save();
        }

        return 'fail';
    }

    // ── access token ────────────────────────────────────────────────────

    /** @param  array{project:string, email:string, key:string, kid:?string}  $cred */
    private function accessToken(array $cred): ?string
    {
        $cacheKey = self::cacheKey($cred);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if (Cache::has($cacheKey.':cooldown')) {
            return null;
        }

        $now = time();
        $header = array_filter(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $cred['kid']]);
        $claims = ['iss' => $cred['email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600];
        $unsigned = Vapid::b64u(json_encode($header)).'.'.Vapid::b64u(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($cred['key']);
        $signature = '';
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return $this->authFailed($cacheKey, 'sign failed');
        }

        try {
            $res = Http::asForm()->acceptJson()->connectTimeout(4)->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $unsigned.'.'.Vapid::b64u($signature),
                ]);
        } catch (\Throwable $e) {
            return $this->authFailed($cacheKey, mb_substr($e->getMessage(), 0, 160));
        }

        $token = $res->json('access_token');
        if (! $res->successful() || ! is_string($token) || $token === '') {
            // บอกแค่รหัส + ชื่อ error สั้น ๆ (เช่น invalid_grant = คีย์ถูกเพิกถอน/นาฬิกาเครื่องเพี้ยน)
            $error = $res->json('error');

            return $this->authFailed($cacheKey, 'http '.$res->status().(is_string($error) ? ' '.mb_substr($error, 0, 60) : ''));
        }

        Cache::put($cacheKey, $token, max(60, (int) $res->json('expires_in', 3600) - 300));

        return $token;
    }

    private function authFailed(string $cacheKey, string $why): ?string
    {
        Cache::put($cacheKey.':cooldown', 1, self::AUTH_COOLDOWN);
        Log::warning('FCM: access token request failed — app push paused for 5 minutes', ['why' => $why]);

        return null;
    }

    /** @param  array{project:string, email:string, key:string, kid:?string}  $cred */
    private static function cacheKey(array $cred): string
    {
        return 'fcm:access_token:'.sha1($cred['project'].'|'.$cred['email'].'|'.($cred['kid'] ?? ''));
    }

    // ── คีย์ service account ────────────────────────────────────────────

    /** @return array{project:string, email:string, key:string, kid:?string}|null */
    private static function credentials(): ?array
    {
        $raw = trim((string) config('fcm.service_account', ''));
        if ($raw === '') {
            return null;
        }
        $project = trim((string) config('fcm.project_id', ''));
        $memo = sha1($project."\n".$raw);
        if (! array_key_exists($memo, self::$parsed)) {
            self::$parsed[$memo] = self::parse($raw, $project) ?? false;
        }

        return self::$parsed[$memo] ?: null;
    }

    /** @return array{project:string, email:string, key:string, kid:?string}|null */
    private static function parse(string $raw, string $project): ?array
    {
        try {
            $json = self::serviceAccountJson($raw);
            if ($json === null
                || ($json['type'] ?? 'service_account') !== 'service_account'
                || ! is_string($json['client_email'] ?? null) || ! str_contains($json['client_email'], '@')
                || ! is_string($json['private_key'] ?? null)) {
                return null;
            }
            $project = $project !== '' ? $project : (string) ($json['project_id'] ?? '');
            // รหัสโปรเจกต์ของ Google Cloud (มีแบบเก่าที่นำหน้าด้วยโดเมน เช่น example.com:proj)
            if (! preg_match('/^(?:[a-z0-9.-]+:)?[a-z][a-z0-9-]{4,61}[a-z0-9]$/', $project)) {
                return null;
            }
            $key = openssl_pkey_get_private($json['private_key']);
            if ($key === false) {
                while (openssl_error_string() !== false) {
                    // ล้างคิว error ของ openssl ไม่ให้ค้างไปปนกับงานอื่น
                }

                return null;
            }

            return [
                'project' => $project,
                'email' => $json['client_email'],
                'key' => $json['private_key'],
                'kid' => is_string($json['private_key_id'] ?? null) && $json['private_key_id'] !== '' ? $json['private_key_id'] : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** ค่าใน .env เป็นได้สามแบบ: JSON ทั้งก้อน · path ไฟล์ (เต็ม หรือนับจากรากโปรเจกต์) · JSON ที่เข้ารหัส base64 */
    private static function serviceAccountJson(string $raw): ?array
    {
        if (str_starts_with($raw, '{')) {
            return self::decode($raw);
        }
        if (strlen($raw) < 1024 && ! str_contains($raw, "\0")) {
            $path = preg_match('#^(?:[A-Za-z]:[\\\\/]|[\\\\/])#', $raw) ? $raw : base_path($raw);
            if (@is_file($path) && @is_readable($path)) {
                return self::decode(trim((string) @file_get_contents($path)));
            }
        }
        $decoded = base64_decode($raw, true);

        return is_string($decoded) && str_starts_with(ltrim($decoded), '{') ? self::decode(trim($decoded)) : null;
    }

    private static function decode(string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            // dotenv แบบเครื่องหมายคำพูดคู่แปลง \n ใน private_key เป็นขึ้นบรรทัดจริง — JSON ไม่ยอม แปลงกลับก่อนอ่าน
            $data = json_decode(str_replace(["\r\n", "\r", "\n"], '\n', $json), true);
        }

        return is_array($data) ? $data : null;
    }
}
