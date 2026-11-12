<?php

namespace Tests\Feature\Api;

use App\Models\ChatConversation;
use App\Models\FreeReadingClaim;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FreeTarotApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->user = User::factory()->create(['thaiprompt_token' => 'test-token']);
        Sanctum::actingAs($this->user);
        Http::fake(['*/tarot/free' => Http::response(['data' => [
            'interpretation' => 'คำทำนายฟรีจริง', 'next_questions' => ['งาน', 'รัก', 'เงิน'],
            'ai_provider' => 'test', 'ai_model' => 'test',
        ]])]);
        TarotCard::create(['slug' => 'sun', 'name_en' => 'Sun', 'name_th' => 'อาทิตย์', 'arcana' => 'major', 'suit' => 'major', 'upright_meaning_th' => 'สำเร็จ', 'reversed_meaning_th' => 'ล่าช้า', 'number' => 19, 'active' => true]);
    }

    private function cast(array $data = [], string $key = 'free-test')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/tarot/free', $data);
    }

    public function test_free_cast_draws_on_server_opens_same_room_and_forwards_question(): void
    {
        $this->getJson('/api/v1/tarot/free')->assertExactJson(['data' => ['available' => true, 'reason' => null, 'message' => null, 'max_chars' => 500]]);
        $response = $this->cast(['question' => 'งานเป็นอย่างไร', 'slug' => 'forged'])->assertCreated()
            ->assertJsonPath('data.reading.question', 'งานเป็นอย่างไร')->assertJsonPath('data.reading.cards.0.slug', 'sun');
        Http::assertSent(fn ($r) => ($r['question'] ?? null) === 'งานเป็นอย่างไร' && $r['max_chars'] === 500);
        $this->cast(['question' => 'งานเป็นอย่างไร'])->assertOk()->assertJsonPath('data.conversation_id', $response->json('data.conversation_id'));
        $this->getJson('/api/v1/tarot/free')->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'claimed');
        $this->assertDatabaseCount('readings', 1);
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $room = ChatConversation::sole();
        $this->assertSame((int) $response->json('data.reading.id'), $room->free_reading_id);
        $this->assertNull($room->reading_id);
    }

    public function test_ai_failure_releases_right_keeps_cap_accounting_and_cleans_reading(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $this->cast()->assertStatus(503)->assertJsonPath('reason_code', 'reading_failed');
        $this->assertDatabaseCount('readings', 0);
        $this->assertNotNull(FreeReadingClaim::sole()->failed_at);
        $this->getJson('/api/v1/tarot/free')->assertJsonPath('data.available', true);
        Setting::put('free_reading_daily_cap', '1', 'tarot');
        $this->getJson('/api/v1/tarot/free')->assertJsonPath('data.reason', 'daily_cap');
    }

    public function test_disabled_no_token_and_in_flight_are_honest_reasons(): void
    {
        Setting::put('free_reading_enabled', '0', 'tarot');
        $this->getJson('/api/v1/tarot/free')->assertJsonPath('data.reason', 'disabled');
        $this->cast()->assertConflict()->assertJsonPath('reason_code', 'disabled');
        Setting::put('free_reading_enabled', '1', 'tarot');
        $this->user->update(['thaiprompt_token' => null]);
        Sanctum::actingAs($this->user->fresh());
        $this->getJson('/api/v1/tarot/free')->assertJsonPath('data.reason', 'no_token');
        $this->user->update(['thaiprompt_token' => 'test-token']);
        Sanctum::actingAs($this->user->fresh());
        FreeReadingClaim::create(['user_id' => $this->user->id, 'batch' => 'v1']);
        $this->getJson('/api/v1/tarot/free')->assertJsonPath('data.reason', 'in_flight');
        $this->cast()->assertConflict()->assertJsonPath('reason_code', 'in_flight');
        Http::assertNothingSent();
    }

    public function test_persistence_failure_restores_free_entitlement_without_orphan_rows(): void
    {
        Reading::creating(fn () => throw new \RuntimeException('db failed'));
        try {
            $this->cast()->assertStatus(503)->assertJsonPath('reason_code', 'reading_failed');
        } finally {
            Reading::flushEventListeners();
        }
        $this->assertDatabaseCount('readings', 0);
        $this->assertDatabaseCount('tarot_reading_cards', 0);
        $this->assertNotNull(FreeReadingClaim::sole()->failed_at);
        $this->getJson('/api/v1/tarot/free')->assertJsonPath('data.available', true);
        Http::assertNothingSent();
    }

    public function test_validation_and_auth_precede_claim_or_ai(): void
    {
        $this->postJson('/api/v1/tarot/free', [])->assertUnprocessable()->assertJsonPath('reason_code', 'validation_failed');
        $this->cast(['question' => str_repeat('ก', 501)])->assertUnprocessable()->assertJsonPath('reason_code', 'validation_failed');
        $this->assertDatabaseCount('free_reading_claims', 0);
        Http::assertNothingSent();
    }

    public function test_per_user_lock_prevents_a_second_cast_without_consuming_quota(): void
    {
        $lock = Cache::lock('free_reading:'.$this->user->id, 60);
        $lock->get();
        try {
            $this->cast()->assertConflict()->assertJsonPath('reason_code', 'in_flight');
        } finally {
            $lock->release();
        }
        $this->assertDatabaseCount('free_reading_claims', 0);
        Http::assertNothingSent();
    }

    public function test_new_batch_does_not_reuse_an_old_key_for_a_second_ai_call(): void
    {
        $first = $this->cast()->assertCreated();
        Setting::put('free_reading_batch', 'v2', 'tarot');
        $this->cast()->assertOk()->assertJsonPath('data.reading.id', $first->json('data.reading.id'));
        $this->assertDatabaseCount('readings', 1);
        $this->assertEquals(0, app(WalletService::class)->balance($this->user));
    }
}
