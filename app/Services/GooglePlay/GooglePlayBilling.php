<?php

namespace App\Services\GooglePlay;

use App\Models\GooglePlayPurchase;
use App\Models\Setting;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use App\Support\Alerts\WalletAlerts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * เติมเครดิตจากการซื้อผ่าน Google Play Billing — ทางเดียวที่แอพช่อง Play ซื้อเครดิตได้
 *
 * กติกาเรื่องเงิน:
 *   - ไม่เชื่อแอพ: ทุก token ถูกถาม Google (purchases.products.get) ก่อนเติมเสมอ
 *   - หนึ่ง token เติมได้ครั้งเดียว (google_play_purchases.token_hash unique) ส่งซ้ำกี่รอบก็ได้คำตอบเดิม
 *   - token ผูกกับบัญชีที่ซื้อ: แอพส่ง obfuscatedAccountId = {@see accountIdFor()} ตอนเปิดหน้าซื้อ
 *     ถ้า Google ตอบค่าอื่นกลับมา = token ของคนอื่น → ปฏิเสธ
 *   - เติมเครดิต + บันทึกการซื้ออยู่ใน transaction เดียว แล้วค่อย consume (นอก transaction)
 *     consume พลาด = เครดิตเข้าแล้ว แต่ Google ยังไม่รู้ → googleplay:consume-pending ทำซ้ำให้
 *     (Google คืนเงินลูกค้าอัตโนมัติถ้าไม่ consume ใน 3 วัน — และ syncVoided จะดึงเครดิตคืน)
 *   - ลูกค้าขอคืนเงิน/chargeback → googleplay:sync-voided ดึงเครดิตคืนเท่าที่ยังเหลือ (ไม่ติดลบ)
 */
class GooglePlayBilling
{
    public function __construct(
        private GooglePlayClient $client,
        private WalletService $wallet,
    ) {}

    /** ขายผ่าน Play ได้ไหม: แอดมินเปิดสวิตช์ (ค่าเริ่มต้นเปิด) และตั้งคีย์ service account แล้ว */
    public function enabled(): bool
    {
        return Setting::get('google_play_billing_enabled', '1') !== '0' && $this->client->configured();
    }

    /**
     * แพ็กเครดิตที่ขาย: product id => เครดิต — แอดมินแก้ได้ (Setting google_play_products เป็น JSON)
     *
     * @return array<string,float>
     */
    public function products(): array
    {
        $saved = json_decode((string) Setting::get('google_play_products', ''), true);
        $list = is_array($saved) && $saved !== [] ? $saved : (array) config('google_play.products', []);

        $out = [];
        foreach ($list as $id => $credits) {
            $id = trim((string) $id);
            if ($id !== '' && preg_match('/^[a-z0-9][a-z0-9._]{0,99}$/', $id) && is_numeric($credits) && (float) $credits > 0) {
                $out[$id] = (float) $credits;
            }
        }

        return $out;
    }

    /**
     * รหัสบัญชีแบบปิดบังที่แอพส่งให้ Google ตอนซื้อ (obfuscatedAccountId, ไม่เกิน 64 ตัว)
     * ไม่ใช่ user id ตรง ๆ — Google ห้ามส่งข้อมูลที่ระบุตัวบุคคลในช่องนี้
     */
    public function accountIdFor(User $user): string
    {
        return hash_hmac('sha256', 'google-play:' . $user->id, (string) config('app.key'));
    }

    /**
     * ตรวจกับ Google แล้วเติมเครดิต (ครั้งเดียวต่อ token)
     *
     * @return array{state:'credited'|'already'|'pending', purchase:?GooglePlayPurchase}
     *
     * @throws RedeemRejected
     */
    public function redeem(User $user, string $productId, string $token): array
    {
        $products = $this->products();
        if (! isset($products[$productId])) {
            throw new RedeemRejected('unknown_product', 'ไม่พบแพ็กเครดิตนี้ในระบบ — กรุณาอัปเดตแอพแล้วลองใหม่', 422);
        }

        if ($existing = $this->existingFor($user, $token)) {
            $this->consume($existing);

            return ['state' => 'already', 'purchase' => $existing->fresh()];
        }

        try {
            $gp = $this->client->getProductPurchase($productId, $token);
        } catch (GooglePlayException $e) {
            throw $this->mapGoogleError($e, $user, $productId);
        }

        $state = (int) ($gp['purchaseState'] ?? -1);
        if ($state === 2) {
            // ลูกค้าเลือกจ่ายแบบรอชำระ (เช่นจ่ายที่ร้านสะดวกซื้อ) — ยังไม่ได้เงิน ห้ามเติม
            return ['state' => 'pending', 'purchase' => null];
        }
        if ($state !== 0) {
            throw new RedeemRejected('purchase_canceled', 'การซื้อนี้ถูกยกเลิกแล้ว — ไม่มีการเรียกเก็บเงิน', 422);
        }
        if (! empty($gp['productId']) && $gp['productId'] !== $productId) {
            throw new RedeemRejected('purchase_invalid', 'ข้อมูลการซื้อไม่ตรงกับแพ็กเครดิต — กรุณาติดต่อแอดมิน', 422);
        }
        $boundTo = (string) ($gp['obfuscatedExternalAccountId'] ?? '');
        if ($boundTo !== '' && ! hash_equals($this->accountIdFor($user), $boundTo)) {
            $this->alertSecurity($user, $productId, 'token ของการซื้อผูกกับบัญชีอื่น', $gp['orderId'] ?? null);
            throw new RedeemRejected('account_mismatch', 'การซื้อนี้เป็นของบัญชีอื่น — กรุณาเข้าสู่ระบบบัญชีที่ใช้ซื้อ', 409);
        }

        $quantity = max(1, (int) ($gp['quantity'] ?? 1));
        $credits = round($products[$productId] * $quantity, 2);
        $orderId = isset($gp['orderId']) ? mb_substr((string) $gp['orderId'], 0, 100) : null;
        $isTest = ($gp['purchaseType'] ?? null) === 0;

        try {
            $purchase = DB::transaction(function () use ($user, $productId, $token, $orderId, $credits, $gp, $quantity, $isTest) {
                $purchase = GooglePlayPurchase::create([
                    'user_id'        => $user->id,
                    'product_id'     => $productId,
                    'token_hash'     => GooglePlayPurchase::hashToken($token),
                    'purchase_token' => $token,
                    'order_id'       => $orderId,
                    'credits'        => $credits,
                    'status'         => GooglePlayPurchase::STATUS_CREDITED,
                    'purchased_at'   => isset($gp['purchaseTimeMillis'])
                        ? now()->setTimestamp(intdiv((int) $gp['purchaseTimeMillis'], 1000)) : now(),
                    'region_code'    => isset($gp['regionCode']) ? mb_substr((string) $gp['regionCode'], 0, 8) : null,
                ]);

                $tx = $this->wallet->credit($user, $credits, 'เติมเครดิตผ่าน Google Play', [
                    'type'           => 'topup',
                    'method'         => 'google_play',
                    'reference_type' => 'google_play_purchase',
                    'reference_id'   => $purchase->id,
                    'reference_code' => $orderId,
                    'meta'           => array_filter([
                        'source'     => 'google_play',
                        'product_id' => $productId,
                        'order_id'   => $orderId,
                        'quantity'   => $quantity > 1 ? $quantity : null,
                        // บัญชีทดสอบของ Play Console (license tester) — Google ไม่ได้เก็บเงินจริง
                        'test'       => $isTest ?: null,
                    ], fn ($v) => $v !== null),
                ]);
                $purchase->update(['wallet_transaction_id' => $tx->id]);

                return $purchase;
            });
        } catch (UniqueConstraintViolationException) {
            // คำขอพร้อมกันสองคำขอด้วย token เดียว — อีกคำขอเติมไปแล้ว
            if ($existing = $this->existingFor($user, $token)) {
                return ['state' => 'already', 'purchase' => $existing];
            }
            throw new RedeemRejected('token_used', 'การซื้อนี้ถูกใช้เติมเครดิตไปแล้ว', 409);
        }

        $this->consume($purchase);
        if ($tx = $purchase->walletTransaction) {
            WalletAlerts::credited($tx);
        }

        return ['state' => 'credited', 'purchase' => $purchase->fresh()];
    }

    /** บอก Google ว่าส่งมอบแล้ว — พลาดได้ (เครือข่าย) ตัวตั้งเวลาทำซ้ำให้ */
    public function consume(GooglePlayPurchase $purchase): bool
    {
        if ($purchase->consumed_at || $purchase->status !== GooglePlayPurchase::STATUS_CREDITED) {
            return true;
        }
        try {
            $this->client->consumeProductPurchase($purchase->product_id, $purchase->purchase_token);
            $purchase->forceFill(['consumed_at' => now(), 'last_error' => null])->save();

            return true;
        } catch (GooglePlayException $e) {
            // แอพ consume ในเครื่องไปก่อนแล้ว (ทำหลังเราเติมเครดิตเสมอ) — Google ปฏิเสธการ consume ซ้ำ
            // ถามสถานะจริง: consumptionState = 1 แปลว่าเสร็จแล้ว ไม่ต้องลองซ้ำไปเรื่อย ๆ
            try {
                $state = $this->client->getProductPurchase($purchase->product_id, $purchase->purchase_token);
                if ((int) ($state['consumptionState'] ?? 0) === 1) {
                    $purchase->forceFill(['consumed_at' => now(), 'last_error' => null])->save();

                    return true;
                }
            } catch (GooglePlayException) {
                // ถามไม่ได้ก็เก็บไว้ลองรอบหน้า
            }
            $purchase->forceFill([
                'consume_attempts' => $purchase->consume_attempts + 1,
                'last_error'       => mb_substr($e->getMessage(), 0, 255),
            ])->save();
            Log::warning('Google Play consume failed — will retry', ['purchase' => $purchase->id, 'err' => $e->getMessage()]);

            return false;
        }
    }

    /** consume รายการที่ค้าง (เรียกจากตัวตั้งเวลา) */
    public function consumePending(int $limit = 50): int
    {
        $done = 0;
        GooglePlayPurchase::query()
            ->where('status', GooglePlayPurchase::STATUS_CREDITED)
            ->whereNull('consumed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (GooglePlayPurchase $p) use (&$done) {
                if ($this->consume($p)) {
                    $done++;
                } elseif ($p->created_at && $p->created_at->lt(now()->subDays(2))) {
                    // ใกล้เส้น 3 วันที่ Google คืนเงินอัตโนมัติ — ให้แอดมินรู้ก่อน
                    $this->alertSystem('Google Play: consume ไม่ผ่านเกิน 2 วัน', 'รายการ #' . $p->id . ' — ' . ($p->last_error ?? '-'));
                }
            });

        return $done;
    }

    /**
     * ลูกค้าขอคืนเงิน/ยกเลิก/chargeback กับ Google → ดึงเครดิตคืน (เท่าที่ยังเหลือ ไม่ทำให้ติดลบ)
     * อ่านย้อนหลังจากรอบก่อน (Google ให้ย้อนได้ 30 วัน) — ประมวลผลซ้ำได้ไม่เสียหาย
     */
    public function syncVoided(): int
    {
        if (! $this->client->configured()) {
            return 0;
        }
        $since = (int) Setting::get('google_play_voided_since_ms', 0);
        $floor = (now()->subDays(29)->getTimestamp()) * 1000;
        $start = max($since, $floor);
        $startedAt = now()->getTimestamp() * 1000;

        $voided = 0;
        $page = null;
        do {
            $batch = $this->client->voidedPurchases($start, $page);
            foreach ($batch['items'] as $item) {
                $token = (string) ($item['purchaseToken'] ?? '');
                if ($token === '') {
                    continue;
                }
                $purchase = GooglePlayPurchase::where('token_hash', GooglePlayPurchase::hashToken($token))->first();
                if ($purchase && $this->void($purchase, (string) ($item['voidedReason'] ?? ''))) {
                    $voided++;
                }
            }
            $page = $batch['next'];
        } while ($page);

        // ย้อนทับหนึ่งวันทุกรอบ กันรายการที่ Google บันทึกช้า (ประมวลซ้ำไม่เป็นไร — void() ข้ามของที่ทำแล้ว)
        Setting::put('google_play_voided_since_ms', (string) ($startedAt - 86_400_000), 'google_play');

        return $voided;
    }

    /** @return bool true = รายการนี้เพิ่งถูกยกเลิกในรอบนี้ */
    public function void(GooglePlayPurchase $purchase, string $reason = ''): bool
    {
        $won = GooglePlayPurchase::whereKey($purchase->id)
            ->where('status', GooglePlayPurchase::STATUS_CREDITED)
            ->update(['status' => GooglePlayPurchase::STATUS_VOIDED, 'voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 64) ?: null]);
        if ($won !== 1) {
            return false;
        }

        $tx = $purchase->walletTransaction;
        if ($tx && $tx->status === 'success') {
            try {
                $adj = $this->wallet->clawBackTopup($tx, 'ลูกค้าขอคืนเงินกับ Google Play', ['google_play_purchase_id' => $purchase->id]);
                $short = (array) $adj->meta;
                $this->alertSystem(
                    'Google Play: ลูกค้าขอคืนเงิน — ดึงเครดิตคืนแล้ว',
                    'รายการ #' . $purchase->id . ' · ' . $purchase->product_id
                        . (! empty($short['shortfall']) ? "\nเครดิตเหลือไม่พอ ขาดไป ฿" . $short['shortfall'] . ' (ลูกค้าใช้ไปก่อนขอคืน)' : ''),
                    'money',
                );
            } catch (\Throwable $e) {
                Log::critical('Google Play void: claw back failed — manual check needed', ['purchase' => $purchase->id, 'err' => $e->getMessage()]);
            }
        }

        return true;
    }

    private function existingFor(User $user, string $token): ?GooglePlayPurchase
    {
        $existing = GooglePlayPurchase::where('token_hash', GooglePlayPurchase::hashToken($token))->first();
        if (! $existing) {
            return null;
        }
        if ($existing->user_id !== $user->id) {
            $this->alertSecurity($user, $existing->product_id, 'ส่ง token ที่บัญชีอื่นใช้เติมไปแล้ว', $existing->order_id);
            throw new RedeemRejected('token_used', 'การซื้อนี้ถูกใช้เติมเครดิตไปแล้ว', 409);
        }

        return $existing;
    }

    private function mapGoogleError(GooglePlayException $e, User $user, string $productId): RedeemRejected
    {
        Log::warning('Google Play verify failed', ['user' => $user->id, 'product' => $productId, 'err' => $e->getMessage()]);
        if ($e->isInvalidPurchase()) {
            return new RedeemRejected('purchase_invalid', 'ตรวจสอบการซื้อกับ Google Play ไม่ผ่าน — ไม่มีการเติมเครดิต', 422);
        }
        if ($e->isConfigurationProblem()) {
            $this->alertSystem('Google Play: ตรวจการซื้อไม่ได้ (ตั้งค่า service account)', $e->getMessage());

            return new RedeemRejected('billing_unavailable', 'ระบบเติมเครดิตขัดข้องชั่วคราว — การซื้อของลูกค้าปลอดภัย แอพจะลองยืนยันให้อีกครั้งอัตโนมัติ', 503);
        }

        return new RedeemRejected('billing_retry', 'เชื่อมต่อ Google Play ไม่สำเร็จ — การซื้อของลูกค้าปลอดภัย แอพจะลองยืนยันให้อีกครั้งอัตโนมัติ', 503);
    }

    private function alertSecurity(User $user, string $productId, string $what, ?string $orderId): void
    {
        try {
            AdminAlerts::send(new Alert(
                key: 'gp-security:' . $user->id,
                level: Alert::WARNING,
                title: 'Google Play: ' . $what,
                body: 'ปฏิเสธแล้ว ไม่มีการเติมเครดิต',
                facts: array_filter(['ลูกค้า' => '#' . $user->id, 'แพ็ก' => $productId, 'ออเดอร์' => $orderId]),
                url: url('/admin'),
                category: 'security',
            ), 60);
        } catch (\Throwable) {
        }
    }

    private function alertSystem(string $title, string $body, string $category = 'system'): void
    {
        try {
            AdminAlerts::send(new Alert(
                key: 'gp:' . sha1($title),
                level: Alert::WARNING,
                title: $title,
                body: mb_substr($body, 0, 600),
                url: url('/admin'),
                category: $category,
            ), 60);
        } catch (\Throwable) {
        }
    }
}
