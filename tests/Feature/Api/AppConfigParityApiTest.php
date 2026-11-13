<?php

namespace Tests\Feature\Api;

use App\Support\ChatRooms;
use App\Support\VoicePremium;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 📱 (2026-11-12) สัญญา API ข้อ B — แอพรู้ว่าเซิร์ฟเวอร์มีอะไรแล้วจาก /v1/app/config
 * และแพ็กเกจไพ่บอกห้องผู้พยากรณ์ที่อ่านให้ (ชุดเดียวกับเมนูไพ่ของเว็บ)
 */
class AppConfigParityApiTest extends TestCase
{
    use RefreshDatabase;

    private const FEATURES = [
        'rooms', 'qa_clock', 'free_ask', 'chat_feed', 'chat_transcribe', 'voice_premium',
        'reading_share', 'reading_review', 'deep_async', 'free_reading', 'daily_luck',
        'collection', 'testimonials', 'account_profile', 'push',
    ];

    public function test_config_flags_every_parity_block_and_keeps_the_old_keys(): void
    {
        Cache::put('voice-premium:ready', true, 60);

        $res = $this->getJson('/api/v1/app/config')->assertOk();

        foreach (self::FEATURES as $key) {
            $this->assertTrue($res->json("data.features.$key"), "features.$key");
        }
        // ของเดิมยังอยู่ ความหมายเดิม
        foreach (['tarot_async', 'tarot_packages', 'account_deletion', 'change_password', 'content_reports'] as $key) {
            $this->assertTrue($res->json("data.features.$key"), "features.$key");
        }

        $res->assertJsonPath('data.voice_premium.offerable', true)
            ->assertJsonPath('data.voice_premium.qa_minutes', (int) config('reading_voice.qa_minutes', 20))
            ->assertJsonPath('data.rooms', true)
            ->assertJsonPath('data.push.fcm', false);
        $this->assertEquals(VoicePremium::price(), $res->json('data.voice_premium.price'));
        $this->assertIsBool($res->json('data.free_reading.enabled'));
    }

    public function test_voice_premium_is_not_offerable_while_the_voice_is_down(): void
    {
        Cache::put('voice-premium:ready', false, 60);

        $this->getJson('/api/v1/app/config')->assertOk()
            ->assertJsonPath('data.voice_premium.offerable', false);
    }

    public function test_packages_name_the_room_that_reads_them(): void
    {
        $byKey = collect($this->getJson('/api/v1/tarot/packages')->assertOk()->json('data'))->keyBy('key');

        foreach ($byKey as $key => $item) {
            $this->assertSame(ChatRooms::roomForSpread($key), $item['room'], $key);
            $this->assertSame(ChatRooms::readerName($key), $item['reader_name'], $key);
            $this->assertSame($item['requires_async'], $item['web_only'], $key);
        }
    }
}
