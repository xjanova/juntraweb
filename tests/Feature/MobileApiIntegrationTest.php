<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Wallet\WalletService;
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
}
