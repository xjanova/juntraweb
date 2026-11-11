<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 📮 ส่งข้อความหนึ่งก้อนไปยังเครื่องหนึ่งเครื่อง (POST ไป push service ของเบราว์เซอร์นั้น)
 *
 * ผลลัพธ์:
 *   ok    push service รับแล้ว (201/202/200)
 *   gone  เครื่องนี้ยกเลิกรับ/ถอนแอปแล้ว (404/410) → ลบแถวทิ้งทันที
 *   fail  อย่างอื่น (403 กุญแจไม่ตรง, 413, 429, 5xx, เน็ตหลุด) → นับ fail_count ถึงเพดานแล้วลบ
 *
 * ไม่มี queue worker บน prod — ส่งตรงในโปรเซสเดียวกัน timeout สั้นพอไม่ให้คำสั่งตามเวลาค้าง
 */
final class WebPushSender
{
    /** พลาดติดกันกี่ครั้งถึงลบเครื่องทิ้ง (ไม่ลบตั้งแต่ครั้งแรก — 5xx/เน็ตหลุดชั่วคราวเกิดได้) */
    public const MAX_FAILS = 5;

    /**
     * @param  array<string,mixed>  $message  เนื้อหาที่ service worker จะอ่าน (title/body/url/tag ...)
     * @param  string  $urgency  very-low | low | normal | high (RFC 8030 §5.3)
     */
    public function send(PushSubscription $sub, array $message, string $urgency = 'normal', ?string $topic = null): string
    {
        if (! self::allowedEndpoint($sub->endpoint)) {
            $sub->delete();

            return 'gone';
        }

        try {
            $body = PayloadCipher::encrypt(
                json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                Vapid::b64uDecode($sub->p256dh),
                Vapid::b64uDecode($sub->auth),
            );
            $headers = [
                'Authorization' => Vapid::authorization(
                    $sub->endpoint,
                    (string) config('webpush.public_key'),
                    (string) config('webpush.private_key'),
                    self::subject(),
                ),
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) max(0, (int) config('webpush.ttl', 21600)),
                'Urgency' => $urgency,
            ];
            if ($topic !== null && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $topic)) {
                $headers['Topic'] = $topic; // ข้อความ topic เดียวกันที่ยังค้างใน push service = ทับของเก่า
            }

            $res = Http::withHeaders($headers)
                ->withBody($body, 'application/octet-stream')
                ->connectTimeout(4)->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->post($sub->endpoint);
        } catch (\Throwable $e) {
            Log::info('Web push: send threw', ['sub' => $sub->id, 'err' => mb_substr($e->getMessage(), 0, 160)]);

            return $this->failed($sub);
        }

        $code = $res->status();
        if (in_array($code, [200, 201, 202], true)) {
            $sub->forceFill(['fail_count' => 0, 'last_success_at' => now()])->save();

            return 'ok';
        }
        if (in_array($code, [404, 410], true)) {
            $sub->delete();

            return 'gone';
        }
        Log::info('Web push: rejected', ['sub' => $sub->id, 'status' => $code, 'body' => mb_substr((string) $res->body(), 0, 160)]);

        return $this->failed($sub);
    }

    /** endpoint ต้องเป็น https ของ push service ที่รู้จักเท่านั้น (กัน SSRF — ดู config/webpush.php) */
    public static function allowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (! $p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            return false;
        }
        $host = strtolower($p['host']);
        foreach ((array) config('webpush.allowed_hosts', []) as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($allowed === '') {
                continue;
            }
            if ($allowed[0] === '.' ? str_ends_with($host, $allowed) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /** sub ของ VAPID ต้องเป็น mailto: หรือ https: — Apple ตอบ 403 BadJwtToken ถ้าเป็นอย่างอื่น */
    public static function subject(): string
    {
        $s = trim((string) config('webpush.subject'));
        if (str_starts_with($s, 'mailto:') || str_starts_with($s, 'https://')) {
            return $s;
        }

        return 'https://'.ltrim(preg_replace('#^https?://#', '', $s ?: 'xn--82c4af5bzdj.online'), '/');
    }

    private function failed(PushSubscription $sub): string
    {
        $fails = $sub->fail_count + 1;
        if ($fails >= self::MAX_FAILS) {
            $sub->delete();
        } else {
            $sub->forceFill(['fail_count' => $fails, 'last_failure_at' => now()])->save();
        }

        return 'fail';
    }
}
