<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 📱 (2026-09-24) แอพต้องรู้ "สิ่งที่เว็บรู้" โดยไม่ต้องออกรุ่นใหม่:
 *  - แพ็กเกจไพ่ที่เปิดขาย (ราคา/ภาพ/ตำแหน่ง/ข้อห้ามเปิดซ้ำ) มาจาก config/tarot_spreads.php + Setting ชุดเดียวกับเว็บ
 *  - บริการที่ปิดขาย (ServiceGate) — เดิมแอพรู้ก็ต่อเมื่อกดเข้าไปแล้วโดน 503
 *  - ลิงก์นโยบาย/ลบบัญชี (Google Play บังคับ) และสวิตช์เติมเครดิตผ่าน Play
 */
class MobileAppConfigAndPackagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_packages_list_what_the_web_sells_with_live_prices(): void
    {
        Setting::put('pricing_tarot_love', '45', 'pricing');

        $r = $this->getJson('/api/v1/tarot/packages')->assertOk();
        $byKey = collect($r->json('data'))->keyBy('key');

        $this->assertSame(['single', 'three', 'love', 'career', 'decision', 'celtic', 'year'], $byKey->keys()->all(),
            'คุณไสยยังซ่อน (tarot_kunsai_visible ไม่ได้เปิด)');
        $this->assertEquals(45, $byKey['love']['price'], 'ราคาต้องมาจากหลังบ้าน ไม่ใช่ค่าที่แอพฝังไว้');
        $this->assertSame(5, $byKey['love']['cards']);
        $this->assertCount(5, $byKey['love']['positions']);
        $this->assertSame('tarot_love', $byKey['love']['type']);
        $this->assertTrue($byKey['celtic']['birth']);
        $this->assertSame(30, $byKey['year']['cooldown_days']);
        $this->assertFalse($byKey['three']['requires_async']);
        $this->assertStringEndsWith('/images/juntra/art/tarot/love.webp', (string) $byKey['love']['image_url']);
    }

    public function test_hidden_package_appears_once_the_owner_opens_it_and_needs_async(): void
    {
        Setting::put('tarot_kunsai_visible', '1', 'pricing');

        $kunsai = collect($this->getJson('/api/v1/tarot/packages')->json('data'))->firstWhere('key', 'kunsai');

        $this->assertNotNull($kunsai);
        $this->assertTrue($kunsai['requires_async']);
        $this->assertSame(10, $kunsai['cards']);
    }

    public function test_free_switch_shows_as_free(): void
    {
        Setting::put('pricing_tarot_single_enabled', '0', 'pricing');

        $single = collect($this->getJson('/api/v1/tarot/packages')->json('data'))->firstWhere('key', 'single');

        $this->assertEquals(0, $single['price']);
        $this->assertTrue($single['free']);
    }

    public function test_config_mirrors_the_service_switches(): void
    {
        Setting::put('service_numerology_closed', '1', 'service');
        Setting::put('service_horoscope_closed', '1', 'service');

        $r = $this->getJson('/api/v1/app/config')->assertOk();

        $r->assertJsonPath('data.services.tarot', true)
            ->assertJsonPath('data.services.chat', true)
            ->assertJsonPath('data.services.numerology', false)
            ->assertJsonPath('data.services.horoscope', false)
            ->assertJsonPath('data.services.auspicious', true)
            ->assertJsonPath('data.features.tarot_async', true)
            ->assertJsonPath('data.billing.google_play', false);   // ยังไม่ได้ตั้ง service account
        $this->assertStringEndsWith('/privacy', $r->json('data.legal.privacy_url'));
        $this->assertStringEndsWith('/terms', $r->json('data.legal.terms_url'));
        $this->assertStringEndsWith('/account/delete', $r->json('data.legal.account_deletion_url'));
    }

    public function test_the_public_deletion_page_explains_how_to_delete(): void
    {
        Setting::put('theme', 'juntra-payakorn', 'appearance');

        $this->get('/account/delete')->assertOk()
            ->assertSee('ลบบัญชีและข้อมูล')
            ->assertSee('ลบบัญชีถาวร')
            ->assertSee('ข้อมูลที่เก็บต่อ');
    }
}
