<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\User;
use App\Models\Zodiac;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Guards the app↔juntraweb seam parity fixes from the 3-repo integration audit.
 */
class MobileApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Mobile chat must not charge for the degraded "not ready" placeholder (web-parity fix). */
    public function test_mobile_chat_does_not_charge_for_degraded_placeholder(): void
    {
        $u = User::factory()->create(); // no thaiprompt_token → pool unavailable
        app(WalletService::class)->credit($u, 100, 'seed');
        $convo = ChatConversation::create([
            'user_id'       => $u->id,
            'session_token' => (string) Str::uuid(),
            'title'         => 'test',
        ]);
        Sanctum::actingAs($u);

        // No token + no ai_api_key → reply is the degraded placeholder → free.
        $r = $this->postJson("/api/v1/chat/conversations/{$convo->id}/send", [
            'message' => 'สวัสดีแม่หมอ ขอดูดวงหน่อย',
        ]);

        $r->assertOk();
        $this->assertSame(100.0, app(WalletService::class)->balance($u), 'degraded reply must be free');
    }

    /** Auth handoff mints a short-lived code (so the bearer stays out of the OAuth URL). */
    public function test_auth_handoff_mints_a_short_lived_code(): void
    {
        $u = User::factory()->create();
        Sanctum::actingAs($u);

        $r = $this->postJson('/api/v1/auth/handoff');

        $r->assertOk();
        $this->assertNotEmpty($r->json('data.code'));
        $this->assertSame(120, $r->json('data.expires_in'));
    }

    /** topupShow must include the promptpay/QR block so the slip re-upload sheet renders. */
    public function test_topup_show_includes_promptpay_block(): void
    {
        config(['pricing.promptpay_id' => '0812345678']);
        $u = User::factory()->create();
        $tx = app(WalletService::class)->recordPendingTopup($u, 137.0, null, 'promptpay');
        Sanctum::actingAs($u);

        $r = $this->getJson("/api/v1/wallet/topup/{$tx->id}");

        $r->assertOk()->assertJsonPath('data.promptpay.id', '0812345678');
        $this->assertNotEmpty($r->json('data.promptpay.qr_payload'));
        $this->assertEquals(137.0, $r->json('data.payable_amount'));
    }

    /** Mobile numerology create — charges once + returns a reading. */
    public function test_mobile_numerology_creates_reading_and_charges(): void
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, 100, 'seed');
        Sanctum::actingAs($u);
        $cost = Pricing::for('numerology');

        $r = $this->postJson('/api/v1/fortune/numerology', [
            'name' => 'Somchai', 'birth_date' => '1990-05-15',
        ]);

        $r->assertCreated()->assertJsonPath('data.type', 'numerology');
        $this->assertNotEmpty($r->json('data.result'));
        $this->assertNotNull($r->json('data.id'));
        $this->assertSame(100.0 - $cost, app(WalletService::class)->balance($u));
    }

    /** A date window with no auspicious day must not charge. */
    public function test_mobile_auspicious_empty_window_does_not_charge(): void
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, 100, 'seed');
        Sanctum::actingAs($u);

        // 2025-01-06 (Mon, day 6, digit-sum 16) scores 5 (<7) → no candidates.
        $r = $this->postJson('/api/v1/fortune/auspicious', [
            'occasion' => 'แต่งงาน', 'from_date' => '2025-01-06', 'to_date' => '2025-01-06',
        ]);

        $r->assertStatus(422)->assertJsonPath('reason_code', 'no_auspicious_day');
        $this->assertSame(100.0, app(WalletService::class)->balance($u));
    }

    /** Daily horoscope is free + returns a reading for a seeded sign. */
    public function test_mobile_horoscope_returns_daily_reading(): void
    {
        Zodiac::create([
            'slug' => 'aries', 'name_en' => 'Aries', 'name_th' => 'เมษ', 'glyph' => '♈',
            'element' => 'Fire', 'ruler' => 'Mars', 'date_range' => '13 เม.ย. - 13 พ.ค.',
            'order_index' => 1, 'traits_th' => 'กล้าหาญ มุ่งมั่น',
        ]);

        $r = $this->getJson('/api/v1/horoscope/aries');

        $r->assertOk()->assertJsonPath('data.zodiac.slug', 'aries');
        $this->assertNotEmpty($r->json('data.summary'));
    }
}
