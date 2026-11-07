<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiOracle;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🧠 (2026-11-07 · แพ็ก E) แชทเว็บใช้สมองชุดเดียวกับแชทเพจ FB ที่ Thaiprompt
 *
 *   - วันเกิด/เวลาเกิดในโปรไฟล์ไปกับทุกข้อความ → แม่หมอเห็นผังดวงจริงชุดเดียวกับบิลของเพจ
 *   - Thaiprompt ไม่ตอบแล้วทางสำรอง (AiOracle ของเว็บเอง) ตอบแทน = ไม่ผ่านด่านของสมองบอท → ไม่คิดเงิน
 */
class ChatBrainTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    private bool $upstreamDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '1', 'pricing', false);
        Setting::put('chat_daily_limit', '0', 'chat', false);
        Cache::flush();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => $this->upstreamDown
                    ? Http::response(['message' => 'down'], 503)
                    : Http::response(['data' => ['session_id' => 'sess-1', 'reply' => 'แม่หมอรับฟังอยู่ค่ะลูก', 'kind' => 'reply']]),
                default => Http::response([], 503),
            };
        });
    }

    private function member(array $profile = []): User
    {
        $user = User::factory()->create(['thaiprompt_token' => null]);
        if ($profile !== []) {
            Profile::create(['user_id' => $user->id] + $profile);
        }
        app(WalletService::class)->credit($user, 10, 'เติมเงิน');

        return $user->fresh();
    }

    /** @return array<string,mixed> */
    private function lastSend(): array
    {
        $sent = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/server/chat/send'))->last();
        $this->assertNotNull($sent, 'ต้องส่งข้อความไป Thaiprompt');

        return $sent[0]->data();
    }

    public function test_the_profile_birth_date_and_time_ride_along_with_every_message(): void
    {
        $user = $this->member(['birth_date' => '1990-11-07', 'birth_time' => '08:30:00']);

        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ช่วงนี้เหนื่อยกับงานค่ะ'])->assertOk();

        $data = $this->lastSend();
        $this->assertSame('1990-11-07', $data['birth_date']);
        $this->assertSame('08:30', $data['birth_time']);
    }

    public function test_no_birth_date_sends_no_birth_fields(): void
    {
        $user = $this->member();

        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ช่วงนี้เหนื่อยกับงานค่ะ'])->assertOk();

        $data = $this->lastSend();
        $this->assertArrayNotHasKey('birth_date', $data);
        $this->assertArrayNotHasKey('birth_time', $data);
    }

    public function test_a_fallback_answer_that_skipped_the_bot_brain_is_free(): void
    {
        $this->upstreamDown = true;
        $oracle = $this->createMock(AiOracle::class);
        $oracle->method('isConfigured')->willReturn(true);
        $oracle->method('chat')->willReturn('แม่หมอรับฟังอยู่นะคะลูก (ทางสำรอง)');
        $this->app->instance(AiOracle::class, $oracle);
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'ช่วงนี้เหนื่อยกับงานค่ะ'])->assertOk();

        $this->assertStringContainsString('ทางสำรอง', (string) $res->json('reply'));
        $this->assertSame(10.0, (float) app(WalletService::class)->balance($user), 'คำตอบที่ไม่ผ่านด่านของสมองบอทห้ามคิดเงิน');
    }
}
