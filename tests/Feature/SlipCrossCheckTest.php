<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SmsPaymentNotification;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\SlipAutoVerifier;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The slip checker against the owner's rules (2026-09-15): one payment = one credit across the web
 * AND แม่หมอ; SlipOK used exactly like the bot; anything unknown waits for an admin.
 */
class SlipCrossCheckTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    private User $user;

    private WalletTransaction $tx;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put('topup-slips/slip.jpg', 'fake-image-bytes');
        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', '9f1c-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'super-secret-value', 'thaiprompt', true);

        $this->user = User::factory()->create();
        $this->tx = app(WalletService::class)->recordPendingTopup($this->user, 100.37, 'topup-slips/slip.jpg', 'promptpay');
    }

    private function slip(array $overrides = []): array
    {
        return array_merge([
            'ok' => true, 'error_code' => null, 'message' => '',
            'trans_ref' => '016240234342DTF05267', 'amount' => 100.37,
            'receiver_account' => 'xxx-x-x5514-x', 'receiver_name' => 'แม่หมอจันทรา', 'sender_name' => 'นาย ทดสอบ',
            'trans_timestamp' => now()->utc()->toIso8601String(),
            'receiver_matches' => true, 'slip_age_ok' => true,
            'used' => false, 'used_source' => null, 'used_by' => null,
        ], $overrides);
    }

    /** @param array<string,mixed> $claim [status, body] */
    private function fakeThaiprompt(array $verify, array $claim = [201, ['data' => ['claimed' => true]]]): void
    {
        // A fresh factory each time: with Http::fake the FIRST matching stub wins, so re-faking
        // inside one test would otherwise keep answering with the previous case's stubs.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::TP . '/oauth/token' => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
            self::TP . '/api/v1/juntra/server/slips/verify' => Http::response(['data' => $verify]),
            self::TP . '/api/v1/juntra/server/slips/claim' => Http::response($claim[1], $claim[0]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    private function check(): ?array
    {
        return app(SlipAutoVerifier::class)->verify($this->tx->fresh(), $this->user, 'topup-slips/slip.jpg');
    }

    public function test_good_slip_is_claimed_then_credited(): void
    {
        $this->fakeThaiprompt($this->slip());

        $res = $this->check();

        $this->assertTrue($res['paid']);
        $tx = $this->tx->fresh();
        $this->assertSame('success', $tx->status);
        $this->assertSame('016240234342DTF05267', $tx->bank_reference);
        $this->assertSame('approve', data_get($tx->meta, 'slip_check.decision'));
        $this->assertSame(100.37, app(WalletService::class)->balance($this->user));

        // Claimed with the web's server identity, never the customer's token, before crediting.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/slips/claim')
            && $r->hasHeader('Authorization', 'Bearer srv-token')
            && $r['trans_ref'] === '016240234342DTF05267'
            && $r['topup_ref'] === $tx->reference_code
            && $r['user_ref'] === (string) $this->user->id);
    }

    public function test_slip_already_used_at_mae_mor_is_rejected_without_credit(): void
    {
        $this->fakeThaiprompt($this->slip(['used' => true, 'used_source' => 'slip_registry', 'used_by' => ['platform' => 'line']]));

        $res = $this->check();

        $this->assertFalse($res['paid']);
        $this->assertSame('duplicate', $res['decision']);
        $this->assertStringContainsString('บอทแม่หมอ (Line)', $res['message']);
        $this->assertSame('pending', $this->tx->fresh()->status);
        $this->assertSame(0.0, app(WalletService::class)->balance($this->user));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/slips/claim'));
    }

    public function test_claim_conflict_means_someone_else_won_the_race(): void
    {
        $this->fakeThaiprompt($this->slip(), [409, ['reason_code' => 'already_used', 'data' => ['platform' => 'facebook']]]);

        $res = $this->check();

        $this->assertSame('duplicate', $res['decision']);
        $this->assertSame('pending', $this->tx->fresh()->status);
        $this->assertNull($this->tx->fresh()->bank_reference);
    }

    public function test_claim_unreachable_waits_for_admin(): void
    {
        $this->fakeThaiprompt($this->slip(), [500, ['message' => 'boom']]);

        $res = $this->check();

        $this->assertSame('review', $res['decision']);
        $this->assertSame('pending', $this->tx->fresh()->status);
    }

    public function test_claim_endpoint_not_deployed_yet_keeps_old_behaviour(): void
    {
        $this->fakeThaiprompt($this->slip(), [404, ['message' => 'Not Found']]);

        $this->assertTrue($this->check()['paid']);
    }

    public function test_slip_already_credited_on_the_web_is_duplicate(): void
    {
        $other = app(WalletService::class)->recordPendingTopup($this->user, 55, null, 'promptpay');
        $other->update(['bank_reference' => '016240234342DTF05267', 'status' => 'success']);
        $this->fakeThaiprompt($this->slip());

        $this->assertSame('duplicate', $this->check()['decision']);
    }

    public function test_money_already_confirmed_by_sms_for_another_topup_is_duplicate(): void
    {
        $other = app(WalletService::class)->recordPendingTopup($this->user, 100.37, null, 'promptpay');
        SmsPaymentNotification::create([
            'device_id' => 'D1', 'type' => 'credit', 'amount' => '100.37', 'status' => 'confirmed',
            'matched_transaction_id' => $other->id, 'sms_timestamp' => now(),
        ]);
        $this->fakeThaiprompt($this->slip());

        $this->assertSame('duplicate', $this->check()['decision']);
    }

    public function test_unknown_or_bad_slips_wait_for_admin_never_rejected(): void
    {
        foreach ([
            'SlipOK already saw it (1012) but no registry proof' => $this->slip(['ok' => false, 'error_code' => 1012, 'trans_ref' => null]),
            'no QR' => $this->slip(['ok' => false, 'error_code' => 1008, 'trans_ref' => null]),
            'wrong receiver' => $this->slip(['receiver_matches' => false]),
            'too old' => $this->slip(['slip_age_ok' => false]),
            'paid less' => $this->slip(['amount' => 100.00]),
        ] as $case => $verify) {
            $this->fakeThaiprompt($verify);
            $res = $this->check();
            $this->assertSame('review', $res['decision'], $case);
            $this->assertSame('pending', $this->tx->fresh()->status, $case);
        }
        $this->assertSame(0.0, app(WalletService::class)->balance($this->user));
    }

    public function test_client_refused_by_thaiprompt_falls_back_to_the_customers_token(): void
    {
        // Rolling deploy / client not yet allowed: the web's own identity is refused. Slips must keep
        // auto-crediting through the customer's linked token exactly as before — not pile up on admins.
        $this->user->forceFill(['thaiprompt_token' => 'customer-token'])->save();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::TP . '/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
            self::TP . '/api/v1/juntra/payment/verify-slip' => Http::response(['data' => $this->slip()]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $res = $this->check();

        $this->assertTrue($res['paid']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment/verify-slip') && $r->hasHeader('Authorization', 'Bearer customer-token'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/juntra/server/'));
    }

    public function test_thaiprompt_down_sends_the_slip_to_admin(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::TP . '/oauth/token' => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
            self::TP . '/api/v1/juntra/server/slips/verify' => Http::response('Bad gateway', 502),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->assertNull($this->check());
        $this->assertSame('pending', $this->tx->fresh()->status);
        $this->assertSame('review', data_get($this->tx->fresh()->meta, 'slip_check.decision'));
    }

    public function test_nothing_configured_and_no_user_token_goes_to_admin(): void
    {
        Setting::put('thaiprompt_client_id', '', 'thaiprompt');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->assertNull($this->check());
        $this->assertSame('review', data_get($this->tx->fresh()->meta, 'slip_check.decision'));
    }

    public function test_waiting_slip_reaches_telegram_with_the_photo(): void
    {
        if (! \App\Support\Alerts\AlertCard::available()) {
            $this->markTestSkipped('needs GD + FreeType to send the card as a photo');
        }
        Setting::put('telegram_bot_token', '123456789:AAHfakeTokenForTestsOnly_abcdefghij', 'telegram', true);
        Setting::put('telegram_chat_id', '555000111', 'telegram');
        Setting::put('telegram_alerts_enabled', '1', 'telegram');
        $this->fakeThaiprompt($this->slip(['receiver_matches' => false]));

        $this->check();

        $photos = collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'api.telegram.org') && str_contains($r->url(), 'sendPhoto'));
        $this->assertCount(2, $photos, 'the card, then the slip itself as a reply');
        $caption = collect($photos->first()->data())->firstWhere('name', 'caption')['contents'] ?? '';
        $this->assertStringContainsString('สลิปรอแอดมินตรวจ', $caption);
    }
}
