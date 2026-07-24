<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test the Filament admin backend: member management, billing dashboard
 * widgets, and the readings viewer all render for an admin (and are gated from
 * members). A broken widget query or resource column would surface as a 500.
 */
class AdminBackendTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function seedMemberWithActivity(): User
    {
        $member = User::factory()->create(['role' => 'member', 'signup_via' => 'email']);
        $wallet = Wallet::factory()->balance(120)->create(['user_id' => $member->id]);

        // A successful top-up (revenue) + a pending one (queue) + a debit.
        WalletTransaction::factory()->create([
            'user_id' => $member->id, 'wallet_id' => $wallet->id,
            'type' => 'topup', 'status' => 'success', 'amount' => '150.00', 'method' => 'promptpay',
        ]);
        WalletTransaction::factory()->pendingTopup(100)->create([
            'user_id' => $member->id, 'wallet_id' => $wallet->id,
        ]);
        WalletTransaction::factory()->create([
            'user_id' => $member->id, 'wallet_id' => $wallet->id,
        ]);

        Reading::create([
            'user_id' => $member->id, 'type' => 'tarot',
            'session_token' => \Illuminate\Support\Str::random(32),
            'question' => 'จะรวยไหม', 'result' => 'ดวงการเงินรุ่งเรือง',
            'ai_provider' => 'gemini',
        ]);

        return $member;
    }

    public function test_admin_dashboard_with_billing_widgets_renders(): void
    {
        $this->seedMemberWithActivity();

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk();
    }

    public function test_member_list_and_detail_render_for_admin(): void
    {
        $member = $this->seedMemberWithActivity();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/users')->assertOk();
        // Detail page exercises the wallet-balance + recent-transactions infolist.
        $this->actingAs($admin)->get('/admin/users/' . $member->id)->assertOk();
    }

    public function test_readings_viewer_renders_for_admin(): void
    {
        $member = $this->seedMemberWithActivity();
        $reading = $member->readings()->first();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/readings')->assertOk();
        $this->actingAs($admin)->get('/admin/readings/' . $reading->id)->assertOk();
    }

    public function test_member_role_cannot_access_admin_panel(): void
    {
        $member = User::factory()->create(['role' => 'member']);

        $this->actingAs($member)->get('/admin')->assertForbidden();
    }
}
