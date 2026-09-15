<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Chat\ChatOffers;
use App\Support\ServiceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * เจ้าของสั่ง (2026-09-15 รอบ 2): "ลายมือและอื่น ๆ ปิดบริการก่อน เหลือไว้แต่ไพ่"
 * ปิดแล้ว = ใช้ไม่ได้ทุกช่องทาง ลิงก์เดิมไม่พัง และหายจากเมนู/หน้าแรก/แชทเอง · เปิดคืน = กลับมาเอง
 */
class TarotOnlyStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ธีมที่ใช้จริงบน prod (เทสต์ตั้งค่าเริ่มต้นเป็นอีกธีม)
        Setting::put('theme', 'juntra-payakorn', 'appearance');
        Cache::flush();
    }

    private function closeAllButTarot(): void
    {
        foreach (array_keys(ServiceGate::SERVICES) as $svc) {
            Setting::put("service_{$svc}_closed", '1', 'service');
        }
        Cache::flush();
    }

    public function test_closed_services_answer_with_a_friendly_page_on_web_and_503_in_the_app(): void
    {
        $this->closeAllButTarot();
        $u = User::factory()->create();

        foreach (['palmistry.index', 'deep.index', 'horoscope.index', 'horoscope.thai', 'numerology.index', 'auspicious.index'] as $route) {
            $this->actingAs($u)->get(route($route))->assertOk()->assertSee('ปิดปรับปรุงชั่วคราว')->assertSee(route('tarot.index'), false);
        }
        $this->actingAs($u)->post(route('deep.store'), ['questions' => ['x']])->assertRedirect(route('deep.index'));

        Sanctum::actingAs($u);
        $this->postJson(route('api.v1.fortune.palmistry'))->assertStatus(503)->assertJsonPath('reason_code', 'service_closed');
        $this->postJson(route('api.v1.deep.store'), ['questions' => ['x']])->assertStatus(503);
    }

    public function test_the_menu_home_and_chat_only_offer_tarot_while_the_rest_is_closed(): void
    {
        $this->closeAllButTarot();

        $home = $this->get(route('home'))->assertOk();
        $home->assertSee(route('tarot.free'), false)->assertSee(route('chat.index'), false)
            ->assertSee('ไพ่ความรัก / เนื้อคู่')                       // a real package from the registry
            ->assertSee(route('tarot.index', ['spread' => 'celtic']), false);
        foreach (['palmistry.index', 'numerology.index', 'auspicious.index', 'horoscope.index', 'deep.index'] as $r) {
            $home->assertDontSee(route($r), false);
        }
        $this->assertNotContains('deep', array_column(ChatOffers::for('love'), 'key'));

        // Reopening brings them back without touching any view.
        Setting::put('service_palmistry_closed', '0', 'service');
        Cache::flush();
        $this->get(route('home'))->assertSee(route('palmistry.index'), false);
    }

    public function test_a_package_link_from_the_home_page_preselects_it(): void
    {
        $this->get(route('tarot.index', ['spread' => 'love']))->assertOk()->assertSee("spread: 'love'", false);
        $this->get(route('tarot.index', ['spread' => 'nonsense']))->assertOk()->assertSee("spread: 'three'", false);
    }

    public function test_readings_already_bought_stay_readable_when_the_service_closes(): void
    {
        $this->closeAllButTarot();
        $u = User::factory()->create();
        $r = \App\Models\Reading::create(['user_id' => $u->id, 'session_token' => 's1', 'type' => 'deep', 'question' => 'q', 'result' => 'คำทำนายเชิงลึกของลูก']);

        $this->actingAs($u)->get(route('deep.show', $r))->assertOk()->assertSee('คำทำนายเชิงลึกของลูก');
    }
}
