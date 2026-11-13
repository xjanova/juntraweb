<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Wallet\ChatSlipIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatSlipPingAuthTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'callback-test-token-with-at-least-32-characters';
    private const URL = '/api/server/chat-slips/ping';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
        config(['services.thaiprompt.chat_slip_ping_token' => self::TOKEN]);
    }

    public function test_untrusted_callers_never_reach_intake_validation_or_network(): void
    {
        $this->mock(ChatSlipIntake::class)->shouldNotReceive('process');
        $customerToken = User::factory()->create()->createToken('customer')->plainTextToken;
        foreach ([null, 'wrong', $customerToken] as $token) {
            $this->withHeaders(['Authorization' => $token ? 'Bearer '.$token : ''])->postJson(self::URL, [])
                ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.', 'reason_code' => 'unauthenticated']);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_missing_or_short_configuration_fails_closed_even_with_matching_bearer(): void
    {
        $this->mock(ChatSlipIntake::class)->shouldNotReceive('process');
        foreach (['', 'short'] as $token) {
            config(['services.thaiprompt.chat_slip_ping_token' => $token]);
            $this->withToken($token)->postJson(self::URL, ['trans_ref' => 'ABC123'])->assertUnauthorized();
        }
        Http::assertNothingSent();
    }

    public function test_anonymous_requests_do_not_consume_the_authenticated_throttle(): void
    {
        $this->mock(ChatSlipIntake::class)->shouldReceive('process')->once()->with('ABC123')
            ->andReturn(['outcome' => ChatSlipIntake::LATER]);
        for ($i = 0; $i < 35; $i++) {
            $this->postJson(self::URL, [])->assertUnauthorized();
        }
        $this->withToken(self::TOKEN)->postJson(self::URL, ['trans_ref' => 'ABC123'])
            ->assertStatus(202)->assertExactJson(['outcome' => 'later', 'topup_ref' => null, 'credited' => null]);
    }

    public function test_authorized_success_and_validation(): void
    {
        $this->mock(ChatSlipIntake::class)->shouldReceive('process')->once()->with('ABC123')
            ->andReturn(['outcome' => 'credited', 'topup_ref' => 'TUP-TEST', 'credited' => 39]);
        $this->withToken(self::TOKEN)->postJson(self::URL, ['trans_ref' => 'ABC123'])
            ->assertOk()->assertExactJson(['outcome' => 'credited', 'topup_ref' => 'TUP-TEST', 'credited' => 39]);
        $this->postJson(self::URL, ['trans_ref' => 'bad ref!'])->assertUnprocessable()->assertJsonValidationErrors('trans_ref');
    }
}
