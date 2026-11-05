<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 🔊 (2026-11-05) เสียงเปิดเป็นค่าเริ่มต้น + ป๊อปอัปกลางจอเมื่อเบราว์เซอร์บล็อก
 * เจ้าของสั่ง "ให้ผู้ใช้เปิดเสียงหากยังไม่ได้เปิดเด้งมากลางจอเลย" + "ถ้าเครื่องไหนเปิดได้เลย ก็เปิดให้เลย"
 *
 * ล็อกไว้: ป๊อปอัปอยู่บนหน้าที่มีเสียงผู้พยากรณ์ (ห้องสด / เลือกไพ่) ครั้งเดียวต่อหน้า
 * และไม่โผล่บนหน้าเลือกห้อง (ไม่มีเสียง) · สวิตช์เสียงจำ "ปิดเอง" ไว้ ไม่ใช่ "เปิด"
 */
class SoundAskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['chat_rooms.persona_upstream' => true]);
        Setting::put('tarot_quick_visible', '1', 'tarot', false);
        Cache::flush();
    }

    public function test_the_live_room_carries_the_sound_popup_once(): void
    {
        $u = User::factory()->create(['thaiprompt_token' => null]);
        $html = $this->actingAs($u)->get(route('chat.index', ['room' => 'busaba']))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="soundAsk"'));
        $this->assertStringContainsString('แตะเพื่อเปิดเสียง', $html);
        $this->assertStringContainsString("'juntra:voice-off'", $html);   // จำเฉพาะตอนปิดเอง = เปิดเป็นค่าเริ่มต้น
        $this->assertStringNotContainsString("getItem('juntra:voice') === '1'", $html);
    }

    public function test_the_room_picker_has_no_sound_popup(): void
    {
        $this->actingAs(User::factory()->create(['thaiprompt_token' => null]))
            ->get(route('chat.index', ['pick' => 1]))->assertOk()
            ->assertDontSee('id="soundAsk"', false);
    }
}
