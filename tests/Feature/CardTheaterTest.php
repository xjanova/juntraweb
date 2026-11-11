<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\CardTheater;
use App\Support\ChatRooms;
use App\Support\TarotSpreads;
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

        // ไพ่ 3 ใบเป็นของห้องบุษบา — เริ่มไพ่ห้องแม่หมอค้างไว้ ห้องล็อกกันไว้ (ReadingRoomLock) จึงเริ่มใหม่ใน session ใหม่
        $this->flushSession();
        $this->actingAs($u)->post('/tarot/begin', ['spread' => 'three']);
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
        // กำลังอ่าน = ยังไม่มีปุ่มดูพิธีซ้ำ · ยังไม่มีประโยคปิดท้าย
        $res->assertDontSee('data-ct-open', false);
        $this->assertNull($cfg['voiceEnd']);
        $this->assertIsArray($cfg['room']['music']);
        // ยังอ่านไม่เสร็จ = ยังไม่มีเรื่องให้เล่า
        $this->assertNull($cfg['story']);
    }

    public function test_after_the_last_card_the_oracle_tells_the_reading_as_one_story(): void
    {
        $u = $this->member();
        $reading = $this->readingFor($u, null);

        $cfg = $this->configFrom($this->actingAs($u)->get(route('tarot.show', $reading))->getContent());
        $story = $cfg['story'];
        $this->assertNotNull($story);
        $this->assertNull($story['voice']);   // ไม่มีเสียงคำทำนาย = เล่าแบบตัวหนังสือ
        // บทของไพ่ชี้ไพ่บนโต๊ะเรียงตามตำแหน่ง (0 = ใบแรก) · ทุกบทมีคำเล่า
        $this->assertSame([0, 1, 2], array_values(array_filter(array_column($story['chapters'], 'card'), fn ($c) => $c !== null)));
        foreach ($story['chapters'] as $ch) {
            $this->assertNotSame('', $ch['text']);
        }
    }

    public function test_a_voiced_reading_tells_the_story_in_time_with_the_voice(): void
    {
        $u = $this->member();
        $reading = $this->readingFor($u, null);
        $reading->update(['payload' => $reading->payload + ['voice' => [
            'status' => 'ready', 'url' => '/media/voice/r1.mp3', 'duration' => 30,
            'chapters' => [
                ['anchor' => 'general', 'start' => 0, 'end' => 8, 'say' => 'เปิดเรื่อง'],
                ['anchor' => 'card:2', 'start' => 8, 'end' => 20, 'say' => 'ใบที่สอง <b>กลับหัว</b>'],
                ['anchor' => 'card:9', 'start' => 20, 'end' => 30, 'say' => 'ไม่มีใบนี้'],
            ],
        ]]]);

        $story = $this->configFrom($this->actingAs($u)->get(route('tarot.show', $reading))->getContent())['story'];
        $this->assertSame('/media/voice/r1.mp3', $story['voice']);
        $this->assertSame([null, 1, null], array_column($story['chapters'], 'card'));
        $this->assertEquals([8, 20], [$story['chapters'][1]['start'], $story['chapters'][1]['end']]);
        $this->assertStringNotContainsString('<b>', $story['chapters'][1]['text']);
    }

    public function test_a_finished_reading_tells_the_theater_what_the_oracle_says_at_the_end(): void
    {
        $u = $this->member();
        $reading = $this->readingFor($u, null);

        $cfg = $this->configFrom($this->actingAs($u)->get(route('tarot.show', $reading))->getContent());
        // ข้อความคำทำนายไม่มีส่วนฟันธง = ชวนถามต่อ (ตรงกับ OracleMedia::readingCues)
        $this->assertSame('followup_ask', $cfg['voiceEnd']);
        // เพลงของห้อง = เฉพาะไฟล์ที่มีจริง (เป็น URL เต็ม)
        foreach ($cfg['room']['music'] as $url) {
            $this->assertStringContainsString('/media/oracle/maemor/music/', $url);
        }
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

    /**
     * 🌸 (2026-11-11) โรงละครของทุกแบบไพ่ = ห้องของคนอ่าน
     *   ไม่ถึง 10 ใบ → บุษบา · เซลติก/12 เดือน → แม่หมอ · คุณไสย → ห้องคุณไสย · จำนวนช่องบนโต๊ะ = จำนวนไพ่จริง
     */
    public function test_every_spread_plays_in_its_readers_room_with_the_right_card_count(): void
    {
        foreach (TarotSpreads::keys() as $key) {
            $n = TarotSpreads::cardCount($key);
            $want = match (true) {
                $key === 'kunsai' => 'kunsai',
                $n >= 10          => 'maemor',
                default           => 'busaba',
            };
            $cfg = CardTheater::forPick($key, collect(), ['cost' => 0]);
            $this->assertSame($want, $cfg['room']['key'], "{$key} ({$n} ใบ) ผิดห้อง");
            $this->assertCount($n, $cfg['spread']['labels'], "{$key}: จำนวนช่องไม่ตรงจำนวนไพ่");
            $this->assertSame(ChatRooms::name($want), $cfg['room']['name']);
        }
    }

    /** ไพ่ 1 / 3 / 5 ใบของบุษบา: ห้อง ท่าวาง และเสียงตอนตั้งจิต/เลือกครบเป็นของแบบนั้น */
    public function test_busaba_pick_theater_for_one_three_and_five_cards(): void
    {
        $this->deck();
        $u = $this->member();

        foreach (['single' => [1, 'row'], 'three' => [3, 'row'], 'quick' => [3, 'row'], 'love' => [5, 'arch'], 'career' => [5, 'arch'], 'decision' => [5, 'arch']] as $spread => [$n, $layout]) {
            $this->actingAs($u)->post('/tarot/begin', ['spread' => $spread]);
            $res = $this->get('/tarot/pick')->assertOk();
            $html = $res->getContent();
            $cfg = $this->configFrom($html);

            $this->assertSame('busaba', $cfg['room']['key'], $spread);
            $this->assertCount($n, $cfg['spread']['labels'], $spread);
            $this->assertSame($layout, $cfg['spread']['layout'], $spread);
            $this->assertStringContainsString("shuffle_focus--{$spread}.mp3", $html, "{$spread}: เสียงตั้งจิตไม่ตรงแบบไพ่");
            $this->assertStringContainsString("pick_complete--{$spread}.mp3", $html, "{$spread}: เสียงเลือกครบไม่ตรงแบบไพ่");
            // ปุ่มเสียงเรียกชื่อบุษบา ไม่ใช่แม่หมอ
            $res->assertSee('aria-label="เปิดเสียงบุษบา"', false)->assertDontSee('เปิดเสียงแม่หมอ', false);
        }
    }

    /** หน้าผลไพ่ 5 ใบของบุษบา: ฟันธงใช้เสียงเฉพาะเรื่อง (ความรัก) · ถามต่อพาเข้าห้องบุษบา */
    public function test_busaba_result_uses_the_spread_verdict_voice_and_hands_off_to_her_room(): void
    {
        $u = $this->member();
        $reading = $this->readingFor($u, null, 'love');
        $reading->update(['payload' => ['positions' => ['อดีต', 'ปัจจุบัน', 'อนาคต'], 'room' => 'busaba']]);

        $res = $this->actingAs($u)->get(route('tarot.show', $reading))->assertOk();
        $html = $res->getContent();
        $this->assertSame('busaba', $this->configFrom($html)['room']['key']);
        $this->assertStringContainsString('verdict_good--love.mp3', $html);
        $this->assertStringContainsString('verdict_caution--love.mp3', $html);
        $this->assertSame('busaba', ChatRooms::roomForSpread('love'));
        $res->assertSee('ถามบุษบา', false);
    }
}
