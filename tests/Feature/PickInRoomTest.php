<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Chat\ChatOffers;
use App\Support\TarotSpreads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 🔮 (2026-11-06) เจ้าของสั่ง "หน้าเลือกเปิดไพ่ ชนิดไพ่ ไม่ต้องมีแล้ว ให้ไปเลือกในห้องแทน"
 *
 *   - /tarot ไม่มีหน้ารวมแพ็กเกจแล้ว — พาเข้าห้องพร้อมรายการไพ่ทุกแบบ (ลิงก์เดิมทั่วเว็บยังใช้ได้)
 *   - ข้อความแจ้งจากทางที่ถูกปฏิเสธ (ข้อห้ามเปิดซ้ำ ฯลฯ) ตามไปถึงห้อง
 *   - ไม่มีแบบไหนหายไปจากการขาย: รายการในห้องรวมทุกแบบที่เปิดขายของห้องนั้น
 */
class PickInRoomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
    }

    private function member(): User
    {
        return User::factory()->create(['thaiprompt_token' => null]);
    }

    public function test_the_package_page_is_gone_and_leads_into_the_room_with_the_menu_open(): void
    {
        $this->get(route('tarot.index'))->assertRedirect(route('chat.index', ['room' => 'maemor', 'menu' => 1]));

        $u = $this->member();
        $page = $this->actingAs($u)->get(route('chat.index', ['room' => 'maemor', 'menu' => 1]))->assertOk();
        $page->assertViewHas('menuOpen', true);

        $menu = $page->viewData('tableMenu');
        $table = $page->viewData('tableOffers');
        // 🃏 (2026-11-06 เจ้าของสั่ง) โต๊ะวางไพ่ทุกแบบที่ผู้พยากรณ์คนนี้ดู — ล้นจอเลื่อนสไลด์ได้
        $this->assertSame(array_column($menu, 'key'), array_column($table, 'key'), 'โต๊ะวางครบทุกแบบของห้อง');
        foreach (array_keys(TarotSpreads::all()) as $key) {
            $this->assertContains('tarot_' . $key, array_column($menu, 'key'), "ไพ่แบบ {$key} ต้องเลือกได้ในห้อง");
        }
        // ลูกค้าคนนี้ไม่มี token ฝั่งทำนาย → ไม่วางการ์ดไพ่ฟรี · แบบของห้องเรียงตามรายการของห้อง
        $this->assertNotContains('free', array_column($menu, 'key'));
        $this->assertSame('tarot_' . \App\Support\ChatRooms::spreads('maemor')[0], $menu[0]['key'], 'แบบของห้องเรียงตามรายการของห้อง');
        $page->assertSee('ดูรายละเอียด')->assertSee('ไพ่ความรัก / เนื้อคู่');

        // ไม่มี ?menu=1 = แผ่นรายการปิดอยู่ แต่ปุ่ม "ดูรายละเอียด" ยังอยู่บนโต๊ะ
        $this->actingAs($u)->get(route('chat.index', ['room' => 'maemor']))->assertOk()
            ->assertViewHas('menuOpen', false)->assertSee('ดูรายละเอียด');
    }

    public function test_a_refusal_message_follows_the_customer_into_the_room(): void
    {
        $u = $this->member();
        $this->actingAs($u)->post(route('tarot.begin'), ['spread' => 'nonsense'])->assertRedirect();

        $this->actingAs($u)->withSession(['_flash' => ['old' => [], 'new' => ['status']], 'status' => 'ข้อความแจ้งจากด่าน'])
            ->get(route('tarot.index'))
            ->assertRedirect(route('chat.index', ['room' => 'maemor', 'menu' => 1]));
        $this->actingAs($u)->get(route('chat.index', ['room' => 'maemor', 'menu' => 1]))->assertOk()
            ->assertSee('ข้อความแจ้งจากด่าน');
    }

    public function test_a_guest_sees_no_menu_and_no_broken_buttons(): void
    {
        $page = $this->get(route('chat.index', ['room' => 'maemor', 'menu' => 1]))->assertOk();
        $page->assertViewHas('tableMenu', [])->assertViewHas('menuOpen', false)->assertDontSee('ดูรายละเอียด');
    }

    public function test_with_oracle_rooms_on_each_room_sells_its_own_spread_and_the_rest_stay_with_mae_mor(): void
    {
        Setting::put('tarot_quick_visible', '1', 'tarot', false);
        config(['chat_rooms.persona_upstream' => true]);
        Cache::flush();
        $u = $this->member();

        $this->assertSame('busaba', ChatOffers::menuRoomOf('quick'));
        // 🌸 (2026-11-06 เจ้าของสั่ง) บุษบาดูไพ่ 1 ใบ · 3 ใบ · ไพ่ด่วน (+ไพ่ฟรีประจำวัน)
        $this->assertSame(['tarot_single', 'tarot_three', 'tarot_quick'], array_column(ChatOffers::roomMenu('busaba', $u), 'key'));
        // ลูกค้าที่ต่อฝั่งทำนายได้ → ไพ่ฟรีประจำวันขึ้นก่อน และอยู่ห้องบุษบาเท่านั้น
        $linked = User::factory()->create(['thaiprompt_token' => 'tok-linked']);
        $this->assertSame(['free', 'tarot_single', 'tarot_three', 'tarot_quick'], array_column(ChatOffers::roomMenu('busaba', $linked), 'key'));
        $this->assertNotContains('free', array_column(ChatOffers::roomMenu('maemor', $linked), 'key'));

        $maemor = array_column(ChatOffers::roomMenu('maemor', $u), 'key');
        $this->assertNotContains('tarot_quick', $maemor);
        $this->assertNotContains('tarot_single', $maemor);
        $this->assertNotContains('free', $maemor, 'ไพ่ฟรีเป็นของบุษบา ไม่ใช่แม่หมอ');
        $this->assertSame('tarot_love', $maemor[0]);
        $this->assertContains('tarot_celtic', $maemor);
        $this->assertContains('tarot_year', $maemor, 'ไพ่ 12 เดือนต้องไม่หายจากการขาย');

        $this->get(route('tarot.index', ['spread' => 'quick']))
            ->assertRedirect(route('chat.index', ['room' => 'busaba', 'menu' => 1]));
    }
}
