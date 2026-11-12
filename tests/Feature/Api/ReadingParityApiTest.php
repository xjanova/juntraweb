<?php

namespace Tests\Feature\Api;

use App\Jobs\InterpretTarotReading;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FortuneBot\FortuneAiService;
use App\Services\Tarot\TarotReadingFinisher;
use App\Services\Voice\ReadingVoiceBuilder;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadingParityApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private array $voiceFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
        config(['app.url' => 'https://juntra.test']);
        Setting::put('pricing_tarot_single', '9', 'pricing');
        Setting::put('pricing_voice_premium', '100', 'pricing');
        Cache::put('voice-premium:ready', true, 60);
        $this->user = User::factory()->create();
        app(WalletService::class)->credit($this->user, 500, 'seed');
        Sanctum::actingAs($this->user);
        TarotCard::create(['slug' => 'sun', 'name_en' => 'Sun', 'name_th' => 'อาทิตย์', 'arcana' => 'major', 'suit' => 'major', 'upright_meaning_th' => 'สำเร็จ', 'reversed_meaning_th' => 'ล่าช้า', 'number' => 19, 'active' => true]);
        $this->mock(FortuneAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailableFor')->andReturn(true);
            $mock->shouldReceive('interpretTarot')->andReturn(['text' => 'คำทำนายจริง', 'provider' => 'test', 'model' => 'test', 'source' => 'thaiprompt']);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->voiceFiles as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function buy(array $extra = [], string $key = 'purchase')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/history/readings', $extra + [
            'type' => 'tarot_single', 'voice_premium' => true, 'picks' => [['slug' => 'sun']],
        ]);
    }

    private function reading(array $attributes = []): Reading
    {
        return Reading::create($attributes + [
            'user_id' => $this->user->id, 'session_token' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'tarot_single', 'result' => 'คำทำนายเสร็จแล้ว',
            'payload' => ['premium' => ['status' => 'paid', 'price' => 100]],
        ]);
    }

    public function test_premium_purchase_has_two_debits_and_replays_without_charging(): void
    {
        $first = $this->buy()->assertCreated()->assertJsonPath('data.premium', ['status' => 'paid', 'price' => 100]);
        $this->buy()->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(2, WalletTransaction::where('type', 'debit')->count());
        $premium = WalletTransaction::where('reference_type', 'voice_premium')->sole();
        $this->assertStringStartsWith('voicepass:', $premium->idempotency_key);
        $this->assertEquals(391, app(WalletService::class)->balance($this->user));
    }

    public function test_unavailable_premium_and_insufficient_total_never_charge(): void
    {
        config(['reading_voice.enabled' => false]);
        $this->buy()->assertConflict()->assertJsonPath('reason_code', 'voice_premium_unavailable');
        config(['reading_voice.enabled' => true]);
        app(WalletService::class)->debit($this->user, 450, 'spent elsewhere');
        $this->buy()->assertStatus(402)->assertJsonPath('cost', 109);
        $this->assertSame(1, WalletTransaction::where('type', 'debit')->count());
    }

    public function test_ai_failure_refunds_both_debits_and_deletes_empty_reading(): void
    {
        $this->mock(FortuneAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailableFor')->andReturn(true);
            $mock->shouldReceive('interpretTarot')->andThrow(new \RuntimeException('ai failed'));
        });
        $this->buy()->assertStatus(503)->assertJsonPath('reason_code', 'reading_failed');
        $this->assertEquals(500, app(WalletService::class)->balance($this->user));
        $this->assertSame(2, WalletTransaction::where('type', 'debit')->where('status', 'refunded')->count());
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_database_failure_refunds_both_debits(): void
    {
        Reading::creating(fn () => throw new \RuntimeException('database failed'));
        try {
            $this->buy()->assertStatus(503);
        } finally {
            Reading::flushEventListeners();
        }
        $this->assertEquals(500, app(WalletService::class)->balance($this->user));
        $this->assertSame(2, WalletTransaction::where('type', 'debit')->where('status', 'refunded')->count());
        $this->buy()->assertStatus(503)->assertJsonPath('reason_code', 'reading_failed');
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_empty_ai_product_refunds_both_instead_of_charging_for_nothing(): void
    {
        $this->mock(FortuneAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailableFor')->andReturn(true);
            $mock->shouldReceive('interpretTarot')->andReturn(['text' => '', 'provider' => 'test', 'model' => 'test', 'source' => 'thaiprompt']);
        });
        $this->buy()->assertStatus(503);
        $this->assertEquals(500, app(WalletService::class)->balance($this->user));
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_zero_price_tarot_still_replays_premium_or_free_purchase(): void
    {
        Setting::put('pricing_tarot_single', '0', 'pricing');
        $first = $this->buy()->assertCreated()->assertJsonPath('cost', 0);
        $this->buy()->assertCreated()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('cost', 0);
        $this->assertSame(1, WalletTransaction::where('type', 'debit')->count());
        $free = $this->buy(['voice_premium' => false], 'no-charge')->assertCreated();
        $this->buy(['voice_premium' => false], 'no-charge')->assertCreated()->assertJsonPath('data.id', $free->json('data.id'));
        $this->assertDatabaseCount('readings', 2);
    }

    public function test_async_failure_uses_existing_finisher_to_refund_both(): void
    {
        Bus::fake();
        $res = $this->buy(['mode' => 'async'])->assertStatus(202)->assertJsonPath('data.status', 'pending');
        Bus::assertDispatchedAfterResponse(InterpretTarotReading::class);
        $reading = Reading::findOrFail($res->json('data.id'));
        app(TarotReadingFinisher::class)->fail($reading, 'failed', [Reading::STATUS_PENDING]);
        $this->assertEquals(500, app(WalletService::class)->balance($this->user));
        $this->assertSame('refunded', data_get($reading->fresh()->payload, 'premium.status'));
    }

    public function test_detail_share_review_and_edit_reset_approval(): void
    {
        $r = $this->reading();
        $url = '/api/v1/history/readings/'.$r->id;
        $this->getJson($url)->assertOk()->assertJsonStructure(['data' => ['persona', 'room' => ['key', 'name', 'short', 'image_url'], 'reader_name', 'premium', 'review', 'share', 'qa_minutes']]);
        $this->postJson($url.'/share', ['public' => true])->assertOk()->assertJsonPath('data.public', true);
        $this->postJson($url.'/share', ['public' => false])->assertOk()->assertExactJson(['data' => ['public' => false, 'url' => null]]);
        $this->postJson($url.'/review', ['rating' => 5, 'message' => '<b>ดีค่ะ</b>', 'allow_public' => true])
            ->assertOk()->assertExactJson(['data' => ['review' => ['rating' => 5, 'message' => 'ดีค่ะ', 'allow_public' => true]]]);
        $r->review()->update(['approved' => true]);
        $this->postJson($url.'/review', ['rating' => 4, 'message' => 'แก้ไข'])->assertOk();
        $this->assertFalse((bool) $r->review()->first()->approved);
        $this->postJson($url.'/review', ['rating' => 6])->assertUnprocessable()->assertJsonPath('reason_code', 'validation_failed');
        $this->postJson($url.'/share', ['public' => 'bad'])->assertUnprocessable()->assertJsonPath('reason_code', 'validation_failed');
    }

    public function test_engagement_is_only_for_owners_of_finished_tarot(): void
    {
        foreach ([['user_id' => User::factory()->create()->id], ['status' => 'pending'], ['status' => 'failed'], ['type' => 'deep'], ['result' => '']] as $attributes) {
            $r = $this->reading($attributes);
            $expected = isset($attributes['user_id']) ? 403 : (isset($attributes['type']) ? 404 : 422);
            foreach (['share' => ['public' => true], 'review' => ['rating' => 5]] as $action => $body) {
                $this->postJson('/api/v1/history/readings/'.$r->id.'/'.$action, $body)->assertStatus($expected);
            }
        }
    }

    public function test_voice_uses_canonical_owner_url_and_binary_file_never_payload_url(): void
    {
        $r = $this->reading(['payload' => ['premium' => ['status' => 'paid', 'price' => 100], 'voice' => [
            'status' => 'ready', 'duration' => 3, 'chapters' => [], 'url' => 'https://attacker.test/999.mp3',
        ]]]);
        $dir = ReadingVoiceBuilder::dir($r);
        File::ensureDirectoryExists($dir);
        File::copy(base_path('tests/fixtures/voice-3s.mp3'), ReadingVoiceBuilder::file($r));
        $this->voiceFiles[] = $dir;
        $this->mock(ReadingVoiceBuilder::class)->shouldReceive('step')->twice()->andReturn(
            ['status' => 'ready', 'done' => 1, 'total' => 1, 'refunded' => false],
            ['status' => 'none', 'done' => 0, 'total' => 0, 'refunded' => true],
        );
        $url = '/api/v1/history/readings/'.$r->id;
        $this->postJson($url.'/voice/next')->assertOk()->assertJsonPath('voice.url', 'https://juntra.test'.$url.'/voice.mp3');
        $this->getJson($url)->assertJsonPath('data.payload.voice.url', 'https://juntra.test'.$url.'/voice.mp3');
        $this->get($url.'/voice.mp3?url=https://attacker.test/other')->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        Http::assertNothingSent();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($url.'/voice.mp3')->assertForbidden();
        $this->postJson($url.'/voice/next')->assertForbidden();
        Sanctum::actingAs($this->user);
        $r->update(['payload' => ['premium' => ['status' => 'refunded', 'price' => 100]]]);
        $this->getJson($url.'/voice.mp3')->assertForbidden();
        $this->postJson($url.'/voice/next')->assertOk()
            ->assertJsonPath('refunded', true)->assertJsonPath('voice', null);
        $r->update(['payload' => []]);
        $this->postJson($url.'/voice/next')->assertForbidden();
    }

    public function test_voice_wait_missing_file_and_no_premium(): void
    {
        $r = $this->reading(['status' => 'pending']);
        $url = '/api/v1/history/readings/'.$r->id;
        $this->postJson($url.'/voice/next')->assertConflict()->assertJsonPath('wait', true);
        $this->getJson($url.'/voice.mp3')->assertNotFound();
        $r->update(['payload' => []]);
        $this->getJson($url.'/voice.mp3')->assertForbidden();
    }
}
