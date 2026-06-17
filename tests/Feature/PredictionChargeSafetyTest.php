<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guards the rule that a prediction must never debit the wallet for an answer
 * it cannot produce. The classic break: palmistry needs a vision model with
 * no heuristic fallback, so on an install without a Gemini key it used to
 * charge ฿29 and return a "not ready" string with no refund.
 */
class PredictionChargeSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithBalance(float $amount): User
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, $amount, 'seed');
        return $u;
    }

    private function balance(User $u): float
    {
        return app(WalletService::class)->balance($u);
    }

    /** No Gemini key (the default) → palmistry refuses BEFORE charging. */
    public function test_palmistry_does_not_charge_when_ai_unconfigured(): void
    {
        Storage::fake('public');
        $u = $this->memberWithBalance(100);

        // ai_api_key has no Setting row in the fresh test DB → isConfigured() === false.
        $resp = $this->actingAs($u)->post('/palmistry/analyze', [
            'image' => UploadedFile::fake()->image('palm.jpg'),
        ]);

        $resp->assertRedirect(route('palmistry.index'));
        $this->assertSame(100.0, $this->balance($u), 'wallet must be untouched when AI is unavailable');
        $this->assertSame(0, Reading::where('type', 'palmistry')->count(), 'no reading row created');
    }

    /** Numerology is pure local math — it always returns a real reading and charges exactly once. */
    public function test_numerology_charges_once_and_returns_reading(): void
    {
        $u = $this->memberWithBalance(100);
        $cost = Pricing::for('numerology');

        $resp = $this->actingAs($u)->post('/numerology/calculate', [
            'name'       => 'Somchai',
            'birth_date' => '1990-05-15',
        ]);

        $resp->assertOk();
        $this->assertSame(100.0 - $cost, $this->balance($u), 'charged exactly the numerology price');

        $reading = Reading::where('type', 'numerology')->where('user_id', $u->id)->first();
        $this->assertNotNull($reading);
        $this->assertNotEmpty($reading->result, 'numerology must produce a non-empty narrative');

        // The narrative contains **bold** markdown — the result page must render
        // it (via <x-reading-prose>), not show raw ** markers.
        $resp->assertSee('<strong>', false);
        $resp->assertDontSee('**คุณ', false);
    }

    /** A date window with no auspicious day must not debit the wallet. */
    public function test_auspicious_does_not_charge_when_no_dates_in_range(): void
    {
        $u = $this->memberWithBalance(100);

        // 2025-01-06 is a Monday, day 6, digit-sum 16 (not %9) → score 5 (< 7),
        // so a single-day window yields zero candidates.
        $resp = $this->actingAs($u)->post('/auspicious/find', [
            'occasion'  => 'แต่งงาน',
            'from_date' => '2025-01-06',
            'to_date'   => '2025-01-06',
        ]);

        $resp->assertRedirect(route('auspicious.index'));
        $this->assertSame(100.0, $this->balance($u), 'no charge when the window has no auspicious day');
        $this->assertSame(0, Reading::where('type', 'auspicious')->count());
    }

    /** Chat must not charge when the only reply is the degraded "not ready" placeholder. */
    public function test_chat_does_not_charge_for_degraded_placeholder(): void
    {
        // Upstream Thaiprompt unreachable + no local Gemini key → degraded reply.
        Http::fake(['*' => Http::response('', 500)]);

        $u = User::factory()->create([
            'facebook_user_id' => 'fb-test-1',   // passes the FB/LINE gate
            'thaiprompt_token' => 'tok-test-1',  // makes bot "available" so it tries upstream
        ]);
        app(WalletService::class)->credit($u, 100, 'seed');

        $token = (string) Str::uuid();
        ChatConversation::create(['session_token' => $token, 'user_id' => $u->id, 'title' => 'test']);

        $resp = $this->actingAs($u)
            ->withSession(['chat_token' => $token])
            ->postJson('/chat/send', ['message' => 'สวัสดีแม่หมอ ขอดูดวงหน่อย']);

        $resp->assertOk();
        $this->assertSame(100.0, $this->balance($u), 'degraded placeholder reply must be free');
    }
}
