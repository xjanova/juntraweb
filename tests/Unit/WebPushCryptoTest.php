<?php

namespace Tests\Unit;

use App\Services\Push\PayloadCipher;
use App\Services\Push\Vapid;
use PHPUnit\Framework\TestCase;

/**
 * 🔒 (2026-11-11) การเข้ารหัส web push เขียนเองด้วย ext-openssl — ตรึงกับค่ามาตรฐานจาก RFC
 *
 * แหล่งอ้างอิงที่ตรึงไว้:
 *   - RFC 8291 "Message Encryption for Web Push" ภาคผนวก A (ตัวอย่างครบทุกค่า: กุญแจ ua/as, salt, auth,
 *     ข้อความ "When I grow up, I want to be a watermelon" และผลลัพธ์ที่ต้องได้ไบต์ต่อไบต์)
 *   - RFC 8292 VAPID: JWT ES256 (r||s 64 ไบต์), aud = origin ของ push service
 * ถ้าเทสต์ RFC พัง = Chrome/Safari/Firefox ถอดข้อความเราไม่ออก (เครื่องผู้ใช้จะไม่ขึ้นอะไรเลย)
 */
class WebPushCryptoTest extends TestCase
{
    private const RFC = [
        'as_private' => 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',
        'as_public' => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',
        'ua_private' => 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94',
        'ua_public' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
        'salt' => 'DGv6ra1nlYgDCS1FRnbzlw',
        'auth' => 'BTBZMqHH6r4Tts7J_aSIgg',
        'plaintext' => 'When I grow up, I want to be a watermelon',
        'body' => 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
    ];

    private function d(string $k): string
    {
        return Vapid::b64uDecode(self::RFC[$k]);
    }

    public function test_encryption_matches_rfc8291_appendix_a_byte_for_byte(): void
    {
        $asPriv = $this->d('as_private');
        $asPub = $this->d('as_public');

        $body = PayloadCipher::encrypt(
            self::RFC['plaintext'], $this->d('ua_public'), $this->d('auth'), $this->d('salt'),
            [$asPriv, $asPub, Vapid::privateKey($asPriv, $asPub)],
        );

        $this->assertSame(self::RFC['body'], Vapid::b64u($body));
    }

    public function test_the_rfc_message_decrypts_with_the_browser_key(): void
    {
        $plain = PayloadCipher::decrypt(
            $this->d('body'), Vapid::privateKey($this->d('ua_private'), $this->d('ua_public')),
            $this->d('ua_public'), $this->d('auth'),
        );

        $this->assertSame(self::RFC['plaintext'], $plain);
    }

    public function test_thai_payload_round_trips_with_a_fresh_browser_key(): void
    {
        [$d, $pub, $key] = Vapid::newKeyPair();
        $auth = random_bytes(16);
        $json = json_encode(['title' => 'ดวงวันนี้ของคุณมาแล้ว 🌙', 'url' => '/account/dashboard'], JSON_UNESCAPED_UNICODE);

        $a = PayloadCipher::encrypt($json, $pub, $auth);
        $b = PayloadCipher::encrypt($json, $pub, $auth);

        $this->assertNotSame($a, $b, 'กุญแจชั่วคราว + salt ต้องใหม่ทุกข้อความ');
        $this->assertSame($json, PayloadCipher::decrypt($a, $key, $pub, $auth));
        $this->assertSame(65, ord($a[20]), 'idlen = จุด P-256 65 ไบต์');
        $this->assertSame(4096, unpack('N', substr($a, 16, 4))[1]);
    }

    public function test_vapid_jwt_is_a_valid_es256_signature_for_the_push_service_origin(): void
    {
        $keys = Vapid::generateKeys();
        $now = 1_800_000_000;
        $header = Vapid::authorization('https://web.push.apple.com/QGuQyavXut', $keys['public'], $keys['private'], 'mailto:owner@example.test', $now);

        $this->assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]+$/', $header);
        preg_match('/t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)/', $header, $m);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(Vapid::b64uDecode($m[1]), true));
        $claims = json_decode(Vapid::b64uDecode($m[2]), true);
        $this->assertSame('https://web.push.apple.com', $claims['aud']);
        $this->assertSame($now + 3600, $claims['exp'], 'Apple ไม่รับ exp เกิน 1 ชม.');
        $this->assertSame('mailto:owner@example.test', $claims['sub']);

        $sig = Vapid::b64uDecode($m[3]);
        $this->assertSame(64, strlen($sig), 'JWS ES256 = r||s 64 ไบต์ ไม่ใช่ DER');
        $this->assertSame(1, openssl_verify("$m[1].$m[2]", Vapid::rawToDer($sig), Vapid::publicKey(Vapid::b64uDecode($m[4])), OPENSSL_ALGO_SHA256));
        $this->assertSame($keys['public'], $m[4]);
    }

    public function test_der_and_raw_signatures_convert_both_ways_including_leading_zero_bytes(): void
    {
        $raw = "\x00\x01".str_repeat("\xAB", 30)."\x80".str_repeat("\x11", 31);
        $this->assertSame($raw, Vapid::derToRaw(Vapid::rawToDer($raw)));
    }

    public function test_generated_keys_have_browser_shapes(): void
    {
        $k = Vapid::generateKeys();
        $pub = Vapid::b64uDecode($k['public']);

        $this->assertSame(65, strlen($pub));
        $this->assertSame("\x04", $pub[0]);
        $this->assertSame(32, strlen(Vapid::b64uDecode($k['private'])));
        $this->assertStringNotContainsString('=', $k['public'].$k['private'], 'base64url ไม่มี padding');
    }

    public function test_a_point_off_the_curve_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        Vapid::publicKey("\x04".str_repeat("\x01", 64));
    }
}
