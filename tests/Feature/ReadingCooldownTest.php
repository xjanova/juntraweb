<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Services\Chat\ChatOffers;
use App\Services\Readings\ReadingBillActions;
use App\Services\Wallet\WalletService;
use App\Support\ReadingCooldown;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🕯️ (2026-09-21) ข้อห้ามของครูบาอาจารย์: ลูกค้าคนเดิมห้ามเปิดแพ็กเกจเดิมซ้ำภายใน N วัน
 *
 * เจ้าของสั่ง: ไพ่ 12 ใบเปิดได้เดือนละครั้ง ตั้งได้จากหลังบ้าน — ด่านต้องกันก่อนหักเงินทุกช่องทาง
 * (เว็บ · แอพ) และไม่ยื่นการ์ดแพ็กเกจที่ติดข้อห้ามในแชท
 */
class ReadingCooldownTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    private string $reply = "## 🎯 ฟันธง\nผล: ปีแห่งการเริ่มใหม่\nไพ่หนุนค่ะลูก\n\n## 🧭 คำแนะนำ\n- ลงมือเลย";

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Cache::flush();

        Http::fake(function (Request $req) {
            if (str_ends_with($req->url(), '/oauth/token')) {
                return Http::response(['access_token' => 'srv', 'expires_in' => 3600]);
            }
            if (str_ends_with($req->url(), '/server/fortune/tarot/interpret')) {
                return Http::response(['data' => ['interpretation' => $this->reply, 'ai_provider' => 'openai', 'ai_model' => 'gpt-5.6-luna']]);
            }

            return Http::response([], 500);
        });

        foreach (range(0, 11) as $i) {
            TarotCard::create([
                'slug' => "card-{$i}", 'name_en' => "Card {$i}", 'name_th' => "ไพ่ {$i}", 'arcana' => 'major', 'suit' => 'major',
                'number' => $i, 'keywords_th' => 'ทดสอบ', 'upright_meaning_th' => 'ตั้งตรง', 'reversed_meaning_th' => 'กลับหัว', 'active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(): User
    {
        $u = User::factory()->create(['thaiprompt_token' => null]);
        app(WalletService::class)->credit($u, 1000, 'seed');

        return $u;
    }

    private function cast(User $u, string $spread)
    {
        return $this->actingAs($u)->post(route('tarot.cast'), ['spread' => $spread]);
    }

    private function balance(User $u): float
    {
        return (float) app(WalletService::class)->balance($u);
    }

    public function test_the_12_month_package_opens_once_per_30_days_by_default(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:41', 'Asia/Bangkok'));
        $u = $this->member();

        $this->cast($u, 'year')->assertRedirect();
        $this->assertSame(1, Reading::where('type', 'tarot_year')->count());
        $afterFirst = $this->balance($u);

        // 20 days later — still inside the window: blocked before any debit
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00', 'Asia/Bangkok'));
        $this->cast($u, 'year')
            ->assertRedirect(route('tarot.index'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'ครูบาอาจารย์ห้ามเปิดพยากรณ์ 12 เดือนซ้ำภายใน 30 วัน')
                && str_contains($s, '21 ก.ย. 2569 10:41 น.')
                && str_contains($s, 'เปิดใหม่ได้ตั้งแต่ 21 ต.ค. 2569 10:41 น.')
                && str_contains($s, 'ยังไม่มีการหักเครดิต'));
        $this->assertSame(1, Reading::where('type', 'tarot_year')->count());
        $this->assertSame($afterFirst, $this->balance($u), 'a blocked open must not debit');

        // other packages are not affected by the 12-month rule
        $this->cast($u, 'three')->assertRedirect();
        $this->assertSame(1, Reading::where('type', 'tarot_three')->count());

        // the window is rolling from the real opening time, not the calendar month
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:42', 'Asia/Bangkok'));
        $this->cast($u, 'year')->assertRedirect();
        $this->assertSame(2, Reading::where('type', 'tarot_year')->count());
    }

    public function test_the_rule_is_per_customer(): void
    {
        $this->cast($this->member(), 'year')->assertRedirect();
        $this->cast($this->member(), 'year')->assertRedirect();

        $this->assertSame(2, Reading::where('type', 'tarot_year')->count());
    }

    public function test_the_landing_page_says_when_it_opens_again_and_begin_refuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:41', 'Asia/Bangkok'));
        $u = $this->member();
        $this->cast($u, 'year');
        $reading = Reading::where('type', 'tarot_year')->firstOrFail();

        $this->actingAs($u)->get(route('tarot.index'))->assertOk()
            ->assertSee('เปิดได้อีกครั้ง 21 ต.ค. 2569 10:41 น.')
            ->assertSee(route('tarot.show', $reading), false);

        $this->actingAs($u)->post(route('tarot.begin'), ['spread' => 'year'])
            ->assertRedirect(route('tarot.index'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'ครูบาอาจารย์ห้ามเปิด'));

        $this->actingAs($u)->post(route('tarot.begin'), ['spread' => 'three'])->assertRedirect(route('tarot.pick'));
    }

    public function test_a_failed_a_refunded_or_a_free_reading_does_not_count(): void
    {
        $u = $this->member();
        $this->cast($u, 'year');
        $r = Reading::where('type', 'tarot_year')->firstOrFail();

        $r->update(['status' => Reading::STATUS_FAILED]);
        $this->assertNull(ReadingCooldown::blockingReading($u, 'year'), 'a failed reading was refunded — nothing to read');

        $r->update(['status' => null]);
        $this->assertNotNull(ReadingCooldown::blockingReading($u, 'year'));

        app(ReadingBillActions::class)->refund($r, 'ลูกค้าไม่พอใจ', null);
        $this->assertNull(ReadingCooldown::blockingReading($u, 'year'), 'an admin-refunded bill does not use up the window');

        Setting::put(ReadingCooldown::settingKey('single'), '1', 'tarot');
        Reading::create(['user_id' => $u->id, 'session_token' => 'free-1', 'type' => 'tarot_single', 'payload' => ['cost' => 0, 'free' => true]]);
        $this->assertNull(ReadingCooldown::blockingReading($u, 'single'), 'the free card from the bot has its own policy');
    }

    public function test_the_admin_setting_overrides_the_default(): void
    {
        $u = $this->member();

        Setting::put(ReadingCooldown::settingKey('year'), '0', 'tarot');
        $this->cast($u, 'year');
        $this->cast($u, 'year');
        $this->assertSame(2, Reading::where('type', 'tarot_year')->count(), '0 = no limit');

        Setting::put(ReadingCooldown::settingKey('three'), '7', 'tarot');
        $this->assertSame(7, ReadingCooldown::days('three'));
        $this->cast($u, 'three');
        $this->cast($u, 'three')->assertSessionHas('status', fn ($s) => str_contains($s, 'ภายใน 7 วัน'));
        $this->assertSame(1, Reading::where('type', 'tarot_three')->count());
    }

    public function test_the_app_gets_409_cooldown_without_a_debit(): void
    {
        $u = $this->member();
        $this->cast($u, 'year');
        $first = Reading::where('type', 'tarot_year')->firstOrFail();
        $before = $this->balance($u);

        Sanctum::actingAs($u);
        $picks = array_map(fn ($i) => ['slug' => "card-{$i}", 'reversed' => false], range(0, 11));
        $r = $this->postJson('/api/v1/history/readings', ['type' => 'tarot_year', 'picks' => $picks]);

        $r->assertStatus(409)->assertJsonPath('reason_code', 'cooldown')->assertJsonPath('reading_id', $first->id);
        $this->assertStringContainsString('ครูบาอาจารย์ห้ามเปิด', $r->json('message'));
        $this->assertNotEmpty($r->json('available_at'));
        $this->assertSame($before, $this->balance($u));
        $this->assertSame(1, Reading::where('type', 'tarot_year')->count());
    }

    public function test_chat_does_not_offer_a_package_the_customer_cannot_open_yet(): void
    {
        $u = $this->member();
        $this->assertContains('tarot_love', array_column(ChatOffers::for('love', $u), 'key'));

        Setting::put(ReadingCooldown::settingKey('love'), '3', 'tarot');
        $this->cast($u, 'love');

        $this->assertNotContains('tarot_love', array_column(ChatOffers::for('love', $u), 'key'));
        $this->assertContains('tarot_love', array_column(ChatOffers::for('love', $this->member()), 'key'), 'other customers still see it');
    }
}
