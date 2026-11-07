<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\TarotReadingCard;
use App\Models\User;
use App\Support\ReadingTheater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🎭 โรงละครคำทำนาย — ข้อมูลที่หน้าเว็บใช้เล่าทีละบท (App\Support\ReadingTheater)
 *
 * กติกาที่ล็อกไว้:
 *   - ลำดับบท: พื้นดวง (มีหัวข้อ 🌠 เท่านั้น) → กราฟ → ไพ่ทีละใบตามตำแหน่ง → สรุป → คุยต่อ
 *   - สีกราฟรายปีมาจากป้ายเดือนในคำทำนายเท่านั้น (ไม่แต่งคะแนน) · วงแหวน = เดือนดี n/12
 *   - เสียงรับเฉพาะสัญญา 23.2 ที่ถูกต้อง (same-origin, เวลาเรียงกัน) — ผิดสัญญา = เล่นแบบเงียบ
 *   - ราศีของกลุ่มดาวยึดชื่อที่คำทำนายพูด · ไม่พูด = คำนวณจากวันเกิดที่เก็บไว้
 *   - ปุ่มเปิดโรงละครเห็นเฉพาะเจ้าของคำทำนาย
 */
class ReadingTheaterTest extends TestCase
{
    use RefreshDatabase;

    private function card(string $slug, string $th): TarotCard
    {
        return TarotCard::create([
            'slug' => $slug, 'name_en' => Str::title($slug), 'name_th' => $th,
            'arcana' => 'major', 'suit' => 'major', 'number' => crc32($slug) % 22,
            'upright_meaning_th' => "ความหมายหัวตั้งของ{$th}", 'reversed_meaning_th' => "ความหมายกลับหัวของ{$th}",
            'active' => true,
        ]);
    }

    /** @param list<array{0:string,1:string,2:bool}> $cards [slug, label, reversed] */
    private function reading(string $type, string $result, array $cards, array $payload = [], ?User $owner = null): Reading
    {
        $owner ??= User::factory()->create();
        $reading = Reading::create([
            'user_id'       => $owner->id,
            'session_token' => (string) Str::uuid(),
            'type'          => $type,
            'payload'       => $payload + ['cost' => 0],
            'result'        => $result,
            'status'        => null,   // อ่านเสร็จแล้ว (ค่าเดิมของแถวที่ไม่ได้อ่านเบื้องหลัง)
        ]);
        foreach ($cards as $i => [$slug, $label, $reversed]) {
            TarotReadingCard::create([
                'reading_id'     => $reading->id,
                'tarot_card_id'  => (TarotCard::where('slug', $slug)->first() ?? $this->card($slug, 'ไพ่'.$slug))->id,
                'position'       => $i + 1,
                'position_label' => $label,
                'reversed'       => $reversed,
            ]);
        }

        return $reading->load('tarotCards.card');
    }

    private function threeCards(array $payload = [], string $birth = "## 🌠 พื้นดวงของลูก\nลูกเกิดใต้ราศีสิงห์ ใจกล้าและรักศักดิ์ศรี\n\n"): Reading
    {
        $result = $birth
            ."## 🃏 ใบที่ 1 · อดีต\nเรื่องเก่า **ปล่อยวาง** ได้แล้ว\n\n"
            ."## 🃏 ใบที่ 2 · ปัจจุบัน\nตอนนี้กำลังเริ่มต้นใหม่\n\n"
            ."## 🃏 ใบที่ 3 · อนาคต\nโอกาสใหม่จะเข้ามา\n\n"
            ."## 🎯 ฟันธง\nผล: ดีขึ้นชัดเจน\nเดินหน้าได้เลย\n\n"
            ."## 🧭 สิ่งที่ควรทำ\n- ตั้งใจทำงาน\n- ใจเย็น\n";

        return $this->reading('tarot_three', $result, [
            ['the-fool', 'อดีต', false],
            ['the-star', 'ปัจจุบัน', true],
            ['the-sun', 'อนาคต', false],
        ], $payload);
    }

    public function test_chapters_run_birth_graph_cards_summary_outro(): void
    {
        $t = ReadingTheater::build($this->threeCards());

        $this->assertSame(['birth', 'graph', 'card', 'card', 'card', 'summary', 'outro'], array_column($t['chapters'], 'kind'));
        $this->assertSame([1, 2, 3], array_values(array_filter(array_column($t['chapters'], 'pos'))));
        // ไพ่ใช้ข้อความหัวข้อ 🃏 ของตำแหน่งนั้น (markdown ถูกตัดเป็นข้อความล้วน)
        $this->assertSame('เรื่องเก่า ปล่อยวาง ได้แล้ว', $t['chapters'][2]['text']);
        $this->assertStringContainsString('(กลับหัว)', $t['chapters'][3]['title']);
        $this->assertStringContainsString('• ตั้งใจทำงาน', $t['chapters'][5]['text']);
        $this->assertSame(['label' => 'ดีขึ้นชัดเจน', 'tone' => $t['verdict']['tone']], $t['verdict']);
        $this->assertSame('row', $t['layout']);
        $this->assertSame('path', $t['graph']['kind']);
        $this->assertSame(1.0, $t['graph']['ring']['fill']);
        // ราศีจากที่คำทำนายพูดถึง
        $this->assertSame('leo', $t['zodiac']['slug']);
        $this->assertNull($t['voice']);
    }

    public function test_birth_chapter_is_skipped_when_reading_has_no_birth_section(): void
    {
        $t = ReadingTheater::build($this->threeCards(['birth_date' => '1990-08-10'], ''));

        $this->assertSame('graph', $t['chapters'][0]['kind']);
        $this->assertNotContains('birth', array_column($t['chapters'], 'kind'));
        // ไม่มีบทพื้นดวง = ไม่มีกลุ่มดาว (แม้จะมีวันเกิด)
        $this->assertNull($t['zodiac']);
    }

    public function test_zodiac_falls_back_to_stored_birth_date_when_text_names_no_sign(): void
    {
        $t = ReadingTheater::build($this->threeCards(['birth_date' => '1990-01-25'], "## 🌠 พื้นดวง\nลูกเป็นคนคิดไกล\n\n"));

        // ราศีแบบไทย (นิรายนะ) ชุดเดียวกับผังดวงของบอท — 25 ม.ค. สากลเป็นกุมภ์ แต่แบบไทยยังเป็นมังกร
        $this->assertSame('capricorn', $t['zodiac']['slug']);
        $this->assertSame('ราศีมังกร', $t['zodiac']['name']);
    }

    public function test_year_graph_uses_month_labels_only(): void
    {
        $tones = ['ดี', 'กลาง', 'ระวัง', 'ดี', 'ดี', 'กลาง', 'กลาง', 'ระวัง', 'ดี', 'กลาง', 'กลาง', 'ดี'];
        $months = ['ต.ค.', 'พ.ย.', 'ธ.ค.', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.'];
        $result = '';
        $cards = [];
        foreach ($tones as $i => $tone) {
            $result .= "## 📅 {$months[$i]} 2569 · {$tone}\nธีม: เดือนที่ ".($i + 1)."\nรายละเอียดเดือนนี้\n\n";
            $cards[] = ['card-'.$i, 'เดือนที่ '.($i + 1), $i % 2 === 1];
        }
        $result .= "## 🎯 ฟันธง\nผล: ปีแห่งการเติบโต\nโดยรวมดี\n";

        $t = ReadingTheater::build($this->reading('tarot_year', $result, $cards));

        $this->assertSame('wheel', $t['layout']);
        $this->assertSame('year', $t['graph']['kind']);
        $this->assertCount(12, $t['graph']['points']);
        $this->assertSame(['good', 'neutral', 'caution'], array_slice(array_column($t['graph']['points'], 'tone'), 0, 3));
        $this->assertSame(['fill' => round(5 / 12, 3), 'caption' => 'เดือนดี 5/12'], $t['graph']['ring']);
        // บทไพ่รายปี: ป้ายสั้น = ชื่อเดือนย่อ · โทน = ป้ายเดือน · ข้อความ = ธีม + เนื้อหาเดือนนั้น
        $cardChapters = array_values(array_filter($t['chapters'], fn ($c) => $c['kind'] === 'card'));
        $this->assertSame('ต.ค.', $cardChapters[0]['short']);
        $this->assertSame('caution', $cardChapters[2]['tone']);
        $this->assertStringStartsWith('เดือนที่ 3', $cardChapters[2]['text']);
        $this->assertStringContainsString('เดือนดี 5', $t['chapters'][0]['text']);
    }

    public function test_valid_voice_contract_maps_anchors_and_tones(): void
    {
        $t = ReadingTheater::build($this->threeCards(['voice' => [
            'status'   => 'ready',
            'provider' => 'elevenlabs',
            'persona'  => 'maemor',
            'url'      => '/readings/1/voice.mp3',
            'duration' => 60,
            'chapters' => [
                ['anchor' => 'birth', 'start' => 0, 'end' => 8, 'say' => '[softly] ลูกเกิดใต้ราศีสิงห์'],
                ['anchor' => 'card:1', 'start' => 8, 'end' => 20, 'say' => 'ใบแรก', 'tone' => 'caution', 'energy' => 2],
                ['anchor' => 'card:9', 'start' => 20, 'end' => 30, 'say' => 'ไม่มีใบนี้'],
                ['anchor' => 'card:2', 'start' => 10, 'end' => 12, 'say' => 'เวลาย้อน'],
                ['anchor' => 'card:3', 'start' => 30, 'end' => 'x', 'say' => 'ยังไม่มีเวลา'],
                ['anchor' => 'verdict', 'start' => 30, 'end' => 55, 'say' => 'สรุปคือดี'],
            ],
        ]]));

        $v = $t['voice'];
        $this->assertSame('/readings/1/voice.mp3', $v['url']);
        $this->assertSame(['birth', 'card', 'summary', 'summary'], array_column($v['chapters'], 'kind'));
        $this->assertSame('ลูกเกิดใต้ราศีสิงห์', $v['chapters'][0]['text']);   // แท็กอารมณ์ถูกตัด
        $this->assertSame(1, $v['chapters'][1]['pos']);
        $this->assertNull($v['chapters'][2]['pos']);                          // ใบที่ไม่มีบนโต๊ะ → สรุป
        // ไพ่แบบไม่ใช่รายปี: โทน/พลังจากบทเสียงขึ้นกราฟ
        $this->assertSame('caution', $t['graph']['points'][0]['tone']);
        $this->assertSame(0.4, $t['graph']['points'][0]['h']);
    }

    public function test_voice_outside_contract_falls_back_to_silent_mode(): void
    {
        $bad = [
            ['status' => 'none', 'url' => '/readings/1/voice.mp3', 'chapters' => [['anchor' => 'graph', 'start' => 0, 'end' => 5]]],
            ['status' => 'ready', 'url' => 'https://cdn.example.com/v.mp3', 'chapters' => [['anchor' => 'graph', 'start' => 0, 'end' => 5]]],
            ['status' => 'ready', 'url' => '//cdn.example.com/v.mp3', 'chapters' => [['anchor' => 'graph', 'start' => 0, 'end' => 5]]],
            ['status' => 'ready', 'url' => '/readings/1/voice.mp3', 'chapters' => [['anchor' => 'graph', 'start' => 5, 'end' => 5]]],
            'ready',
        ];
        foreach ($bad as $voice) {
            $t = ReadingTheater::build($this->threeCards(['voice' => $voice]));
            $this->assertNull($t['voice'], json_encode($voice));
            $this->assertNotEmpty($t['chapters']);
        }
    }

    public function test_stage_art_ships_for_every_room_and_backs_cards_without_admin_back(): void
    {
        foreach (['maemor', 'busaba', 'kunsai'] as $persona) {
            $this->assertFileExists(public_path("images/juntra/theater/{$persona}-bg.webp"));
        }
        $t = ReadingTheater::build($this->threeCards());
        $this->assertStringContainsString('/images/juntra/theater/' . $t['persona'] . '-bg.webp', (string) $t['art']['backdrop']);
        foreach (['altar', 'moon', 'cardBack'] as $key) {
            $this->assertNotNull($t['art'][$key], $key);
        }
        // ไม่มีหลังไพ่จากแอดมิน → ใช้ลายหลังไพ่ของโรงละคร
        $this->assertSame($t['art']['cardBack'], $t['back']);
    }

    /** กราฟชีวิตจาก Thaiprompt (ย่อ) — เก็บคำขอที่ส่งไว้ตรวจ */
    private function fakeLifeGraph(int $status = 200): \ArrayObject
    {
        Setting::put('thaiprompt_base_url', 'https://tp.test', 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'cid', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'secret', 'thaiprompt', true);
        $sent = new \ArrayObject;
        Http::fake(function (HttpRequest $request) use ($status, $sent) {
            if (str_contains($request->url(), '/oauth/token')) {
                return Http::response(['access_token' => 'srv', 'expires_in' => 3600]);
            }
            $sent[] = $request->data();

            return Http::response(['success' => $status === 200, 'data' => $status === 200 ? [
                'max_age' => 100,
                'current' => ['age' => 36, 'age_decimal' => 36.4, 'period' => ['planet_th' => 'พฤหัสบดี', 'from_age' => 33, 'to_age' => 39, 'tone' => 'ดี'], 'sub' => null],
                'years' => [[0, 50], [1, 52]],
                'periods' => [['planet' => 5, 'planet_th' => 'พฤหัสบดี', 'from_age' => 33, 'to_age' => 39, 'hex' => '#f08a24', 'tone' => 'ดี', 'text' => 'ได้ลาภ']],
                'peaks' => [
                    ['age' => 27, 'year_be' => 2560, 'score' => 80, 'main_planet_th' => 'ศุกร์', 'sub_planet_th' => 'พุธ'],
                    ['age' => 12, 'year_be' => 2545, 'score' => 78, 'main_planet_th' => 'ศุกร์', 'sub_planet_th' => 'ศุกร์'],
                ],
                'lows' => [], 'card' => ['พฤหัสบดีเสวยอายุ (ช่วงดี): ได้ลาภ'], 'notes' => [],
            ] : null], $status);
        });

        return $sent;
    }

    public function test_life_graph_from_thaiprompt_drives_the_graph_chapter(): void
    {
        $sent = $this->fakeLifeGraph();
        $owner = User::factory()->create();
        $owner->profile()->create(['birth_date' => '1990-05-16', 'birth_time' => '21:30', 'birth_place' => 'กรุงเทพ']);
        $reading = $this->reading('tarot_three', "## 🃏 ใบที่ 1 · อดีต\nเรื่องเก่า\n", [['the-fool', 'อดีต', false]], [], $owner);

        $t = ReadingTheater::build($reading);

        $this->assertSame(36, $t['life']['current']['age']);
        $graph = collect($t['chapters'])->firstWhere('kind', 'graph');
        $this->assertSame('กราฟชีวิตตามตำรามหาทักษา', $graph['title']);
        $this->assertStringContainsString('ดาวเสวยพฤหัสบดี อายุ 33–39 ปี (ช่วงดี)', $graph['text']);
        // จุดเด่นมาเรียงตามคะแนน แต่บรรยายเรียงตามอายุ
        $this->assertStringContainsString('ช่วงรุ่งของชีวิต: อายุ 12 · อายุ 27', $graph['text']);
        // วันเกิดของเจ้าของบัญชีเอง → ส่งเวลา/ที่เกิดไปด้วย
        $this->assertSame(['birth_date' => '1990-05-16', 'birth_time' => '21:30', 'birth_place' => 'กรุงเทพ'], $sent[0]);

        // ครั้งต่อไปในวันเดียวกันใช้ผลที่จำไว้ ไม่ยิงซ้ำ
        ReadingTheater::build($reading);
        $this->assertCount(1, $sent);

        $this->get(route('tarot.show', $reading))->assertDontSee('rt-life', false); // ไม่ใช่เจ้าของ = ไม่มีโรงละคร
        $this->actingAs($owner)->get(route('tarot.show', $reading))->assertSee('rt-life-chart', false)->assertSee('ตอนนี้อายุ', false);
    }

    public function test_someone_elses_birth_date_never_gets_the_owners_birth_time(): void
    {
        $sent = $this->fakeLifeGraph();
        $owner = User::factory()->create();
        $owner->profile()->create(['birth_date' => '1990-05-16', 'birth_time' => '21:30', 'birth_place' => 'กรุงเทพ']);
        $reading = $this->reading('tarot_three', "## 🃏 ใบที่ 1 · อดีต\nเรื่องเก่า\n", [['the-fool', 'อดีต', false]], ['birth_date' => '1975-11-23'], $owner);

        ReadingTheater::build($reading);

        $this->assertSame(['birth_date' => '1975-11-23'], $sent[0]);
    }

    public function test_life_graph_failure_keeps_the_card_path_graph_and_is_not_retried_every_view(): void
    {
        $sent = $this->fakeLifeGraph(503);
        $reading = $this->threeCards(['birth_date' => '1990-05-16']);

        $t = ReadingTheater::build($reading);
        ReadingTheater::build($reading);

        $this->assertNull($t['life']);
        $this->assertSame('เส้นทางของไพ่', collect($t['chapters'])->firstWhere('kind', 'graph')['title']);
        $this->assertCount(1, $sent);
    }

    public function test_unavailable_while_reading_or_failed(): void
    {
        $r = $this->threeCards();
        $this->assertTrue(ReadingTheater::available($r));

        $r->status = Reading::STATUS_WORKING;
        $this->assertFalse(ReadingTheater::available($r));
        $r->status = Reading::STATUS_FAILED;
        $this->assertFalse(ReadingTheater::available($r));
    }

    public function test_launch_button_only_for_reading_owner(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create();
        $r = $this->threeCards();
        $r->update(['user_id' => $owner->id, 'shared_public' => true]);

        $this->actingAs($owner)->get(route('tarot.show', $r))
            ->assertOk()
            ->assertSee('ดูแบบโรงละคร')
            ->assertSee('readingTheater(', false);

        $this->actingAs(User::factory()->create())->get(route('tarot.show', $r))
            ->assertOk()
            ->assertDontSee('ดูแบบโรงละคร');
    }

    public function test_history_links_to_theater_for_finished_tarot_readings(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create();
        $r = $this->threeCards();
        $r->update(['user_id' => $owner->id]);

        $this->actingAs($owner)->get(route('account.history'))
            ->assertOk()
            ->assertSee(route('tarot.show', ['reading' => $r, 'theater' => 1]), false);
    }
}
