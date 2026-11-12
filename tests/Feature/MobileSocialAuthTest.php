<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Affiliate\MaeMorAffiliate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobileSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $verifier;

    private array $providers = [['id' => 'facebook', 'name' => 'Facebook']];

    private array $profile = ['id' => '9001', 'email' => 'social@example.com', 'name' => 'Social member'];

    private ?array $proof = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 11, 12, 12, 0, 0));
        $this->verifier = str_repeat('a', 64);
        config(['app.url' => 'https://juntra.test']);
        Setting::put('thaiprompt_enabled', '1');
        Setting::put('thaiprompt_base_url', 'https://tp.test');
        Setting::put('thaiprompt_client_id', 'test-client');
        Setting::put('thaiprompt_client_secret', 'test-secret');
        $this->mock(MaeMorAffiliate::class, fn ($mock) => $mock->shouldReceive('ensureMember')
            ->andReturn(['status' => 'unavailable']));
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/api/oauth/social/providers')) {
                return Http::response(['data' => ['flow' => 'juntra-social-v1', 'providers' => $this->providers]]);
            }
            if (str_ends_with($request->url(), '/oauth/token')) {
                return Http::response([
                    'access_token' => 'test-access', 'expires_in' => 3600,
                ] + ($this->proof ?? [
                    'social_provider' => $request['social_provider'], 'social_nonce' => $request['social_nonce'],
                ]));
            }
            if (str_ends_with($request->url(), '/api/user')) {
                return Http::response($this->profile);
            }

            return Http::response([], 503);
        });
    }

    private function challenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    private function start(array $extra = []): array
    {
        return $this->postJson('/api/v1/auth/social/start', $extra + [
            'provider' => 'facebook', 'code_challenge' => $this->challenge(), 'device' => 'mobile',
        ])->assertOk()->json('data');
    }

    private function begin(array $extra = []): array
    {
        $ticket = $this->start($extra);
        $this->get(parse_url($ticket['url'], PHP_URL_PATH))->assertRedirect();

        return $ticket;
    }

    private function completeSocialCallback(array $query = [])
    {
        return $this->get('/auth/thaiprompt/callback?'.http_build_query($query + [
            'code' => 'test-code', 'state' => session('mobile_social.state'),
        ]));
    }

    private function poll(array $ticket, ?string $verifier = null)
    {
        return $this->postJson('/api/v1/auth/social/poll', [
            'id' => $ticket['id'], 'code_verifier' => $verifier ?? $this->verifier,
        ]);
    }

    public function test_lists_only_enabled_bridge_providers(): void
    {
        $this->getJson('/api/v1/auth/social/providers')->assertExactJson(['data' => ['providers' => $this->providers]]);
        $this->providers[] = ['id' => 'google', 'name' => 'Google'];
        $this->providers[] = ['id' => 'line', 'name' => 'LINE'];
        Cache::flush();
        $this->getJson('/api/v1/auth/social/providers')->assertJsonCount(2, 'data.providers')
            ->assertJsonPath('data.providers.1.id', 'google');
    }

    public function test_disabled_sso_and_unavailable_bridge_fail_closed(): void
    {
        Setting::put('thaiprompt_enabled', '0');
        $this->getJson('/api/v1/auth/social/providers')->assertExactJson(['data' => ['providers' => []]]);
        Setting::put('thaiprompt_enabled', '1');
        Http::swap(new Factory(app('events')));
        Http::fake(['*' => Http::response([], 404)]);
        Cache::flush();
        $this->getJson('/api/v1/auth/social/providers')->assertExactJson(['data' => ['providers' => []]]);
    }

    public function test_unknown_and_disabled_providers_are_rejected(): void
    {
        foreach (['unknown', 'google'] as $provider) {
            $this->postJson('/api/v1/auth/social/start', [
                'provider' => $provider, 'code_challenge' => $this->challenge(), 'device' => 'mobile',
            ])->assertUnprocessable()->assertJsonValidationErrors('provider');
        }
        $this->assertDatabaseCount('social_login_requests', 0);
    }

    public function test_start_has_fixed_ttl_https_origin_and_no_token(): void
    {
        $ticket = $this->start();
        $this->assertSame(300, $ticket['expires_in']);
        $this->assertSame(2, $ticket['poll_interval']);
        $this->assertStringStartsWith('https://juntra.test/auth/social/', $ticket['url']);
        $this->assertNull(parse_url($ticket['url'], PHP_URL_QUERY));
        $this->assertDatabaseHas('social_login_requests', [
            'id' => hash('sha256', $ticket['id']), 'expires_at' => now()->addSeconds(300)->toDateTimeString(),
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unicode_and_ascii_app_origins_produce_the_same_punycode_url(): void
    {
        foreach (['https://จันทรา.online', 'https://xn--82c4af5bzdj.online/'] as $origin) {
            config(['app.url' => $origin]);
            $ticket = $this->start();
            $this->assertSame('xn--82c4af5bzdj.online', parse_url($ticket['url'], PHP_URL_HOST));
            $this->assertSame('https', parse_url($ticket['url'], PHP_URL_SCHEME));
            $this->assertNull(parse_url($ticket['url'], PHP_URL_PORT));
            $this->poll($ticket)->assertJsonPath('data.status', 'pending');
        }
    }

    public function test_staging_origin_port_is_preserved_and_request_host_is_ignored(): void
    {
        config(['app.url' => 'https://Staging.Example.com:8443/']);
        $this->withServerVariables(['HTTP_HOST' => 'attacker.example']);
        $ticket = $this->start();
        $this->assertStringStartsWith('https://staging.example.com:8443/auth/social/', $ticket['url']);
        $this->poll($ticket)->assertJsonPath('data.status', 'pending');
    }

    public function test_invalid_configured_origin_fails_closed_without_creating_tickets(): void
    {
        foreach (['http://juntra.test', 'https://user:pass@juntra.test', 'https://juntra.test/other', 'https://bad_host.test'] as $origin) {
            config(['app.url' => $origin]);
            $this->getJson('/api/v1/auth/social/providers')->assertExactJson(['data' => ['providers' => []]]);
            $this->postJson('/api/v1/auth/social/start', [
                'provider' => 'facebook', 'code_challenge' => $this->challenge(), 'device' => 'mobile',
            ])->assertServiceUnavailable();
        }
        $this->assertDatabaseCount('social_login_requests', 0);
    }

    public function test_pending_mismatch_and_expiry_do_not_consume_or_extend_ticket(): void
    {
        $ticket = $this->start();
        $this->poll($ticket, str_repeat('b', 64))->assertNotFound();
        $this->poll($ticket)->assertExactJson(['data' => ['status' => 'pending']]);
        $this->travel(299)->seconds();
        $this->poll($ticket)->assertJsonPath('data.status', 'pending');
        $this->travel(1)->seconds();
        $this->poll($ticket)->assertExactJson(['data' => ['status' => 'expired']]);
        $this->get(parse_url($ticket['url'], PHP_URL_PATH))->assertStatus(400);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_browser_clears_existing_session_and_uses_selected_provider(): void
    {
        $old = User::factory()->create();
        $this->actingAs($old)->withSession(['url.intended' => '/private', 'old_data' => 'secret']);
        $ticket = $this->begin();
        $this->assertGuest();
        $this->assertNull(session('old_data'));
        $this->assertNull(session('url.intended'));
        $this->assertSame('facebook', session('mobile_social.provider'));
        $this->get(parse_url($ticket['url'], PHP_URL_PATH))->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'pending');
    }

    public function test_browser_redirect_has_provider_nonce_and_original_callback_uri(): void
    {
        $ticket = $this->start();
        $response = $this->get(parse_url($ticket['url'], PHP_URL_PATH))->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertStringStartsWith('https://tp.test/oauth/social/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('facebook', $query['provider']);
        $this->assertSame(session('mobile_social.nonce'), $query['social_nonce']);
        $this->assertSame(route('thaiprompt.callback'), $query['redirect_uri']);
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_correct_user_binding_authorization_one_time_token_and_profile_shape(): void
    {
        $old = User::factory()->create(['thaiprompt_user_id' => '1']);
        $owner = User::factory()->create(['thaiprompt_user_id' => '9001', 'email' => 'owner@example.com']);
        $this->actingAs($old);
        $ticket = $this->begin();
        $this->completeSocialCallback()->assertOk()->assertSee('href="juntra://auth/complete"', false)
            ->assertDontSee($owner->email)->assertDontSee('test-access');
        $this->assertGuest();
        $this->assertSame('1', $old->refresh()->thaiprompt_user_id);
        $this->assertSame('owner@example.com', $owner->refresh()->email);
        $this->poll($ticket, str_repeat('b', 64))->assertNotFound();
        $response = $this->poll($ticket)->assertOk()->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.user.id', $owner->id)->assertJsonPath('data.user.thaiprompt_linked', true)
            ->assertJsonMissingPath('data.user.thaiprompt_token');
        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertSame($owner->id, $token->tokenable_id);
        $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertExactJson(['data' => $response->json('data.user')]);
        $this->poll($ticket)->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_missing_or_wrong_upstream_provider_proof_never_authorizes(): void
    {
        foreach ([[], ['social_provider' => 'google', 'social_nonce' => 'wrong']] as $proof) {
            $this->proof = $proof;
            $ticket = $this->begin();
            $this->completeSocialCallback()->assertStatus(400)->assertDontSee('test-access');
            $this->poll($ticket)->assertJsonPath('data.status', 'failed');
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_callback_state_mismatch_does_not_change_request(): void
    {
        $ticket = $this->begin();
        $this->completeSocialCallback(['state' => 'wrong'])->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'pending');
        $this->completeSocialCallback()->assertOk();
        $this->poll($ticket)->assertJsonPath('data.status', 'authorized');
    }

    public function test_malformed_callback_parameters_fail_safely(): void
    {
        $ticket = $this->begin();
        $this->completeSocialCallback(['state' => ['invalid']])->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'pending');
        $this->completeSocialCallback(['code' => ['invalid']])->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'failed');
        $ticket = $this->begin();
        $this->completeSocialCallback(['error' => ['invalid']])->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_callback_replay_cannot_replace_authorized_user(): void
    {
        $ticket = $this->begin();
        $context = session('mobile_social');
        $this->completeSocialCallback()->assertOk();
        $this->profile = ['id' => '9002', 'email' => 'other@example.com'];
        $this->withSession(['mobile_social' => $context]);
        $this->completeSocialCallback()->assertStatus(400);
        $this->assertDatabaseMissing('users', ['thaiprompt_user_id' => '9002']);
        $this->poll($ticket)->assertJsonPath('data.user.email', 'social@example.com');
    }

    public function test_changed_browser_account_is_rejected_without_linking(): void
    {
        $ticket = $this->begin();
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->completeSocialCallback()->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'failed');
        $this->assertNull($other->refresh()->thaiprompt_user_id);
    }

    public function test_browser_binding_cannot_be_replaced_by_another_session(): void
    {
        $ticket = $this->begin();
        $context = session('mobile_social');
        $this->flushSession();
        $this->get(parse_url($ticket['url'], PHP_URL_PATH))->assertStatus(400);
        $this->assertNull(session('mobile_social'));
        $this->withSession(['mobile_social' => $context]);
        $this->completeSocialCallback()->assertOk();
    }

    public function test_expired_callback_never_binds_a_user(): void
    {
        $ticket = $this->begin();
        $this->travel(300)->seconds();
        $this->completeSocialCallback()->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'expired');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_denial_is_cancelled_without_upstream_error_or_user_leaks(): void
    {
        $ticket = $this->begin();
        $this->completeSocialCallback(['error' => 'access_denied', 'error_description' => 'private upstream details'])
            ->assertStatus(400)->assertDontSee('private upstream details');
        $this->poll($ticket)->assertExactJson(['data' => ['status' => 'cancelled']]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_staff_and_conflicting_existing_identity_cannot_social_login(): void
    {
        $staff = User::factory()->create(['email' => 'social@example.com', 'role' => 'admin']);
        $ticket = $this->begin();
        $this->completeSocialCallback()->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'failed');
        $staff->update(['role' => 'member', 'thaiprompt_user_id' => '9999']);
        $ticket = $this->begin();
        $this->completeSocialCallback()->assertStatus(400);
        $this->assertSame('9999', $staff->refresh()->thaiprompt_user_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_referral_is_bound_to_ticket_and_browser_cookie_cannot_override_it(): void
    {
        $ticket = $this->begin(['referral_code' => 'APP_SPONSOR']);
        $this->withCookie('juntra_ref', 'BROWSER_SPONSOR');
        $this->completeSocialCallback()->assertOk();
        $user = User::where('thaiprompt_user_id', '9001')->firstOrFail();
        $this->assertSame('APP_SPONSOR', $user->pending_referral_code);
        $this->poll($ticket)->assertJsonPath('data.user.id', $user->id);
    }

    public function test_existing_referral_is_preserved(): void
    {
        $user = User::factory()->create([
            'thaiprompt_user_id' => '9001', 'pending_referral_code' => 'ORIGINAL',
        ]);
        $this->begin(['referral_code' => 'OTHER']);
        $this->completeSocialCallback()->assertOk();
        $this->assertSame('ORIGINAL', $user->refresh()->pending_referral_code);
    }

    public function test_concurrent_consume_loser_cannot_mint_another_token(): void
    {
        $ticket = $this->begin();
        $this->completeSocialCallback()->assertOk();
        $interleaved = false;
        DB::listen(function ($query) use ($ticket, &$interleaved) {
            if (! $interleaved && str_starts_with($query->sql, 'select')
                && str_contains($query->sql, 'social_login_requests')) {
                $interleaved = true;
                DB::table('social_login_requests')->where('id', hash('sha256', $ticket['id']))
                    ->update(['status' => 'consumed']);
            }
        });
        $this->poll($ticket)->assertNotFound();
        $this->assertTrue($interleaved);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_same_provider_with_wrong_nonce_is_rejected(): void
    {
        $ticket = $this->begin();
        $this->proof = ['social_provider' => 'facebook', 'social_nonce' => str_repeat('0', 64)];
        $this->completeSocialCallback()->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_deleted_account_is_not_recreated_or_issued_a_token(): void
    {
        $user = User::factory()->create(['email' => 'social@example.com', 'thaiprompt_user_id' => '9001']);
        $user->delete();
        $ticket = $this->begin();
        $this->completeSocialCallback()->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_upstream_identity_cannot_authorize_even_with_a_matching_email(): void
    {
        $this->profile = ['email' => 'social@example.com'];
        $ticket = $this->begin();
        $this->completeSocialCallback()->assertStatus(400);
        $this->poll($ticket)->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_pkce_and_referral_are_validation_errors(): void
    {
        foreach ([['code_challenge' => 'plain-verifier'], ['referral_code' => 'bad/ref']] as $extra) {
            $this->postJson('/api/v1/auth/social/start', $extra + [
                'provider' => 'facebook', 'code_challenge' => $this->challenge(), 'device' => 'mobile',
            ])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/social/poll', ['id' => 'invalid', 'code_verifier' => 'short'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('social_login_requests', 0);
    }

    public function test_reserved_email_never_uses_a_squatted_account(): void
    {
        $squatter = User::factory()->create(['email' => 'fb_123@thaiprompt.local']);
        $this->profile['email'] = $squatter->email;
        $ticket = $this->begin();
        $this->completeSocialCallback()->assertOk();
        $response = $this->poll($ticket)->assertJsonPath('data.status', 'authorized');
        $this->assertNotSame($squatter->id, $response->json('data.user.id'));
        $this->assertNull($squatter->refresh()->thaiprompt_user_id);
    }

    public function test_google_can_be_selected_when_upstream_enables_it(): void
    {
        $this->providers[] = ['id' => 'google', 'name' => 'Google'];
        $ticket = $this->begin(['provider' => 'google']);
        $this->assertSame('google', session('mobile_social.provider'));
        $this->completeSocialCallback()->assertOk();
        $this->poll($ticket)->assertJsonPath('data.status', 'authorized');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/oauth/token')
            && $request['social_provider'] === 'google');
    }

    public function test_start_and_poll_have_independent_rate_limits(): void
    {
        $ticket = $this->start();
        for ($i = 0; $i < 40; $i++) {
            $this->poll($ticket)->assertOk();
        }
        $this->poll($ticket)->assertTooManyRequests();
        for ($i = 0; $i < 5; $i++) {
            $this->start();
        }
        $this->postJson('/api/v1/auth/social/start', [
            'provider' => 'facebook', 'code_challenge' => $this->challenge(), 'device' => 'mobile',
        ])->assertTooManyRequests();
    }
}
