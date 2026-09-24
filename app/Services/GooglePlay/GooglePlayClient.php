<?php

namespace App\Services\GooglePlay;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Google Play Developer API (androidpublisher v3) ด้วย service account — เฉพาะที่การขายเครดิตต้องใช้
 *
 * ไม่ดึง google/apiclient ทั้งก้อน (หลายสิบ MB) มาเพื่อสามคำสั่ง: เซ็น JWT ด้วย openssl แล้วแลก
 * access token เองตาม OAuth 2.0 service-account flow (RFC 7523) — token อายุ 1 ชม. แคชไว้ 50 นาที
 *
 * @see https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.products
 */
class GooglePlayClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    private const BASE = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/';

    /** ตั้งค่าครบพอจะคุยกับ Google ได้ไหม (มีไฟล์คีย์ที่อ่านได้และหน้าตาถูก) */
    public function configured(): bool
    {
        try {
            $this->credentials();

            return true;
        } catch (GooglePlayException) {
            return false;
        }
    }

    /**
     * สถานะการซื้อของ token นี้ตามที่ Google รู้ (ProductPurchase resource)
     *
     * @return array<string,mixed>
     *
     * @throws GooglePlayException
     */
    public function getProductPurchase(string $productId, string $token): array
    {
        $res = $this->api()->get($this->productUrl($productId, $token));
        $this->throwIfFailed($res, 'get');

        return (array) $res->json();
    }

    /** บอก Google ว่าเครดิตถูกส่งมอบแล้ว (consume = acknowledge ด้วย) ลูกค้าจึงซื้อแพ็กเดิมซ้ำได้ */
    public function consumeProductPurchase(string $productId, string $token): void
    {
        $res = $this->api()->post($this->productUrl($productId, $token) . ':consume');
        $this->throwIfFailed($res, 'consume');
    }

    /**
     * การซื้อที่ถูกคืนเงิน/ยกเลิก/chargeback ในช่วงเวลา (Voided Purchases API)
     *
     * @return array{items: list<array<string,mixed>>, next: ?string}
     */
    public function voidedPurchases(int $startTimeMillis, ?string $pageToken = null): array
    {
        $query = array_filter([
            'startTime'  => $startTimeMillis,
            'maxResults' => 1000,
            'token'      => $pageToken,
            'type'       => 0,   // เฉพาะสินค้าแบบซื้อครั้งเดียว (ไม่ใช่ subscription)
        ], fn ($v) => $v !== null);
        $res = $this->api()->get(self::BASE . rawurlencode($this->packageName()) . '/purchases/voidedpurchases', $query);
        $this->throwIfFailed($res, 'voided');
        $json = (array) $res->json();

        return [
            'items' => array_values((array) ($json['voidedPurchases'] ?? [])),
            'next'  => $json['tokenPagination']['nextPageToken'] ?? null,
        ];
    }

    public function packageName(): string
    {
        return (string) config('google_play.package_name', 'com.xjanova.juntra');
    }

    private function productUrl(string $productId, string $token): string
    {
        return self::BASE . rawurlencode($this->packageName())
            . '/purchases/products/' . rawurlencode($productId)
            . '/tokens/' . rawurlencode($token);
    }

    private function api(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout((int) config('google_play.timeout', 15));
    }

    /** @throws GooglePlayException */
    private function throwIfFailed(Response $res, string $op): void
    {
        if ($res->successful()) {
            return;
        }
        $status = $res->status();
        $message = (string) ($res->json('error.message') ?? '');
        // 401 = token หมดอายุกลางทาง: ทิ้งแคช รอบหน้าขอใหม่
        if ($status === 401) {
            Cache::forget($this->tokenCacheKey());
        }
        throw new GooglePlayException(
            "google_play_{$op}_http_{$status}" . ($message !== '' ? ': ' . mb_substr($message, 0, 160) : ''),
            $status,
        );
    }

    /** @throws GooglePlayException */
    private function accessToken(): string
    {
        $cached = Cache::get($this->tokenCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $cred = $this->credentials();
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $cred['private_key_id'] ?? null];
        $claims = [
            'iss'   => $cred['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $cred['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $unsigned = self::b64(json_encode(array_filter($header))) . '.' . self::b64(json_encode($claims));
        $key = openssl_pkey_get_private($cred['private_key']);
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GooglePlayException('google_play_bad_private_key');
        }

        $res = Http::asForm()->timeout((int) config('google_play.timeout', 15))->post($cred['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . self::b64($signature),
        ]);
        $token = (string) $res->json('access_token', '');
        if (! $res->successful() || $token === '') {
            throw new GooglePlayException('google_play_auth_http_' . $res->status(), $res->status());
        }

        $ttl = max(60, (int) $res->json('expires_in', 3600) - 600);
        Cache::put($this->tokenCacheKey(), $token, $ttl);

        return $token;
    }

    /**
     * @return array{client_email:string, private_key:string, token_uri:string, private_key_id?:string}
     *
     * @throws GooglePlayException
     */
    private function credentials(): array
    {
        $path = (string) config('google_play.service_account_path', '');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new GooglePlayException('google_play_not_configured');
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new GooglePlayException('google_play_bad_service_account');
        }
        $json['token_uri'] = $json['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        return $json;
    }

    private function tokenCacheKey(): string
    {
        return 'google_play:access_token:' . sha1((string) config('google_play.service_account_path', ''));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
