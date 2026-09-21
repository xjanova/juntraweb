<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Services\Chat\ChatOffers;
use App\Services\Chat\ChatReadingIntent;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔮 (2026-09-15) แพ็กเกจไพ่บนเว็บ — ข้อมูลที่ส่งให้แม่หมอ (โปรไฟล์ต่อแพ็กเกจฝั่ง Thaiprompt),
 * วันเกิดไม่บังคับ, แพ็กเกจคุณไสยที่ซ่อนไว้จนเจ้าของอนุมัติ และหน้าผลแบบการ์ด/ตาราง
 */
class TarotPackagesTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    /** @var array<int,array<string,mixed>> */
    private array $sent = [];

    private string $reply = "## 🎯 ฟันธง\nผล: ใช่\nไพ่หนุนค่ะลูก\n\n## 🃏 ใบที่ 1 · สถานการณ์ปัจจุบัน\nลูกกำลังเริ่มต้นใหม่\n\n## 🧭 คำแนะนำ\n- ลงมือเลย";

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
                $this->sent[] = $req->data();

                return Http::response(['data' => ['interpretation' => $this->reply, 'ai_provider' => 'openai', 'ai_model' => 'gpt-5.6-luna', 'profile' => $req['spread_key']]]);
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

    private function member(): User
    {
        $u = User::factory()->create(['thaiprompt_token' => null]);
        app(WalletService::class)->credit($u, 500, 'seed');

        return $u;
    }

    private function cast(User $u, string $spread, array $extra = [])
    {
        return $this->actingAs($u)->post(route('tarot.cast'), ['spread' => $spread, 'question' => 'งานจะดีไหม'] + $extra);
    }

    public function test_the_package_name_position_meanings_and_customer_name_reach_mae_mor(): void
    {
        $this->cast($this->member(), 'love')->assertRedirect();

        $p = $this->sent[0];
        $this->assertSame('love', $p['spread_key']);
        $this->assertNotEmpty($p['cards'][0]['asks'], 'the position meaning travels as `asks` (Thaiprompt dropped `position_asks`)');
        $this->assertArrayNotHasKey('months', $p);
        $this->assertArrayNotHasKey('birth_date', $p);
    }

    public function test_the_12_month_package_sends_real_month_names(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00', 'Asia/Bangkok'));
        $this->cast($this->member(), 'year')->assertRedirect();
        $this->assertSame('ก.ย. 2569', $this->sent[0]['months'][0]);
        $this->assertSame('ส.ค. 2570', $this->sent[0]['months'][11]);
        // the plain prompt (the app's path and the fallbacks) names the same real months
        $this->assertStringContainsString('ตำแหน่งที่ 1 — เดือนที่ 1 (ก.ย. 2569)', $this->sent[0]['prompt']);
        $this->assertStringContainsString('ตำแหน่งที่ 12 — เดือนที่ 12 (ส.ค. 2570)', $this->sent[0]['prompt']);
        $this->assertStringNotContainsString('ด้านบวกของไพ่', $this->sent[0]['prompt'], 'upright is not "the positive side" — an upright Tower is heavy');

        // Late in the month the first "month" would be a few days — start next month.
        Carbon::setTestNow(Carbon::parse('2026-09-28 10:00', 'Asia/Bangkok'));
        $this->cast($this->member(), 'year')->assertRedirect();
        $this->assertSame('ต.ค. 2569', $this->sent[1]['months'][0]);
        $this->assertCount(12, $this->sent[1]['months']);
        Carbon::setTestNow();
    }

    public function test_birth_date_is_offered_and_sent_only_for_packages_that_read_the_chart(): void
    {
        $u = $this->member();
        $u->profile()->create(['birth_date' => '1990-06-27']);

        $this->actingAs($u)->post(route('tarot.begin'), ['spread' => 'celtic']);
        $this->actingAs($u)->get(route('tarot.pick'))->assertOk()->assertSee('name="birth_date"', false)->assertSee('1990-06-27');

        $this->actingAs($u)->post(route('tarot.begin'), ['spread' => 'love']);
        $this->actingAs($u)->get(route('tarot.pick'))->assertOk()->assertDontSee('name="birth_date"', false);

        $this->cast($u, 'celtic', ['birth_date' => '1990-06-27'])->assertRedirect();
        $this->cast($u, 'love', ['birth_date' => '1990-06-27'])->assertRedirect();

        $this->assertSame('1990-06-27', $this->sent[0]['birth_date']);
        $this->assertArrayNotHasKey('birth_date', $this->sent[1], 'love does not use the chart');
        $this->assertSame('1990-06-27', data_get(Reading::where('type', 'tarot_celtic')->first()->payload, 'birth_date'));
    }

    public function test_the_kunsai_package_is_unsellable_until_the_owner_turns_it_on(): void
    {
        $u = $this->member();

        $this->get(route('tarot.index'))->assertOk()->assertDontSee('ไพ่ดูคุณไสย');
        $this->cast($u, 'kunsai')->assertSessionHasErrors('spread');
        $this->assertSame(['tarot_celtic', 'deep'], array_column(ChatOffers::for('kunsai'), 'key'), 'a hidden package never shows up as a chat card');

        Setting::put('tarot_kunsai_visible', '1', 'pricing');
        Cache::flush();

        $this->get(route('tarot.index'))->assertOk()->assertSee('ไพ่ดูคุณไสย');
        $this->cast($u, 'kunsai')->assertRedirect();
        $this->assertSame('kunsai', $this->sent[0]['spread_key']);
        $this->assertSame(401.0, (float) app(WalletService::class)->balance($u->fresh()), 'คุณไสย ฿99 เท่าบอท');
        $this->assertSame('tarot_kunsai', ChatOffers::for('kunsai')[0]['key']);
    }

    public function test_asking_whether_one_was_hexed_is_a_kunsai_reading_request(): void
    {
        $this->assertSame('kunsai', ChatReadingIntent::detect('ช่วยดูหน่อยว่าโดนของไหมคะ'));
        $this->assertSame('kunsai', ChatReadingIntent::detect('โดนคุณไสยหรือเปล่า'));
        $this->assertNull(ChatReadingIntent::detect('เคยได้ยินเรื่องมนต์ดำจากยาย เล่าให้ฟัง'), 'a story is not a request');
    }

    public function test_the_result_page_shows_cards_when_mae_mor_used_the_headings_and_prose_otherwise(): void
    {
        $u = $this->member();
        $this->cast($u, 'single')->assertRedirect();
        $reading = Reading::latest('id')->first();

        $this->actingAs($u)->get(route('tarot.show', $reading))
            ->assertOk()
            ->assertSee('แม่หมอฟันธง')
            ->assertSee('คำทำนายทีละใบ')
            ->assertSee('ลูกกำลังเริ่มต้นใหม่')
            ->assertDontSee('บทวิเคราะห์รวม');

        $reading->update(['result' => '**ภาพรวม** คำทำนายแบบเก่า']);
        $this->actingAs($u)->get(route('tarot.show', $reading))
            ->assertOk()->assertSee('บทวิเคราะห์รวม')->assertDontSee('แม่หมอฟันธง');
    }
}
