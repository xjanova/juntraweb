<?php

namespace App\Services\Thaiprompt;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * เว็บจันทรา ↔ Thaiprompt แบบ "เซิร์ฟเวอร์คุยกับเซิร์ฟเวอร์" — ไม่ผูกกับลูกค้าคนใด
 *
 * ทำไมต้องมี: ทุก endpoint ที่มีอยู่เดิม (/api/v1/juntra/*) ตรวจ token ของ "ลูกค้า"
 * ลูกค้าที่สมัครด้วยเบอร์/อีเมลจึงตรวจสลิปอัตโนมัติไม่ได้เลย และงานของระบบ
 * อย่าง "บันทึกว่าสลิปใบนี้ใช้ที่เว็บแล้ว" หรือ "จองยอดสตางค์ไม่ให้ชนกับบิลของ
 * บอทแม่หมอ" ไม่ใช่เรื่องของลูกค้าคนไหน — ต้องเป็นตัวตนของเว็บเอง
 *
 * ตัวตน = OAuth client ตัวเดียวกับที่ใช้ SSO อยู่แล้ว (Setting thaiprompt_client_id /
 * thaiprompt_client_secret) ขอ token แบบ client_credentials → ไม่มีความลับใหม่ให้ตั้ง
 *
 * กติกาเรื่องเงิน: ทุกเมธอด "ไม่โยน" — ต่อไม่ได้ = คืนสถานะ unavailable ให้ผู้เรียก
 * ส่งต่อแอดมิน ห้ามตีความว่าสลิปผ่านหรือไม่ผ่าน
 */
class JuntraServerClient
{
    private const TOKEN_CACHE = 'juntra:server_token';

    /** เพิ่งขอ token ไม่ผ่าน — อย่ายิงซ้ำทุก request */
    private const TOKEN_MISS = 'juntra:server_token_miss';

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->base() !== '';
    }

    /* ============================ SLIPS ============================ */

    /**
     * ส่งสลิปให้ Thaiprompt ตรวจด้วย SlipOK ชุดเดียวกับบอทแม่หมอ พร้อมบอกว่า
     * เคยถูกใช้ในฝั่งแม่หมอแล้วหรือยัง
     *
     * @return array{status:'ok'|'flood_guard'|'unavailable',data:?array}
     */
    public function verifySlip(string $absolutePath, int|string $userRef, ?float $expectedAmount = null): array
    {
        if (! is_file($absolutePath)) {
            return ['status' => 'unavailable', 'data' => null];
        }

        $resp = $this->send(fn (PendingRequest $http) => $http
            ->timeout(35) // SlipOK เองรอได้ถึง 25 วิ
            ->attach('slip', (string) file_get_contents($absolutePath), basename($absolutePath))
            ->post($this->url('/slips/verify'), array_filter([
                'user_ref'        => (string) $userRef,
                'expected_amount' => $expectedAmount !== null ? number_format($expectedAmount, 2, '.', '') : null,
            ], fn ($v) => $v !== null)));

        if ($resp?->successful() && is_array($resp->json('data'))) {
            return ['status' => 'ok', 'data' => $resp->json('data')];
        }
        if ($resp?->status() === 429) {
            return ['status' => 'flood_guard', 'data' => null];
        }
        $this->logMiss('verifySlip', $resp);

        return ['status' => 'unavailable', 'data' => null];
    }

    /**
     * เลขอ้างอิงชุดนี้ มีใบไหนเคยใช้ในฝั่งแม่หมอแล้วบ้าง (ฟรี ไม่กินโควตา SlipOK)
     *
     * @param  array<int,string>  $refs
     * @return array<int,array>|null  รายการที่เคยใช้ · null = ถามไม่ได้
     */
    public function usedRefs(array $refs): ?array
    {
        $refs = array_values(array_unique(array_filter(array_map('strval', $refs), fn ($r) => $r !== '' && strlen($r) <= 64)));
        if ($refs === []) {
            return [];
        }

        $resp = $this->send(fn (PendingRequest $http) => $http
            ->post($this->url('/slips/check'), ['refs' => array_slice($refs, 0, 25)]));

        if ($resp?->successful()) {
            return (array) ($resp->json('data.used') ?? []);
        }
        $this->logMiss('usedRefs', $resp);

        return null;
    }

    /**
     * จองสลิปใบนี้ว่า "ใช้ที่เว็บจันทราแล้ว" ในทะเบียนกลางของแม่หมอ — ต้องเรียก
     * ก่อนเครดิตเสมอ ใครจองได้ก่อนคนนั้นได้ใช้ (ทะเบียนมี unique กันชนพร้อมกัน)
     *
     * @param  array{trans_ref:string,amount:float|string,user_ref:int|string,topup_ref:string}  $slip
     * @return array{status:'claimed'|'already_used'|'unavailable',data:?array}
     */
    public function claimSlip(array $slip): array
    {
        $body = array_filter([
            'trans_ref'        => (string) $slip['trans_ref'],
            'amount'           => number_format((float) $slip['amount'], 2, '.', ''),
            'user_ref'         => (string) $slip['user_ref'],
            'topup_ref'        => (string) $slip['topup_ref'],
            'sender_name'      => $slip['sender_name'] ?? null,
            'receiver_account' => $slip['receiver_account'] ?? null,
            'sending_bank'     => $slip['sending_bank'] ?? null,
            'receiving_bank'   => $slip['receiving_bank'] ?? null,
            'trans_timestamp'  => $slip['trans_timestamp'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $resp = $this->send(fn (PendingRequest $http) => $http->post($this->url('/slips/claim'), $body));

        if ($resp && in_array($resp->status(), [200, 201], true)) {
            return ['status' => 'claimed', 'data' => (array) $resp->json('data')];
        }
        if ($resp?->status() === 409) {
            return ['status' => 'already_used', 'data' => (array) ($resp->json('data') ?? [])];
        }
        $this->logMiss('claimSlip', $resp);

        return ['status' => 'unavailable', 'data' => null];
    }

    /* ======================= UNIQUE AMOUNTS ======================= */

    /**
     * ขอยอดเศษสตางค์ไม่ซ้ำจาก "ตัวจองกลาง" ของ Thaiprompt — ตัวเดียวกับที่บอท
     * แม่หมอใช้ออกบิล ยอดของสองเว็บจึงไม่มีทางชนกัน (SMS ธนาคารก้อนเดียวถูก
     * ยืนยันได้เว็บเดียว)
     *
     * @return array{id:int,unique_amount:string,base_amount:string,expires_at:?string}|null
     */
    public function reserveAmount(float $base, string $ref, int $externalId, int $ttlMinutes): ?array
    {
        $resp = $this->send(fn (PendingRequest $http) => $http->post($this->url('/amounts/reserve'), [
            'base_amount' => number_format($base, 2, '.', ''),
            'ref'         => $ref,
            'external_id' => $externalId,
            'ttl_minutes' => max(5, min(2880, $ttlMinutes)),
        ]));

        $data = $resp?->successful() ? $resp->json('data') : null;
        if (is_array($data) && isset($data['unique_amount'], $data['id'])) {
            return [
                'id'            => (int) $data['id'],
                'unique_amount' => number_format((float) $data['unique_amount'], 2, '.', ''),
                'base_amount'   => number_format((float) ($data['base_amount'] ?? $base), 2, '.', ''),
                'expires_at'    => $data['expires_at'] ?? null,
            ];
        }
        $this->logMiss('reserveAmount', $resp);

        return null;
    }

    /** คืนยอดที่จองไว้ (ใช้แล้ว/ยกเลิก) — best-effort ไม่สำเร็จก็หมดอายุเอง */
    public function releaseAmount(?int $id, ?string $ref, string $status): bool
    {
        if (! $id && ! $ref) {
            return false;
        }
        $resp = $this->send(fn (PendingRequest $http) => $http->post($this->url('/amounts/release'), array_filter([
            'id'     => $id,
            'ref'    => $ref,
            'status' => in_array($status, ['used', 'cancelled'], true) ? $status : 'cancelled',
        ], fn ($v) => $v !== null)));

        return (bool) $resp?->successful();
    }

    /* ============================ TRANSPORT ============================ */

    /**
     * ยิง 1 ครั้ง ถ้า token หมดอายุ (401) ขอใหม่แล้วลองอีกครั้งเดียว
     *
     * @param  callable(PendingRequest):Response  $call
     */
    private function send(callable $call): ?Response
    {
        if (! $this->isConfigured()) {
            return null;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->token();
            if ($token === null) {
                return null;
            }
            try {
                $resp = $call(Http::acceptJson()->withToken($token)->connectTimeout(4)->timeout(10));
            } catch (\Throwable $e) {
                Log::warning('JuntraServerClient: request threw', ['err' => mb_substr($e->getMessage(), 0, 200)]);

                return null;
            }
            if ($resp->status() !== 401) {
                return $resp;
            }
            Cache::forget(self::TOKEN_CACHE);
        }

        return $resp ?? null;
    }

    private function token(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if (Cache::has(self::TOKEN_MISS)) {
            return null;
        }

        try {
            $resp = Http::asForm()->acceptJson()->connectTimeout(4)->timeout(10)
                ->post($this->base() . '/oauth/token', [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'scope'         => '',
                ]);
        } catch (\Throwable $e) {
            Log::warning('JuntraServerClient: token request threw', ['err' => mb_substr($e->getMessage(), 0, 200)]);
            Cache::put(self::TOKEN_MISS, 1, now()->addSeconds(60));

            return null;
        }

        $token = (string) ($resp->json('access_token') ?? '');
        if (! $resp->successful() || $token === '') {
            // ห้ามลง body — ถ้า upstream echo คำขอกลับมา ความลับของ client จะติดไปด้วย
            Log::warning('JuntraServerClient: token refused', ['status' => $resp->status()]);
            Cache::put(self::TOKEN_MISS, 1, now()->addSeconds(60));

            return null;
        }

        $ttl = max(60, (int) ($resp->json('expires_in') ?? 3600) - 60);
        Cache::put(self::TOKEN_CACHE, $token, now()->addSeconds(min($ttl, 86400)));

        return $token;
    }

    private function logMiss(string $what, ?Response $resp): void
    {
        if ($resp === null) {
            return; // ไม่ได้ตั้งค่า / ต่อไม่ได้ — log ไปแล้วที่ send()/token()
        }
        Log::info("JuntraServerClient::{$what} non-2xx", [
            'status' => $resp->status(),
            'reason' => $resp->json('reason_code'),
        ]);
    }

    private function url(string $path): string
    {
        return $this->base() . '/api/v1/juntra/server' . $path;
    }

    private function base(): string
    {
        return rtrim((string) Setting::get('thaiprompt_base_url', 'https://main.thaiprompt.online'), '/');
    }

    private function clientId(): string
    {
        return trim((string) Setting::get('thaiprompt_client_id', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) Setting::get('thaiprompt_client_secret', ''));
    }
}
