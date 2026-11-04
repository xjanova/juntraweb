<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 🏛️ (2026-11-05) ห้องพยากรณ์เต็มจอ — เจ้าของสั่ง "เลือกผู้พยากรณ์แล้วต้องเข้าห้องพยากรณ์ ไม่ใช่ห้องแชท"
 *
 * ล็อกไว้:
 *  - ห้องสด = ฉากโต๊ะของห้องนั้น (ภาพโต๊ะ/ผู้พยากรณ์ชุดเดียวกับโรงละครเปิดไพ่) + แชทอยู่ในฉาก
 *  - แพ็กเกจของห้องวางบนโต๊ะ (ล็อกอินแล้ว) · ยังไม่ล็อกอิน = ฉากเดิมแต่มีปุ่มเข้าสู่ระบบแทนช่องพิมพ์
 *  - หน้าประวัติแชท (อ่านอย่างเดียว) ไม่ใช่ห้อง — หน้าตาเดิม
 *  - ปุ่มบนหน้าเลือกผู้พยากรณ์ = "เข้าห้อง…"
 */
class OracleRoomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['chat_rooms.persona_upstream' => true]);
        Setting::put('tarot_quick_visible', '1', 'tarot', false);
        Setting::put('tarot_kunsai_visible', '1', 'pricing', false);
        Cache::flush();
    }

    public function test_choosing_an_oracle_enters_her_room_scene_with_the_chat_inside(): void
    {
        $u = User::factory()->create(['thaiprompt_token' => null]);

        $res = $this->actingAs($u)->get(route('chat.index', ['room' => 'busaba']))->assertOk()
            ->assertSee('chat-shell is-room', false)
            ->assertSee('images/juntra/theater/plate-busaba.webp', false)
            ->assertSee('images/juntra/theater/sprite-busaba.webp', false)
            ->assertSee('ห้องน้องบุษบา')
            // แชทยังอยู่ครบในฉาก — ช่องพิมพ์ + ปุ่มส่ง
            ->assertSee('class="chat-composer"', false)
            // แพ็กเกจของห้องบนโต๊ะ
            ->assertSee('class="or-table"', false)
            ->assertSee('openTable(', false);

        $this->assertStringNotContainsString('class="page-hero', $res->getContent());
    }

    public function test_a_guest_sees_the_room_with_a_sign_in_card_and_no_table(): void
    {
        $this->get(route('chat.index', ['room' => 'maemor']))->assertOk()
            ->assertSee('chat-shell is-room', false)
            ->assertSee('or-gate', false)
            ->assertDontSee('class="or-table"', false);
    }

    public function test_chat_history_keeps_the_plain_page(): void
    {
        $u = User::factory()->create(['thaiprompt_token' => null]);
        $c = ChatConversation::create(['user_id' => $u->id, 'session_token' => 'tok-1', 'persona' => 'maemor']);
        $c->messages()->create(['role' => 'user', 'content' => 'สวัสดี']);

        $this->actingAs($u)->get(route('chat.show', $c))->assertOk()
            ->assertDontSee('chat-shell is-room', false)
            ->assertDontSee('class="or-table"', false);
    }

    public function test_the_oracle_picker_says_enter_the_room(): void
    {
        $this->actingAs(User::factory()->create(['thaiprompt_token' => null]))
            ->get(route('chat.index', ['pick' => 1]))->assertOk()
            ->assertSee('เข้าห้องบุษบา', false)
            ->assertDontSee('คุยกับบุษบาฟรี', false);
    }
}
