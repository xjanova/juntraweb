<?php

namespace Tests\Feature\Api;

use App\Jobs\InterpretDeepReading;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Deep\DeepReadingFinisher;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeepAsyncApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
        Bus::fake();
        Setting::put('pricing_deep', '39', 'pricing');
        $this->user = User::factory()->create();
        app(WalletService::class)->credit($this->user, 100, 'seed');
        Sanctum::actingAs($this->user);
    }

    public function test_async_returns_pending_replays_same_id_and_has_private_no_store_status(): void
    {
        $data = ['mode' => 'async', 'questions' => ['งานดีไหม']];
        $r = $this->withHeader('Idempotency-Key', 'deep-one')->postJson('/api/v1/deep', $data)
            ->assertStatus(202)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.reading', null);
        Bus::assertDispatchedAfterResponse(InterpretDeepReading::class);
        $this->postJson('/api/v1/deep', $data)->assertStatus(202)->assertJsonPath('data.id', $r->json('data.id'));
        $this->assertSame(1, WalletTransaction::where('type', 'debit')->count());
        $url = '/api/v1/history/readings/'.$r->json('data.id').'/status';
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'pending')->assertHeader('Cache-Control', 'no-store, private');
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($url)->assertForbidden();
    }

    public function test_async_uses_web_finisher_and_refunds_when_question_fails(): void
    {
        $response = $this->withHeader('Idempotency-Key', 'failed')->postJson('/api/v1/deep', ['mode' => 'async', 'questions' => ['งานดีไหม']])->assertStatus(202);
        $this->mock(FortuneBotClient::class)->shouldReceive('deepQuestion')->once()->andReturn(['status' => 'unavailable', 'reading' => '', 'whole' => false]);
        app(DeepReadingFinisher::class)->finish($response->json('data.id'));
        $this->assertSame('failed', Reading::sole()->status);
        $this->assertEquals(100, app(WalletService::class)->balance($this->user));
    }

    public function test_async_database_failure_refunds_and_validation_limits_match_web(): void
    {
        $this->getJson('/api/v1/deep')->assertJsonPath('data.max_questions', 3);
        $this->postJson('/api/v1/deep', ['mode' => 'async', 'questions' => ['1', '2', '3', '4']])->assertUnprocessable()->assertJsonPath('reason_code', 'validation_failed');
        Reading::creating(fn () => throw new \RuntimeException('db failed'));
        try {
            $this->withHeader('Idempotency-Key', 'persist-failed')->postJson('/api/v1/deep', ['mode' => 'async', 'questions' => ['งาน']])->assertStatus(503);
        } finally {
            Reading::flushEventListeners();
        }
        $this->assertEquals(100, app(WalletService::class)->balance($this->user));
        $this->postJson('/api/v1/deep', ['mode' => 'async', 'questions' => ['งาน']])->assertStatus(503)->assertJsonPath('reason_code', 'reading_failed');
        $this->assertDatabaseCount('readings', 0);
        Bus::assertNotDispatched(InterpretDeepReading::class);
    }
}
