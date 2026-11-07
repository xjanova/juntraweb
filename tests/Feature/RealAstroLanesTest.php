<?php

namespace Tests\Feature;

use App\Models\DailyHoroscope;
use App\Models\Profile;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zodiac;
use App\Services\AuspiciousAdvisor;
use App\Services\AuspiciousScorer;
use App\Services\DailyHoroscopeWriter;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🪐 (2026-11-07) ดวงรายวัน · ฤกษ์ · ลายมือ ไปเครื่องโหรของบอทแม่หมอ (เลนของเว็บ ไม่ต้องมี token ลูกค้า)
 *
 * บั๊กเดิมที่กันไว้:
 *  - Thaiprompt ไม่เคยมี fortune/horoscope/daily กับ fortune/auspicious (404) ⇒ ดวงรายวันเขียนเองจากธาตุ
 *    พร้อมเลข/สี/ไพ่ "นำโชค" ที่สุ่มจาก crc32 — ผิดกฎเจ้าของ (2026-08-02: สีกับไพ่งดถาวร · เลขเฉพาะ 15/29)
 *  - ลายมือใช้ Gemini ในเครื่องซึ่งบน prod ไม่มีคีย์ ⇒ บริการปฏิเสธลูกค้าทุกคน
 */
class RealAstroLanesTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    /** @var array<int, Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
    }

    /** @param  array<string, mixed>  $routes  ท้าย path ของเลนเว็บ → [status, body] */
    private function fakeServer(array $routes): void
    {
        Http::fake(function (Request $req) use ($routes) {
            $this->sent[] = $req;
            $url = $req->url();
            if (str_ends_with($url, '/oauth/token')) {
                return Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]);
            }
            foreach ($routes as $tail => [$status, $body]) {
                if (str_ends_with($url, '/api/v1/juntra/server/fortune/'.$tail)) {
                    return Http::response($body, $status);
                }
            }

            return Http::response(['message' => 'not found'], 404);
        });
    }

    /** @return array<int, Request> */
    private function sentTo(string $tail): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => str_ends_with($r->url(), '/fortune/'.$tail)));
    }

    private function zodiac(string $slug = 'scorpio', string $th = 'พิจิก'): Zodiac
    {
        static $i = 0;

        return Zodiac::create([
            'slug' => $slug, 'name_en' => ucfirst($slug), 'name_th' => $th, 'glyph' => '♏',
            'element' => 'Water', 'ruler' => 'Mars', 'date_range' => '23 ต.ค. - 21 พ.ย.',
            'order_index' => ++$i, 'traits_th' => 'ลึกซึ้ง',
        ]);
    }

    private function member(float $balance = 100): User
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, $balance, 'seed');

        return $u;
    }

    /* ======================= ดวงรายวัน ======================= */

    public function test_daily_horoscope_comes_from_real_sky_lane_and_never_stores_lucky_colour_or_card(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->fakeServer(['horoscope/daily' => [200, ['data' => [
            'summary' => 'ดาวอังคารเจ้าเรือนสถิตเรือนสิบ', 'love' => 'รัก', 'career' => 'งาน', 'money' => 'เงิน', 'health' => 'สุขภาพ',
            'lucky_number' => '15, 29',
            // ฝั่งนั้นส่งมาก็ไม่เก็บ — กฎเจ้าของคือสีกับไพ่งดถาวร
            'lucky_color' => 'แดง', 'lucky_card' => 'The Sun',
            'ai_generated' => true,
        ]]]]);
        $z = $this->zodiac();

        $row = app(DailyHoroscopeWriter::class)->today($z, Carbon::today());

        $this->assertTrue($row->ai_generated);
        $this->assertSame('ดาวอังคารเจ้าเรือนสถิตเรือนสิบ', $row->summary);
        $this->assertSame('15, 29', $row->lucky_number);
        $this->assertNull($row->lucky_color);
        $this->assertNull($row->lucky_card);

        $sent = $this->sentTo('horoscope/daily');
        $this->assertCount(1, $sent);
        $this->assertSame(['zodiac' => 'scorpio', 'zodiac_th' => 'พิจิก', 'date' => '2026-11-15'], $sent[0]->data());

        $this->get('/horoscope/scorpio')->assertOk()
            ->assertSee('ดาวอังคารเจ้าเรือนสถิตเรือนสิบ')
            ->assertSee('เลขนำโชค')
            ->assertDontSee('สีมงคล')
            ->assertDontSee('ไพ่ประจำวัน');
        $this->assertCount(1, $this->sentTo('horoscope/daily'), 'เปิดซ้ำต้องอ่านจาก DB ไม่ยิงซ้ำ');
    }

    public function test_upstream_down_falls_back_to_local_writing_without_any_lucky_items(): void
    {
        Carbon::setTestNow('2026-11-07 09:00:00');
        $this->fakeServer(['horoscope/daily' => [503, ['message' => 'down']]]);
        $z = $this->zodiac();

        $this->get('/horoscope/scorpio')->assertOk()
            ->assertSee('พิจิก')
            ->assertDontSee('เลขนำโชค')
            ->assertDontSee('สีมงคล');

        $row = DailyHoroscope::firstOrFail();
        $this->assertFalse($row->ai_generated);
        $this->assertNull($row->lucky_number, 'ทางที่เขียนเองไม่มีดาวจริง — ห้ามสุ่มเลขนำโชค');
        $this->assertNull($row->lucky_color);
        $this->assertNull($row->lucky_card);
    }

    public function test_app_api_never_sends_lucky_colour_or_card_even_for_old_rows(): void
    {
        Carbon::setTestNow('2026-11-07 09:00:00');
        $this->fakeServer([]);
        $z = $this->zodiac();
        // แถวเก่าก่อนแก้ — มีสี/ไพ่ที่สุ่มไว้ในตาราง
        DailyHoroscope::create([
            'zodiac_id' => $z->id, 'date' => Carbon::today(), 'summary' => 'เก่า', 'love' => '-', 'career' => '-',
            'money' => '-', 'health' => '-', 'lucky_number' => '42', 'lucky_color' => 'ทองอ่อน', 'lucky_card' => 'The Star',
        ]);

        $this->getJson('/api/v1/horoscope/scorpio')->assertOk()
            ->assertJsonPath('data.summary', 'เก่า')
            ->assertJsonPath('data.lucky_color', null)
            ->assertJsonPath('data.lucky_card', null);
        $this->get('/horoscope/scorpio')->assertOk()->assertDontSee('ทองอ่อน')->assertDontSee('The Star');
    }

    public function test_warm_command_writes_every_sign_once_and_skips_existing_rows(): void
    {
        Carbon::setTestNow('2026-11-07 00:05:00');
        $this->fakeServer(['horoscope/daily' => [200, ['data' => [
            'summary' => 'จากดาวจริง', 'love' => 'ก', 'career' => 'ข', 'money' => 'ค', 'health' => 'ง', 'lucky_number' => null,
        ]]]]);
        $this->zodiac('aries', 'เมษ');
        $this->zodiac('taurus', 'พฤษภ');

        $this->artisan('horoscope:warm')->assertSuccessful();
        $this->artisan('horoscope:warm')->assertSuccessful();

        $this->assertSame(2, DailyHoroscope::count());
        $this->assertSame(2, DailyHoroscope::where('ai_generated', true)->count());
        $this->assertCount(2, $this->sentTo('horoscope/daily'), 'รอบสองต้องข้ามราศีที่มีแล้ว');
    }

    /* ======================= ฤกษ์ ======================= */

    public function test_auspicious_sends_candidate_days_and_owner_birth_date_to_the_real_calendar_check(): void
    {
        Carbon::setTestNow('2026-11-07 09:00:00');
        $this->fakeServer(['auspicious' => [200, ['data' => [
            'advice' => 'แม่หมอแนะนำวันที่ผ่านปฏิทินหลวงค่ะ', 'ai_provider' => 'openai', 'ai_model' => 'gpt',
        ]]]]);
        $u = User::factory()->create();
        Profile::create(['user_id' => $u->id, 'birth_date' => '1990-01-07']);

        $days = app(AuspiciousScorer::class)->candidateDays(Carbon::parse('2026-11-08'), Carbon::parse('2026-12-08'), 'wedding');
        $this->assertNotEmpty($days);

        $out = app(AuspiciousAdvisor::class)->advise($u->fresh(), 'wedding', 'แต่งงาน', $days);

        $this->assertSame('maemor', $out['source']);
        $this->assertSame('แม่หมอแนะนำวันที่ผ่านปฏิทินหลวงค่ะ', $out['text']);
        $sent = $this->sentTo('auspicious')[0]->data();
        $this->assertSame('wedding', $sent['category']);
        $this->assertSame('1990-01-07', $sent['birth_date']);
        $this->assertSame(array_map(fn ($d) => $d['date']->format('Y-m-d'), $days), $sent['days']);
        $this->assertStringContainsString('แต่งงาน', $sent['prompt']);
    }

    public function test_auspicious_without_link_or_birth_date_still_reaches_maemor_and_falls_back_locally_when_down(): void
    {
        Carbon::setTestNow('2026-11-07 09:00:00');
        $this->fakeServer(['auspicious' => [503, ['message' => 'ระบบคำแนะนำไม่พร้อมชั่วคราว']]]);
        $days = app(AuspiciousScorer::class)->candidateDays(Carbon::parse('2026-11-08'), Carbon::parse('2026-12-08'), 'general');

        // ลูกค้าสมัครด้วยเบอร์ (ไม่มี token Thaiprompt) — เดิมไม่เคยถึงแม่หมอเลย
        $out = app(AuspiciousAdvisor::class)->advise(User::factory()->create(), 'general', 'เปิดร้าน', $days);

        $this->assertSame('local', $out['source']);
        $this->assertNotSame('', trim($out['text']));
        $sent = $this->sentTo('auspicious')[0]->data();
        $this->assertArrayNotHasKey('birth_date', $sent);
        $this->assertCount(0, array_filter($this->sent, fn (Request $r) => str_contains($r->url(), '/chat/')), 'ไม่ไหลไปท่อแชท');
    }

    /* ======================= ลายมือ ======================= */

    public function test_palm_reading_uses_bot_vision_lane_without_a_local_gemini_key(): void
    {
        $this->fakeServer(['palm' => [200, ['data' => [
            'reading' => 'แม่หมอเห็นมือขวา เส้นชีวิตยาวลึกค่ะ', 'ai_provider' => 'openai', 'ai_model' => 'gpt-4o',
        ]]]]);
        $u = $this->member();
        $cost = Pricing::for('palmistry');

        $this->actingAs($u)->post('/palmistry/analyze', [
            'image' => UploadedFile::fake()->image('palm.png', 2400, 1800),
            'question' => 'งานปีหน้า',
        ])->assertOk()->assertSee('เส้นชีวิตยาวลึก');

        $reading = Reading::where('type', 'palmistry')->firstOrFail();
        $this->assertSame('openai', $reading->ai_provider);
        $this->assertSame(100.0 - $cost, app(WalletService::class)->balance($u));

        $sent = $this->sentTo('palm')[0]->data();
        $this->assertStringStartsWith('data:image/jpeg;base64,', $sent['image'], 'ย่อเป็น JPEG ก่อนส่ง');
        $img = imagecreatefromstring(base64_decode(substr($sent['image'], strlen('data:image/jpeg;base64,'))));
        $this->assertLessThanOrEqual(1600, max(imagesx($img), imagesy($img)));
        $this->assertSame('งานปีหน้า', $sent['question']);
    }

    public function test_palm_photo_without_a_palm_refunds_and_asks_for_a_new_photo(): void
    {
        $this->fakeServer(['palm' => [422, ['message' => 'x', 'reason_code' => 'not_palm']]]);
        $u = $this->member();

        $this->actingAs($u)->post('/palmistry/analyze', ['image' => UploadedFile::fake()->image('cat.jpg')])
            ->assertRedirect(route('palmistry.index'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'ยังไม่เห็นฝ่ามือ'));

        $this->assertSame(100.0, app(WalletService::class)->balance($u), 'คืนเงินแล้ว');
        $this->assertSame(0, Reading::where('type', 'palmistry')->count());
    }

    public function test_palm_vision_down_refunds_with_system_message(): void
    {
        $this->fakeServer(['palm' => [503, ['message' => 'x', 'reason_code' => 'vision_unavailable']]]);
        $u = $this->member();

        $this->actingAs($u)->post('/palmistry/analyze', ['image' => UploadedFile::fake()->image('palm.jpg')])
            ->assertRedirect(route('palmistry.index'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'ระบบขัดข้อง'));

        $this->assertSame(100.0, app(WalletService::class)->balance($u));
    }

    public function test_palm_rejects_svg_uploads(): void
    {
        $this->fakeServer([]);
        $u = $this->member();

        $this->actingAs($u)->post('/palmistry/analyze', [
            'image' => UploadedFile::fake()->createWithContent('palm.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors('image');

        $this->assertSame(100.0, app(WalletService::class)->balance($u));
        $this->assertCount(0, $this->sentTo('palm'));
    }

    public function test_app_palm_not_palm_returns_422_and_refunds(): void
    {
        $this->fakeServer(['palm' => [422, ['message' => 'x', 'reason_code' => 'not_palm']]]);
        $u = $this->member();

        $this->actingAs($u, 'sanctum')
            ->post('/api/v1/fortune/palmistry', ['image' => UploadedFile::fake()->image('cat.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'not_palm');

        $this->assertSame(100.0, app(WalletService::class)->balance($u));
    }
}
