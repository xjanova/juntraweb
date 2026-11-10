<?php

namespace App\Services\Push;

use RuntimeException;

/**
 * 🔑 กุญแจ VAPID (RFC 8292) + ของใช้เส้นโค้ง P-256 — เขียนเองด้วย ext-openssl ไม่เพิ่มแพ็กเกจ composer
 *
 * ทำไมไม่ใช้ minishlink/web-push: vendor ของ worktree เป็น junction ไปที่ checkout หลัก และ prod
 * ไม่มี queue worker — สิ่งที่ต้องใช้จริงมีแค่ ECDH + HKDF + AES-128-GCM + ECDSA ซึ่ง openssl มีครบ
 * และเทสต์ถอดรหัสย้อนกลับได้ (tests/Unit/WebPushCryptoTest.php)
 *
 * รูปแบบกุญแจตามที่เบราว์เซอร์ใช้:
 *   public  = 0x04 || X(32) || Y(32)  → 65 ไบต์ base64url (applicationServerKey ของ pushManager.subscribe)
 *   private = d(32)                    → 32 ไบต์ base64url
 */
final class Vapid
{
    /** SubjectPublicKeyInfo ของ id-ecPublicKey + prime256v1 ก่อนตัวจุด 65 ไบต์ */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @return array{public:string, private:string} base64url ทั้งคู่ */
    public static function generateKeys(): array
    {
        [$d, $pub] = self::newKeyPair();

        return ['public' => self::b64u($pub), 'private' => self::b64u($d)];
    }

    /**
     * สร้างคู่กุญแจ P-256 ใหม่
     *
     * @return array{0:string,1:string,2:\OpenSSLAsymmetricKey} [d 32 ไบต์, จุดสาธารณะ 65 ไบต์, ตัวกุญแจ]
     */
    public static function newKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('openssl สร้างกุญแจ P-256 ไม่ได้: '.openssl_error_string());
        }
        $ec = openssl_pkey_get_details($key)['ec'];

        return [self::pad32($ec['d']), "\x04".self::pad32($ec['x']).self::pad32($ec['y']), $key];
    }

    /** กุญแจสาธารณะ 65 ไบต์ → ตัวกุญแจของ openssl (ใช้ทำ ECDH / ตรวจลายเซ็น) */
    public static function publicKey(string $point): \OpenSSLAsymmetricKey
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('กุญแจสาธารณะต้องเป็นจุด P-256 แบบไม่บีบอัด 65 ไบต์');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$point), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('จุดนี้ไม่อยู่บนเส้นโค้ง P-256');
        }

        return $key;
    }

    /** d 32 ไบต์ + จุดสาธารณะ 65 ไบต์ → ตัวกุญแจลับของ openssl (ECPrivateKey ตาม RFC 5915) */
    public static function privateKey(string $d, string $point): \OpenSSLAsymmetricKey
    {
        if (strlen($d) !== 32 || strlen($point) !== 65) {
            throw new RuntimeException('กุญแจ VAPID ผิดขนาด (ต้อง d 32 ไบต์ + จุด 65 ไบต์)');
        }
        $der = hex2bin('30770201010420').$d
            .hex2bin('a00a06082a8648ce3d030107a144034200').$point;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('กุญแจ VAPID เสีย อ่านไม่ได้');
        }

        return $key;
    }

    /**
     * ส่วนหัว Authorization ของคำขอส่งแจ้งเตือน — `vapid t=<JWT ES256>, k=<กุญแจสาธารณะ>`
     *
     * @param  string  $endpoint  URL ของ push service (aud = origin ของมัน)
     */
    public static function authorization(string $endpoint, string $publicB64u, string $privateB64u, string $subject, ?int $now = null): string
    {
        $parts = parse_url($endpoint);
        $aud = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $now ??= time();

        $header = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        // Apple ไม่รับ exp เกิน 1 ชม.ข้างหน้า (ตอบ 403 BadJwtToken) — ใช้ 1 ชม. กับทุกเจ้า
        $claims = self::b64u(json_encode(['aud' => $aud, 'exp' => $now + 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $input = $header.'.'.$claims;

        $pub = self::b64uDecode($publicB64u);
        $key = self::privateKey(self::b64uDecode($privateB64u), $pub);
        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('เซ็น JWT ไม่สำเร็จ');
        }

        return 'vapid t='.$input.'.'.self::b64u(self::derToRaw($der)).', k='.$publicB64u;
    }

    /** กุญแจใน config ใช้ได้ไหม (มีครบ ขนาดถูก และเป็นคู่กันจริง) */
    public static function configured(): bool
    {
        $pub = (string) config('webpush.public_key');
        $priv = (string) config('webpush.private_key');
        if ($pub === '' || $priv === '') {
            return false;
        }
        try {
            $point = self::b64uDecode($pub);
            $key = self::privateKey(self::b64uDecode($priv), $point);
            $ec = openssl_pkey_get_details($key)['ec'];

            return "\x04".self::pad32($ec['x']).self::pad32($ec['y']) === $point;
        } catch (\Throwable) {
            return false;
        }
    }

    /** ลายเซ็น ECDSA แบบ DER (SEQUENCE{INTEGER r, INTEGER s}) → r||s 64 ไบต์ ตามที่ JWS ใช้ */
    public static function derToRaw(string $der): string
    {
        $pos = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$pos]) !== 0x02) {
                throw new RuntimeException('ลายเซ็น DER ผิดรูป');
            }
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\x00");
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
            $pos += 2 + $len;
        }

        return $out;
    }

    /** r||s 64 ไบต์ → DER (ให้เทสต์ตรวจลายเซ็นด้วย openssl_verify) */
    public static function rawToDer(string $raw): string
    {
        $int = function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || (ord($b[0]) & 0x80)) {
                $b = "\x00".$b;
            }

            return "\x02".chr(strlen($b)).$b;
        };
        $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32, 32));

        return "\x30".chr(strlen($body)).$body;
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $bin = base64_decode(strtr($s, '-_', '+/'), true);
        if ($bin === false) {
            throw new RuntimeException('base64url ไม่ถูกต้อง');
        }

        return $bin;
    }

    private static function pad32(string $bin): string
    {
        return str_pad($bin, 32, "\x00", STR_PAD_LEFT);
    }
}
