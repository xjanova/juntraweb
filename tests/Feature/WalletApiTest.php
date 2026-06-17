<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_topup_promptpay_returns_qr_payload_and_svg(): void
    {
        config(['pricing.promptpay_id' => '0812345678']);
        $u = User::factory()->create();
        Sanctum::actingAs($u);

        $r = $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100]);

        $r->assertCreated()
            ->assertJsonPath('data.promptpay.id', '0812345678');
        $this->assertNotEmpty($r->json('data.promptpay.qr_payload'));
        $this->assertStringStartsWith('data:image/svg+xml', (string) $r->json('data.promptpay.qr_svg'));
    }

    public function test_pending_cap_returns_409(): void
    {
        config(['pricing.max_pending_topups' => 1]);
        $u = $this->makeUserWithPending(1);
        Sanctum::actingAs($u);

        $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'too_many_pending');
    }

    public function test_list_and_cancel_pending_topup(): void
    {
        $u = User::factory()->create();
        $tx = app(WalletService::class)->recordPendingTopup($u, 100, null, 'promptpay');
        Sanctum::actingAs($u);

        $this->getJson('/api/v1/wallet/topups')
            ->assertOk()
            ->assertJsonPath('meta.pending_count', 1);

        $this->deleteJson("/api/v1/wallet/topup/{$tx->id}")
            ->assertOk();

        $this->assertSame('cancelled', $tx->fresh()->status);
    }

    public function test_cannot_cancel_another_users_topup(): void
    {
        $owner = User::factory()->create();
        $tx = app(WalletService::class)->recordPendingTopup($owner, 100, null, 'promptpay');
        $attacker = User::factory()->create();
        Sanctum::actingAs($attacker);

        // Scoped to the requester's own rows → 404 (not found), never cancelled.
        $this->deleteJson("/api/v1/wallet/topup/{$tx->id}")->assertNotFound();
        $this->assertSame('pending', $tx->fresh()->status);
    }

    private function makeUserWithPending(int $n): User
    {
        $u = User::factory()->create();
        $svc = app(WalletService::class);
        for ($i = 0; $i < $n; $i++) {
            $svc->recordPendingTopup($u, 100, null, 'promptpay');
        }
        return $u;
    }
}
