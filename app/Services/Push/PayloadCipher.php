<?php

namespace App\Services\Push;

use RuntimeException;

/**
 * 🔒 เข้ารหัสเนื้อหาแจ้งเตือนตาม RFC 8291 (Message Encryption for Web Push) แบบ aes128gcm (RFC 8188)
 *
 * push service (Google/Apple/Mozilla) อ่านข้อความไม่ได้ — มีแต่เบราว์เซอร์เครื่องนั้นที่ถอดได้
 *   1. สร้างกุญแจชั่วคราว (as) ต่อข้อความ → ECDH กับกุญแจของเบราว์เซอร์ (ua, p256dh)
 *   2. IKM  = HKDF(salt=auth, ikm=ecdh, info="WebPush: info\0"||ua||as, 32)
 *   3. CEK  = HKDF(salt, IKM, "Content-Encoding: aes128gcm\0", 16)
 *      NONCE= HKDF(salt, IKM, "Content-Encoding: nonce\0", 12)
 *   4. body = salt(16) || rs(4) || idlen(1)=65 || as(65) || AES-128-GCM(payload || 0x02) || tag(16)
 */
final class PayloadCipher
{
    /** ขนาด record ที่ประกาศ — ข้อความเราสั้นกว่านี้มาก จึงมี record เดียวเสมอ */
    public const RECORD_SIZE = 4096;

    /** เพดานเนื้อหาก่อนเข้ารหัส (push service ส่วนใหญ่รับได้ ~4 KB รวมส่วนหัว) */
    public const MAX_PLAINTEXT = 3000;

    /**
     * @param  string  $uaPublic  กุญแจ p256dh ของเบราว์เซอร์ (65 ไบต์ ดิบ)
     * @param  string  $authSecret  ค่า auth ของเบราว์เซอร์ (16 ไบต์ ดิบ)
     * @param  array{0:string,1:string,2:\OpenSSLAsymmetricKey}|null  $ephemeral  ให้เทสต์ตรึงกุญแจชั่วคราว
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, ?array $ephemeral = null): string
    {
        if (strlen($payload) > self::MAX_PLAINTEXT) {
            throw new RuntimeException('ข้อความแจ้งเตือนยาวเกินไป');
        }
        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('auth secret ต้องยาว 16 ไบต์');
        }

        [, $asPublic, $asKey] = $ephemeral ?? Vapid::newKeyPair();
        $salt ??= random_bytes(16);

        $shared = openssl_pkey_derive(Vapid::publicKey($uaPublic), $asKey, 32);
        if ($shared === false) {
            throw new RuntimeException('ECDH ไม่สำเร็จ: '.openssl_error_string());
        }

        [$cek, $nonce] = self::keys($shared, $authSecret, $salt, $uaPublic, $asPublic);
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('เข้ารหัส AES-GCM ไม่สำเร็จ');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$asPublic.$cipher.$tag;
    }

    /**
     * ถอดรหัสในฐานะเบราว์เซอร์ — ใช้ในเทสต์เพื่อพิสูจน์ว่าสิ่งที่ส่งออกไปถอดกลับได้จริง
     */
    public static function decrypt(string $body, \OpenSSLAsymmetricKey $uaPrivate, string $uaPublic, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $rest = substr($body, 21 + $idLen);
        $cipher = substr($rest, 0, -16);
        $tag = substr($rest, -16);

        $shared = openssl_pkey_derive(Vapid::publicKey($asPublic), $uaPrivate, 32);
        [$cek, $nonce] = self::keys($shared, $authSecret, $salt, $uaPublic, $asPublic);
        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('ถอดรหัสไม่ผ่าน');
        }

        return substr(rtrim($plain, "\x00"), 0, -1); // ตัด padding + ตัวคั่น 0x02
    }

    /** @return array{0:string,1:string} [CEK 16 ไบต์, NONCE 12 ไบต์] */
    private static function keys(string $shared, string $auth, string $salt, string $uaPublic, string $asPublic): array
    {
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00".$uaPublic.$asPublic, $auth);

        return [
            hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt),
            hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt),
        ];
    }
}
