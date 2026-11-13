<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReadingParityAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_reading_and_free_routes_require_bearer_before_work(): void
    {
        Http::fake();
        foreach (['/api/v1/tarot/free', '/api/v1/history/readings/1/voice.mp3', '/api/v1/history/readings/1/status'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach (['/api/v1/tarot/free', '/api/v1/history/readings/1/share', '/api/v1/history/readings/1/review', '/api/v1/history/readings/1/voice/next', '/api/v1/deep'] as $url) {
            $this->postJson($url, [])->assertUnauthorized();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('readings', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
