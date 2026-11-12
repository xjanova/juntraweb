<?php

namespace Tests\Feature\Api;

use App\Models\DailyCheckin;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Models\TarotReadingCard;
use App\Models\User;
use App\Support\DailyLuck;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🌙 (2026-11-12) แอพ: เช็กอินรับดวง + สมุดสะสมไพ่ — กติกาเดียวกับหน้าเว็บ (DailyLuck · CardCollection)
 *
 * ล็อกไว้:
 *  - ต้องล็อกอิน · ดวงโชว์เมื่อกดรับแล้วเท่านั้น · กดซ้ำได้แถวเดิม (วันละแถว ตามเวลาไทย)
 *  - วันต่อเนื่องต่อจากเมื่อวาน · ขาดวัน = ดับแล้วเริ่ม 1 ใหม่
 *  - สมุดนับเฉพาะไพ่จากคำทำนายของตัวเองที่ไม่ล้มเหลว · นับหัวกลับแยก · เรียงตามสำรับ
 */
class AccountEngagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $when): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($when, DailyLuck::TZ));
    }

    public function test_every_endpoint_needs_a_login(): void
    {
        $this->getJson('/api/v1/account/daily-luck')->assertUnauthorized();
        $this->postJson('/api/v1/account/checkin')->assertUnauthorized();
        $this->getJson('/api/v1/account/collection')->assertUnauthorized();
        $this->assertSame(0, DailyCheckin::count());
    }

    public function test_the_luck_stays_hidden_until_the_member_checks_in(): void
    {
        $this->at('2026-11-07 09:00');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/account/daily-luck')->assertOk()
            ->assertExactJson(['data' => [
                'checked_in'     => false,
                'streak'         => 0,
                'next_milestone' => ['days' => 3, 'label' => DailyLuck::MILESTONES[3], 'left' => 3],
                'earned'         => [],
                'reading'        => null,
            ]]);
        $this->assertSame(0, DailyCheckin::count(), 'แค่เปิดดูต้องไม่นับเป็นเช็กอิน');
    }

    public function test_checking_in_twice_is_one_row_and_both_calls_answer_the_same_shape(): void
    {
        $this->at('2026-11-07 09:00');
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $luck = DailyLuck::reading($u);

        $first = $this->postJson('/api/v1/account/checkin')->assertOk()
            ->assertJsonPath('data.checked_in', true)
            ->assertJsonPath('data.streak', 1)
            ->assertJsonPath('data.new', true)
            ->assertJsonPath('data.reading', $luck)
            ->json('data');
        $this->assertSame(['checked_in', 'streak', 'next_milestone', 'earned', 'reading', 'new'], array_keys($first));

        $this->at('2026-11-07 22:45');
        $second = $this->postJson('/api/v1/account/checkin')->assertOk()->assertJsonPath('data.new', false)->json('data');
        unset($first['new'], $second['new']);
        $this->assertSame($first, $second, 'กดซ้ำวันเดิมต้องได้ผลเดิม');

        $this->assertSame(1, DailyCheckin::count());
        $this->getJson('/api/v1/account/daily-luck')->assertOk()
            ->assertJsonPath('data.checked_in', true)
            ->assertJsonPath('data.reading', $luck);
    }

    public function test_the_streak_continues_from_yesterday_and_breaks_after_a_gap(): void
    {
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        DailyCheckin::create(['user_id' => $u->id, 'day' => '2026-11-06', 'streak' => 2]);

        // เมื่อวานกดไว้ → วันนี้ยังไม่กด วันต่อเนื่องยังไม่ดับ
        $this->at('2026-11-07 08:00');
        $this->getJson('/api/v1/account/daily-luck')->assertOk()
            ->assertJsonPath('data.checked_in', false)
            ->assertJsonPath('data.streak', 2)
            ->assertJsonPath('data.reading', null);

        $this->postJson('/api/v1/account/checkin')->assertOk()
            ->assertJsonPath('data.streak', 3)
            ->assertJsonPath('data.earned', [['days' => 3, 'label' => DailyLuck::MILESTONES[3]]])
            ->assertJsonPath('data.next_milestone', ['days' => 7, 'label' => DailyLuck::MILESTONES[7], 'left' => 4]);

        // ขาดวันที่ 8 → วันที่ 9 ช่วงหัวค่ำเวลาไทย (วัน UTC กับวันไทยห่างกันมากสุด) วันต่อเนื่องต้องดับ
        $this->at('2026-11-09 20:00');
        $this->getJson('/api/v1/account/daily-luck')->assertOk()
            ->assertJsonPath('data.streak', 0)
            ->assertJsonPath('data.earned', []);
        $this->postJson('/api/v1/account/checkin')->assertOk()
            ->assertJsonPath('data.streak', 1)
            ->assertJsonPath('data.new', true);

        $this->assertSame(['2026-11-06', '2026-11-07', '2026-11-09'],
            DailyCheckin::orderBy('day')->get()->map(fn ($r) => $r->day->toDateString())->all());
    }

    public function test_the_collection_counts_only_your_own_successful_readings(): void
    {
        $cards = collect(range(1, 4))->map(fn ($i) => TarotCard::create([
            'slug' => "c{$i}", 'name_en' => "C{$i}", 'name_th' => "ไพ่ทดสอบ{$i}", 'arcana' => 'major', 'suit' => 'major', 'number' => $i,
            'image_path' => $i === 1 ? 'images/cards/c1.webp' : null,
            'keywords_th' => '-', 'upright_meaning_th' => '-', 'reversed_meaning_th' => '-', 'active' => true,
        ]));
        $retired = TarotCard::create([
            'slug' => 'old', 'name_en' => 'Old', 'name_th' => 'ไพ่ที่ปิดไว้', 'arcana' => 'major', 'suit' => 'major', 'number' => 9,
            'keywords_th' => '-', 'upright_meaning_th' => '-', 'reversed_meaning_th' => '-', 'active' => false,
        ]);
        $u = User::factory()->create();
        $other = User::factory()->create();

        $reading = fn (User $owner, ?string $status = null) => Reading::create([
            'user_id' => $owner->id, 'session_token' => 'tok-'.uniqid(), 'type' => 'tarot_three',
            'question' => 'งานจะดีไหม', 'payload' => [], 'result' => 'ไพ่บอกว่าดี', 'status' => $status,
        ]);
        $deal = fn (Reading $r, TarotCard $c, int $pos, bool $rev = false) => TarotReadingCard::create([
            'reading_id' => $r->id, 'tarot_card_id' => $c->id, 'position' => $pos, 'position_label' => 'ตำแหน่ง', 'reversed' => $rev,
        ]);
        $ok = $reading($u);
        $deal($ok, $cards[0], 1);
        $deal($ok, $cards[0], 2, true);
        $deal($ok, $cards[1], 3, true);
        $deal($reading($u, Reading::STATUS_FAILED), $cards[2], 1);   // อ่านไม่สำเร็จ ไม่นับ
        $deal($reading($other), $cards[3], 1);                       // ของคนอื่น ไม่นับ
        $deal($reading($u), $retired, 1);                            // ไพ่ที่ปิดไว้ ไม่อยู่ในสมุด

        Sanctum::actingAs($u);
        $res = $this->getJson('/api/v1/account/collection')->assertOk()
            ->assertJsonPath('data.found', 2)
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.favourite', ['slug' => 'c1', 'name_th' => 'ไพ่ทดสอบ1', 'count' => 2]);

        $got = $res->json('data.cards');
        $this->assertSame(['c1', 'c2', 'c3', 'c4'], array_column($got, 'slug'), 'เรียงตามสำรับ (id) เหมือนหน้าเว็บ');
        $this->assertSame(['slug', 'name_th', 'name_en', 'image_url', 'count', 'reversed_count', 'found'], array_keys($got[0]));
        $this->assertSame([2, 1, 0, 0], array_column($got, 'count'));
        $this->assertSame([1, 1, 0, 0], array_column($got, 'reversed_count'));
        $this->assertSame([true, true, false, false], array_column($got, 'found'));
        $this->assertSame('ไพ่ทดสอบ1', $got[0]['name_th']);
        $this->assertSame('C1', $got[0]['name_en']);
        $this->assertSame(asset('images/cards/c1.webp'), $got[0]['image_url']);
        foreach ($got as $card) {
            $this->assertMatchesRegularExpression('#^https?://#', $card['image_url'], 'ภาพต้องเป็น URL เต็ม');
            $this->assertSame($cards->firstWhere('slug', $card['slug'])->imageUrl(), $card['image_url'], 'ภาพเดียวกับหน้าสมุดบนเว็บ');
        }
    }

    public function test_an_empty_collection_has_no_favourite(): void
    {
        TarotCard::create([
            'slug' => 'c1', 'name_en' => 'C1', 'name_th' => 'ไพ่ทดสอบ1', 'arcana' => 'major', 'suit' => 'major', 'number' => 1,
            'keywords_th' => '-', 'upright_meaning_th' => '-', 'reversed_meaning_th' => '-', 'active' => true,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/account/collection')->assertOk()
            ->assertJsonPath('data.found', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.favourite', null)
            ->assertJsonPath('data.cards.0.found', false)
            ->assertJsonPath('data.cards.0.count', 0);
    }
}
