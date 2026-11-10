<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 📱 (2026-11-10) ฉากผู้พยากรณ์บน iPad — เจ้าของ: "เปิดบน ไอแพท ชอบถูกครอป ตัวละคร ทุกคนเลย"
 *
 * สิ่งที่ล็อกไว้:
 *  - ห้องแชท: เลย์เอาต์จอกว้าง (แผงแชทชิดขวา) ใช้เฉพาะจอแนวนอน ≥ 900px — แท็บเล็ตแนวตั้งทุกขนาด
 *    (iPad Pro / Safari โหมดเว็บเดสก์ท็อปกว้าง 1024 px) ใช้ฉากแบบมือถือ และ CSS กับสคริปต์จัดฉากต้องตรงกัน
 *  - ผู้พยากรณ์เลื่อนลงตามแถบสถานะของเครื่อง (safe-area) เหมือนแถบบน — ป้ายชื่อห้องไม่ทับหัว
 *  - หน้าแรก: สไลด์ภาพผู้พยากรณ์ใช้แบบภาพบน-ข้อความล่างบนจอแนวตั้งทุกขนาด
 */
class OracleSceneLayoutTest extends TestCase
{
    use RefreshDatabase;

    /** CSS เลือกเลย์เอาต์ด้วยทิศจอ และสคริปต์จัดฉากใช้เกณฑ์เดียวกัน — ไม่งั้นภาพโต๊ะวางผิดฝั่งกับแผงแชท */
    public function test_room_wide_layout_needs_landscape_in_css_and_script(): void
    {
        $css = (string) file_get_contents(resource_path('css/oracle-room.css'));
        $this->assertStringContainsString('@media (min-width: 900px) and (orientation: landscape) {', $css);
        $this->assertStringContainsString('@media (max-width: 899.98px), (orientation: portrait) {', $css);
        $this->assertStringNotContainsString('@media (min-width: 900px) {', $css, 'เลย์เอาต์จอกว้างต้องดูทิศจอด้วย');

        $blade = (string) file_get_contents(resource_path('views/pages/chat/index.blade.php'));
        $this->assertStringContainsString("const WIDE_MQ = '(min-width: 900px) and (orientation: landscape)';", $blade);
        $this->assertStringContainsString('window.matchMedia(WIDE_MQ).matches', $blade);
    }

    /** ห้องแชทมีตัววัดแถบสถานะ และความสูงผู้พยากรณ์หักระยะนั้นออก */
    public function test_room_scene_reads_the_safe_area_for_the_actor(): void
    {
        $this->withoutVite();
        $this->get(route('chat.index', ['room' => 'maemor']))->assertOk()
            ->assertSee('<i class="or-safe"></i>', false)
            ->assertSee('const top = (wide ? 96 : 70) + safeTop;', false);

        $this->assertStringContainsString('env(safe-area-inset-top', (string) file_get_contents(resource_path('css/oracle-room.css')));
        // โรงละครเปิดไพ่: หักแถบสถานะทั้งแนวตั้งและแนวนอน
        $this->assertStringContainsString('(portrait ? 118 : 124) - safeTop', (string) file_get_contents(resource_path('js/card-theater/index.js')));
    }

    /** หน้าแรก: จอแนวตั้งทุกความกว้างได้ภาพผู้พยากรณ์ด้านบน ไม่ใช่ภาพชิดล่างล้นจอแบบจอคอม */
    public function test_home_hero_stacks_on_every_portrait_screen(): void
    {
        $css = (string) file_get_contents(resource_path('css/themes/juntra-payakorn.css'));
        $this->assertStringContainsString("@media (max-width: 900px), (orientation: portrait) {\n  .hero.has-slides { align-items: flex-end;", $css);
        $this->assertMatchesRegularExpression('/\.hero-slide-image \{[^}]*object-position: center top;/s', $css);
    }
}
