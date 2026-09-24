<?php

namespace Tests\Feature;

use App\Models\GooglePlayPurchase;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\GooglePlay\GooglePlayBilling;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🛒 (2026-09-24) เติมเครดิตจากแอพช่อง Google Play — นโยบาย Play บังคับให้ขายเครดิตผ่าน Play Billing
 *
 * กติกาเงินที่ตรึงไว้:
 *  - ไม่เชื่อแอพ: ถาม Google ทุกครั้ง · ยังไม่ได้เงิน (pending) / ยกเลิก / token ปลอม = ไม่เติม
 *  - หนึ่ง token เติมได้ครั้งเดียว · token ของบัญชีอื่น (obfuscatedAccountId) = ปฏิเสธ
 *  - เติมแล้ว consume · consume พลาด = ตัวตั้งเวลาทำซ้ำ (ก่อน Google คืนเงินอัตโนมัติใน 3 วัน)
 *  - ลูกค้าขอคืนเงินกับ Google = ดึงเครดิตคืนเท่าที่เหลือ (ไม่ติดลบ)
 */
class GooglePlayBillingTest extends TestCase
{
    use RefreshDatabase;

    private const PID = 'juntra_credits_100';

    private array $purchase = [];

    private int $consumeCalls = 0;

    private bool $consumeFails = false;

    private array $voided = [];

    private string $keyFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'gp-sa-');
        file_put_contents($this->keyFile, json_encode([
            'type' => 'service_account', 'client_email' => 'juntra@proj.iam.gserviceaccount.com',
            'private_key' => $pem, 'private_key_id' => 'k1', 'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        config(['google_play.service_account_path' => $this->keyFile, 'google_play.package_name' => 'com.xjanova.juntra']);

        Http::fake(function (Request $req) {
            $url = $req->url();
            if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'gp-access', 'expires_in' => 3600]);
            }
            if (str_contains($url, '/purchases/voidedpurchases')) {
                return Http::response(['voidedPurchases' => $this->voided]);
            }
            if (str_contains($url, '/purchases/products/') && str_ends_with($url, ':consume')) {
                $this->consumeCalls++;

                return $this->consumeFails ? Http::response(['error' => ['message' => 'backend']], 500) : Http::response('', 204);
            }
            if (str_contains($url, '/purchases/products/')) {
                return $this->purchase === []
                    ? Http::response(['error' => ['message' => 'not found']], 404)
                    : Http::response($this->purchase);
            }

            return Http::response([], 500);
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    private function member(): User
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, 10, 'seed');

        return $u;
    }

    private function paid(User $u, array $over = []): void
    {
        $this->purchase = array_merge([
            'purchaseState' => 0, 'consumptionState' => 0, 'orderId' => 'GPA.1234-5678-9012-34567',
            'purchaseTimeMillis' => (string) (now()->getTimestamp() * 1000), 'quantity' => 1, 'regionCode' => 'TH',
            'obfuscatedExternalAccountId' => app(GooglePlayBilling::class)->accountIdFor($u),
        ], $over);
    }

    private function redeem(string $token = 'tok-aaa-111', string $pid = self::PID)
    {
        return $this->postJson('/api/v1/wallet/google-play/redeem', ['product_id' => $pid, 'purchase_token' => $token]);
    }

    private function balance(User $u): float
    {
        return (float) app(WalletService::class)->balance($u);
    }

    public function test_catalog_is_offered_only_when_configured(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u);

        $this->getJson('/api/v1/wallet/google-play')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.products.1.product_id', self::PID)
            ->assertJsonPath('data.account_id', app(GooglePlayBilling::class)->accountIdFor($u));

        Setting::put('google_play_billing_enabled', '0', 'google_play');
        $this->getJson('/api/v1/wallet/google-play')->assertJsonPath('data.enabled', false)->assertJsonPath('data.products', []);

        Setting::put('google_play_billing_enabled', '1', 'google_play');
        config(['google_play.service_account_path' => null]);
        $this->getJson('/api/v1/wallet/google-play')->assertJsonPath('data.enabled', false);
    }

    public function test_a_verified_purchase_credits_once_and_is_consumed(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u);
        $this->paid($u);

        $this->redeem()->assertCreated()->assertJsonPath('data.state', 'credited')->assertJsonPath('data.consumed', true);
        $this->assertSame(110.0, $this->balance($u));
        $this->assertSame(1, $this->consumeCalls);

        $tx = WalletTransaction::where('method', 'google_play')->sole();
        $this->assertSame('topup', $tx->type);
        $this->assertSame('GPA.1234-5678-9012-34567', $tx->reference_code);

        // ส่ง token เดิมซ้ำ (แอพเก็บตกตอนเปิดใหม่) = ไม่เติมซ้ำ
        $this->redeem()->assertOk()->assertJsonPath('data.state', 'already');
        $this->assertSame(110.0, $this->balance($u));
        $this->assertSame(1, GooglePlayPurchase::count());
    }

    public function test_pending_payment_does_not_credit_yet(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u);
        $this->paid($u, ['purchaseState' => 2]);

        $this->redeem()->assertStatus(202)->assertJsonPath('data.state', 'pending');
        $this->assertSame(10.0, $this->balance($u));
        $this->assertSame(0, GooglePlayPurchase::count());

        // จ่ายเงินแล้ว — ส่ง token เดิมมาใหม่ได้เครดิต
        $this->paid($u);
        $this->redeem()->assertCreated();
        $this->assertSame(110.0, $this->balance($u));
    }

    public function test_canceled_or_unknown_purchases_are_refused(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u);

        $this->paid($u, ['purchaseState' => 1]);
        $this->redeem()->assertStatus(422)->assertJsonPath('reason_code', 'purchase_canceled');

        $this->purchase = [];   // Google: ไม่รู้จัก token นี้
        $this->redeem('forged-token-xyz')->assertStatus(422)->assertJsonPath('reason_code', 'purchase_invalid');

        $this->paid($u);
        $this->redeem('tok-aaa-111', 'juntra_credits_999999')->assertStatus(422)->assertJsonPath('reason_code', 'unknown_product');

        $this->assertSame(10.0, $this->balance($u));
    }

    public function test_a_token_bought_by_another_account_is_refused(): void
    {
        $buyer = $this->member();
        $thief = $this->member();

        // token ผูกกับบัญชีผู้ซื้อ แต่คนอื่นเอามาส่ง
        $this->paid($buyer);
        Sanctum::actingAs($thief);
        $this->redeem()->assertStatus(409)->assertJsonPath('reason_code', 'account_mismatch');
        $this->assertSame(10.0, $this->balance($thief));

        // ผู้ซื้อตัวจริงเติมได้ แล้วคนอื่นเอา token เดิมมาใช้ซ้ำไม่ได้
        Sanctum::actingAs($buyer);
        $this->redeem()->assertCreated();
        Sanctum::actingAs($thief);
        $this->redeem()->assertStatus(409)->assertJsonPath('reason_code', 'token_used');
        $this->assertSame(10.0, $this->balance($thief));
    }

    public function test_failed_consume_is_retried_by_the_scheduler(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u);
        $this->paid($u);
        $this->consumeFails = true;

        $this->redeem()->assertCreated()->assertJsonPath('data.consumed', false);
        $this->assertSame(110.0, $this->balance($u), 'เครดิตเข้าแล้วแม้ consume พลาด');

        $this->consumeFails = false;
        $this->artisan('googleplay:consume-pending')->assertSuccessful();
        $this->assertNotNull(GooglePlayPurchase::sole()->consumed_at);
    }

    public function test_a_purchase_the_app_already_consumed_is_marked_done_not_retried_forever(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u);
        $this->paid($u);
        $this->consumeFails = true;   // Google ปฏิเสธการ consume ของเรา…
        $this->redeem('tok-app-consumed')->assertCreated()->assertJsonPath('data.consumed', false);

        // …เพราะแอพ consume ในเครื่องไปแล้ว (consumptionState = 1)
        $this->purchase['consumptionState'] = 1;
        $this->artisan('googleplay:consume-pending')->assertSuccessful();

        $this->assertNotNull(GooglePlayPurchase::sole()->consumed_at);
        $this->assertSame(110.0, $this->balance($u), 'ไม่มีผลกับเครดิต');
    }

    public function test_a_refund_on_google_play_claws_the_credits_back_without_going_negative(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u);
        $this->paid($u);
        $this->redeem('tok-refund-1')->assertCreated();

        // ลูกค้าใช้ไป 60 ก่อนขอคืนเงินกับ Google
        app(WalletService::class)->debit($u, 60, 'เปิดไพ่');
        $this->assertSame(50.0, $this->balance($u));

        $this->voided = [['purchaseToken' => 'tok-refund-1', 'orderId' => 'GPA.1234-5678-9012-34567', 'voidedReason' => 1]];
        $this->artisan('googleplay:sync-voided')->assertSuccessful();

        $this->assertSame(0.0, $this->balance($u), 'ดึงคืนเท่าที่เหลือ ไม่ติดลบ');
        $purchase = GooglePlayPurchase::sole();
        $this->assertSame('voided', $purchase->status);
        $this->assertSame('refunded', $purchase->walletTransaction->status);
        $adj = WalletTransaction::where('type', 'adjustment')->sole();
        $this->assertSame('50.00', (string) $adj->meta['shortfall']);

        // รอบถัดไปเจอรายการเดิมอีก — ไม่ดึงซ้ำ
        $this->artisan('googleplay:sync-voided')->assertSuccessful();
        $this->assertSame(1, WalletTransaction::where('type', 'adjustment')->count());
        $this->artisan('wallet:reconcile')->assertSuccessful();
    }

    public function test_redeem_says_billing_is_unavailable_when_not_configured(): void
    {
        config(['google_play.service_account_path' => null]);
        $u = $this->member();
        Sanctum::actingAs($u);

        $this->redeem()->assertStatus(503)->assertJsonPath('reason_code', 'billing_unavailable');
        $this->assertSame(10.0, $this->balance($u));
    }
}
