<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\ServiceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * เจ้าของสั่ง (2026-11-04):
 *   - "โชว์ใบอนุญาต (DBD Registered) ไว้หน้าเว็บ และตรา dbd ไว้ footer"
 *   - "ทำให้เว็บรับกับ seo ให้สมบูรณ์ ทั้งปกติ และ ai"
 */
class SeoAndTrustTest extends TestCase
{
    use RefreshDatabase;

    private const DBD_URL = 'https://dbdregistered.dbd.go.th/api/public/trustmarkinfo/125789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    /** ดึง JSON-LD ทุกก้อนในหน้าออกมาเป็น array */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(fn ($j) => json_decode($j, true, 512, JSON_THROW_ON_ERROR), $m[1]);
    }

    public function test_dbd_badge_is_in_the_footer_and_the_licence_is_on_the_home_page(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('class="dbd-badge"', $html);
        $this->assertStringContainsString('id="dbd-registered"', $html);
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'href="' . self::DBD_URL . '"'), 'ตรา + ภาพใบรับรอง + ปุ่มตรวจสอบ ลิงก์ไปหน้ากรมฯ');
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertFileExists(public_path(config('business.dbd.badge')));
        $this->assertFileExists(public_path(config('business.dbd.certificate')));

        // โซเชียลทางการครบทุกช่องทาง + อยู่ใน schema sameAs ด้วย
        foreach (config('business.social') as $key => $s) {
            $this->assertStringContainsString('class="social-link social-' . $key . '"', $html);
            $this->assertStringContainsString('href="' . $s['url'] . '"', $html);
        }
        $store = collect($this->jsonLd($html)[0]['@graph'])->firstWhere('@type', 'OnlineStore');
        $this->assertSame(array_values(array_column(config('business.social'), 'url')), $store['sameAs']);

        // ตราอยู่ทุกหน้าที่ใช้เลย์เอาต์ ไม่ใช่แค่หน้าแรก
        $this->get(route('tarot.index'))->assertOk()->assertSee('class="dbd-badge"', false);
    }

    public function test_public_pages_are_indexable_with_canonical_and_private_pages_are_noindex(): void
    {
        $this->get('/chat?pick=1')->assertOk()
            ->assertSee('<meta name="robots" content="index,follow', false)
            ->assertSee('<link rel="canonical" href="' . route('chat.index') . '">', false);

        $this->get(route('login'))->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertDontSee('rel="canonical"', false);

        $this->actingAs(User::factory()->create())->get(route('wallet.index'))->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false);
    }

    public function test_a_closed_service_page_is_noindex_and_left_out_of_the_sitemap(): void
    {
        $this->closeAllButTarot();

        $this->get(route('palmistry.index'))->assertOk()->assertSee('<meta name="robots" content="noindex,follow">', false);

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<loc>' . route('tarot.index') . '</loc>', $xml);
        $this->assertStringNotContainsString(route('palmistry.index'), $xml);
        $this->assertStringNotContainsString('/horoscope', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'sitemap ต้องเป็น XML ที่ถูกต้อง');
    }

    public function test_robots_txt_welcomes_search_and_ai_crawlers_and_points_to_the_sitemap(): void
    {
        $txt = $this->get('/robots.txt')->assertOk()->getContent();

        foreach (['User-agent: *', 'User-agent: GPTBot', 'User-agent: ClaudeBot', 'User-agent: PerplexityBot', 'User-agent: Google-Extended', 'Allow: /'] as $line) {
            $this->assertStringContainsString($line, $txt);
        }
        $this->assertStringContainsString('Disallow: /account/', $txt);
        $this->assertStringContainsString('Disallow: /wallet', $txt);
        $this->assertStringContainsString('Sitemap: ' . route('seo.sitemap'), $txt);
        $this->assertStringNotContainsString("Disallow: /\n", $txt, 'ห้ามปิดทั้งเว็บ');
        $this->assertFileDoesNotExist(public_path('robots.txt'), 'ไฟล์นิ่งจะบัง route นี้บนเซิร์ฟเวอร์จริง');
    }

    public function test_llms_txt_describes_the_shop_with_real_prices_and_the_dbd_licence(): void
    {
        $md = $this->get('/llms.txt')->assertOk()->getContent();

        $this->assertStringStartsWith('# จันทราพยากรณ์', $md);
        $this->assertStringContainsString('ไพ่ยิปซีออนไลน์', $md);
        $this->assertStringContainsString('125789', $md);
        $this->assertStringContainsString(self::DBD_URL, $md);
        $this->assertStringContainsString(route('tarot.free'), $md);
        $this->assertMatchesRegularExpression('/\(\d+ ใบ\): /u', $md, 'มีแพ็กเกจไพ่พร้อมราคา');
    }

    public function test_structured_data_describes_the_store_and_the_home_faq_matches_what_is_shown(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $blocks = $this->jsonLd($html);
        $types = collect($blocks)->flatMap(fn ($b) => collect($b['@graph'])->pluck('@type'))->all();

        foreach (['OnlineStore', 'WebSite', 'OfferCatalog', 'FAQPage'] as $t) {
            $this->assertContains($t, $types);
        }
        $store = collect($blocks[0]['@graph'])->firstWhere('@type', 'OnlineStore');
        $this->assertSame('125789', $store['hasCredential']['identifier']);

        // FAQ schema ต้องตรงกับคำถามที่แสดงบนหน้าเป๊ะ
        $faq = collect($blocks)->flatMap(fn ($b) => $b['@graph'])->firstWhere('@type', 'FAQPage');
        foreach ($faq['mainEntity'] as $q) {
            $this->assertStringContainsString('<h3>' . e($q['name']) . '</h3>', $html);
        }

        // หน้าอื่นมีแค่ข้อมูลร้าน ไม่มี FAQ ที่ไม่ได้แสดง
        $tarot = $this->get(route('tarot.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('FAQPage', $tarot);
        $this->assertStringContainsString('OnlineStore', $tarot);
    }

    public function test_page_descriptions_are_not_double_escaped(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]{20,}">/u', $html);

        // หน้าที่ไม่ประกาศ description เองได้ของ Seo ตาม route
        $this->get(route('tarot.free'))->assertOk()->assertSee('ดูดวงไพ่ยิปซีฟรี 1 ใบ', false);
    }
}
