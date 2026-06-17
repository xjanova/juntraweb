<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        // Accounts are SOFT-deleted so the wallet ledger (FK cascade) survives
        // account closure for bookkeeping retention — the row remains with a
        // deleted_at, and the soft-delete scope keeps the user out of auth.
        $this->assertSoftDeleted($user);
    }

    public function test_account_deletion_scrubs_pii_but_keeps_wallet_ledger(): void
    {
        $user = User::factory()->create([
            'name'               => 'Real Name',
            'email'              => 'real.person@example.com',
            'facebook_user_id'   => 'fb-123',
            'line_user_id'       => 'line-123',
            'thaiprompt_user_id' => 'tp-123',
            'thaiprompt_token'   => 'secret-bearer',
        ]);

        // A ledger entry that must survive the account closure.
        app(WalletService::class)->credit($user, 100, 'seed');
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->count());

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertSoftDeleted($user);

        // PDPA erasure: PII scrubbed (query with trashed since soft-deleted).
        $fresh = User::withTrashed()->find($user->id);
        $this->assertSame('ผู้ใช้ที่ลบบัญชี', $fresh->name);
        $this->assertSame('deleted+' . $user->id . '@deleted.local', $fresh->email);
        $this->assertNull($fresh->facebook_user_id);
        $this->assertNull($fresh->line_user_id);
        $this->assertNull($fresh->thaiprompt_user_id);
        $this->assertNull($fresh->thaiprompt_token);

        // Financial ledger preserved.
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->count());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
