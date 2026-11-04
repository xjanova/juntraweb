<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\CardTheater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🃏 (2026-11-05) โรงละครเปิดไพ่ — แทนหน้าพัดไพ่ HTML เดิม
 *
 * ล็อกไว้:
 *  - หน้าเลือกไพ่ = โรงละคร (ไม่มีพัดเดิม) · สำรับคือ id ไพ่จริงที่เปิดใช้งาน · ฟอร์มเดิมของ tarot.cast ยังอยู่ครบ
 *  - ห้อง/ฉากโต๊ะตรงผู้พยากรณ์ของไพ่ชุดนั้น · ยอดไม่พอ = ปุ่มเติมเงิน
 *  - หน้าผล: เฉพาะเจ้าของ · ไม่เปิดให้รายการที่อ่านไม่สำเร็จ · ส่งภาพ/ชื่อ/กลับหัวของไพ่ที่เปิดได้จริง
 *  - ท่าวางไพ่ของทุกแพ็กเกจ
 */
class CardTheaterTest extends TestCase
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

    private function deck(int $n = 12): void
    {
        for ($i = 0; $i < $n; $i++) {
            TarotCard::create([
                'slug' => "card-{$i}", 'name_en' => "Card {$i}", 'name_th' => "ไพ่ใบที่ {$i}", 'arcana' => 'major', 'suit' => 'major', 'number' => $i,
                'keywords_th' => "คำสำคัญ {$i}", 'upright_meaning_th' => 'ความหมายตั้ง', 'reversed_meaning_th' => 'ความหมายกลับหัว', 'active' => true,
            ]);
        }
        TarotCard::create([
            'slug' => 'off', 'name_en' => 'Off', 'name_th' => 'ปิดอยู่', 'arcana' => 'major', 'suit' => 'major', 'number' => 99,
            'keywords_th' => '-', 'upright_meaning_th' => '-', 'reversed_meaning_th' => '-', 'active' => false,
        ]);
    }

    private function member(float $credit = 500): User
    {
        $u = User::factory()->create(['thaiprompt_token' => null]);
        if ($credit > 0) {
            app(WalletService::class)->credit($u, $credit, 'seed');
        }

        return $u;
    }

    /** @return array<string, mixed> */
    private function configFrom(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" data-ct-config>(.*?)</script>#s', $html, $m), 'ไม่พบ config ของโรงละคร');

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_pick_page_is_the_theater_with_the_real_deck_and_the_old_cast_form(): void
    {
        $this->deck();
        $u = $this->member();
        $this->actingAs($u)->post('/tarot/begin', ['spread' => 'three', 'question' => 'งาน<b>ใหม่</b>']);

        $res = $this->get('/tarot/pick')->assertOk()
            ->assertSee('data-card-theater', false)
            ->assertDontSee('cardFan', false)
            ->assertSee('action="' . route('tarot.cast') . '"', false)
            ->assertSee('name="_idem"', false)
            ->assertSee('name="spread" value="three"', false);

        $cfg = $this->configFrom($res->getContent());
        $this->assertSame('pick', $cfg['mode']);
        $this->assertCount(12, $cfg['deck']);
        $this->assertNotContains(TarotCard::where('slug', 'off')->value('id'), $cfg['deck']);
        $this->assertCount(3, $cfg['spread']['labels']);
        $this->assertSame('row', $cfg['spread']['layout']);
        $this->assertTrue($cfg['enough']);
        // คำถามของลูกค้าไม่หลุดเป็น HTML (JSON_HEX_TAG)
        $this->assertStringNotContainsString('<b>ใหม่</b>', $res->getContent());
    }

    public function test_the_room_scene_follows_the_spread_owner(): void
    {
        $this->deck();
        $this->actingAs($this->member())->post('/tarot/begin', ['spread' => 'kunsai']);

        $cfg = $this->configFrom($this->get('/tarot/pick')->getContent());
        $this->assertSame('kunsai', $cfg['room']['key']);
        $this->assertStringEndsWith('images/juntra/theater/plate-kunsai.webp', $cfg['room']['plate']['src']);
        $this->assertSame('shrine', $cfg['spread']['layout']);
        $this->assertCount(10, $cfg['spread']['labels']);
    }

    public function test_not_enough_credit_offers_a_top_up(): void
    {
        $this->deck();
        $this->actingAs($this->member(0))->post('/tarot/begin', ['spread' => 'celtic']);

        $res = $this->get('/tarot/pick')->assertOk()->assertSee('ยอดไม่พอ');
        $cfg = $this->configFrom($res->getContent());
        $this->assertFalse($cfg['enough']);
        $this->assertSame(route('wallet.topup'), $cfg['topupUrl']);
        $this->assertSame('celtic', $cfg['spread']['layout']);
    }

    public function test_the_birth_date_field_is_only_on_spreads_that_use_it(): void
    {
        $this->deck();
        $u = $this->member();
        $this->actingAs($u)->post('/tarot/begin', ['spread' => 'celtic']);
        $this->get('/tarot/pick')->assertSee('name="birth_date"', false);

        $this->post('/tarot/begin', ['spread' => 'three']);
        $this->get('/tarot/pick')->assertDontSee('name="birth_date"', false);
    }

    private function readingFor(User $u, ?string $status, string $spread = 'three'): Reading
    {
        $this->deck(3);
        $reading = Reading::create([
            'user_id' => $u->id, 'session_token' => (string) Str::uuid(), 'type' => "tarot_{$spread}",
            'payload' => ['positions' => ['อดีต', 'ปัจจุบัน', 'อนาคต'], 'room' => 'maemor'],
            'result' => $status === null ? 'คำทำนาย' : null, 'status' => $status,
        ]);
        foreach (TarotCard::where('active', true)->orderBy('id')->get() as $i => $card) {
            $reading->tarotCards()->create(['tarot_card_id' => $card->id, 'position' => $i + 1, 'position_label' => ['อดีต', 'ปัจจุบัน', 'อนาคต'][$i], 'reversed' => $i === 1]);
        }

        return $reading;
    }

    public function test_the_result_page_gives_the_owner_the_real_cards(): void
    {
        $u = $this->member();
        $reading = $this->readingFor($u, Reading::STATUS_PENDING);

        $res = $this->actingAs($u)->get(route('tarot.show', $reading))->assertOk()->assertSee('data-card-theater', false);
        $cfg = $this->configFrom($res->getContent());
        $this->assertSame('result', $cfg['mode']);
        $this->assertSame('reading', $cfg['status']);
        $this->assertSame(route('tarot.status', $reading), $cfg['statusUrl']);
        $this->assertSame(['อดีต', 'ปัจจุบัน', 'อนาคต'], $cfg['spread']['labels']);
        $this->assertSame([false, true, false], array_column($cfg['cards'], 'reversed'));
        $this->assertSame('ไพ่ใบที่ 0', $cfg['cards'][0]['name']);
        $this->assertSame('คำสำคัญ 0', $cfg['cards'][0]['meaning']);
        $this->assertSame('ความหมายกลับหัว', $cfg['cards'][1]['meaning']);
        // กำลังอ่าน = ยังไม่มีปุ่มดูพิธีซ้ำ
        $res->assertDontSee('data-ct-open', false);
    }

    public function test_a_finished_reading_has_the_replay_button_and_strangers_get_no_theater(): void
    {
        $u = $this->member();
        $reading = $this->readingFor($u, null);
        $reading->update(['shared_public' => true]);

        $this->actingAs($u)->get(route('tarot.show', $reading))->assertSee('data-ct-open', false);
        $this->actingAs($this->member())->get(route('tarot.show', $reading))->assertOk()->assertDontSee('data-card-theater', false);
    }

    public function test_a_failed_reading_gets_no_theater(): void
    {
        $u = $this->member();
        $reading = $this->readingFor($u, Reading::STATUS_FAILED);

        $this->actingAs($u)->get(route('tarot.show', $reading))->assertOk()->assertDontSee('data-card-theater', false);
    }

    public function test_layouts_for_every_spread_size(): void
    {
        $this->assertSame('row', CardTheater::layout('single', 1));
        $this->assertSame('row', CardTheater::layout('three', 3));
        $this->assertSame('arch', CardTheater::layout('love', 5));
        $this->assertSame('grid', CardTheater::layout('nine', 9));
        $this->assertSame('celtic', CardTheater::layout('celtic', 10, 'celtic'));
        $this->assertSame('shrine', CardTheater::layout('kunsai', 10, 'celtic'));
        $this->assertSame('year', CardTheater::layout('year', 12));
        $this->assertSame('rows', CardTheater::layout('x', 7));
    }
}
