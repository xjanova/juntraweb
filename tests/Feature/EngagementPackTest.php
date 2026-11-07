<?php

namespace Tests\Feature;

use App\Models\DailyCheckin;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Models\TarotReadingCard;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\DailyLuck;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🌟 (2026-11-07) ลูกเล่นชุดใหม่ — รีวิวหลังจบคำทำนาย · แชร์ลิงก์ · เช็กอินรับดวง · สมุดสะสมไพ่ · ป๊อปอัปมีอะไรใหม่
 *
 * ล็อกไว้:
 *  - รีวิว/แชร์ได้เฉพาะเจ้าของ และคำทำนายต้องอ่านเสร็จ · หนึ่งคำทำนายหนึ่งรีวิว · แก้แล้วต้องอนุมัติใหม่
 *  - รีวิวลูกค้าขึ้นหน้าแรกเมื่อ "ลูกค้ายอม + แอดมินอนุมัติ" ทั้งคู่ · ชื่อโชว์แค่คำแรก ไม่โชว์อีเมล
 *  - เช็กอินวันละครั้ง (เวลาไทย) · ต่อเนื่อง +1 · ขาดวัน = เริ่มใหม่ · ดวงวันเดิมได้ผลเดิม
 *  - สมุดสะสมนับเฉพาะไพ่จากคำทำนายของตัวเองที่ไม่ล้มเหลว
 */
class EngagementPackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function reading(User $user, ?string $status = null, array $extra = []): Reading
    {
        return Reading::create(array_merge([
            'user_id' => $user->id, 'session_token' => 'tok-'.uniqid(), 'type' => 'tarot_three',
            'question' => 'งานจะดีไหม', 'payload' => [], 'result' => 'ไพ่บอกว่าดี', 'status' => $status,
        ], $extra));
    }

    /* ══════════ รีวิวหลังจบคำทำนาย ══════════ */

    public function test_the_owner_sees_the_review_box_on_a_finished_reading(): void
    {
        $u = User::factory()->create();
        $r = $this->reading($u);

        $this->actingAs($u)->get(route('tarot.show', $r))->assertOk()
            ->assertSee('คำทำนายนี้ตรงใจไหมคะ')->assertSee(route('tarot.review', $r), false)
            ->assertSee('เปิดลิงก์แชร์');
    }

    public function test_the_review_box_is_hidden_from_visitors_and_while_reading_or_failed(): void
    {
        $u = User::factory()->create();
        $shared = $this->reading($u, null, ['shared_public' => true]);
        $this->get(route('tarot.show', $shared))->assertOk()->assertDontSee('คำทำนายนี้ตรงใจไหมคะ');

        foreach ([Reading::STATUS_WORKING, Reading::STATUS_FAILED] as $status) {
            $r = $this->reading($u, $status);
            $this->actingAs($u)->get(route('tarot.show', $r))->assertOk()->assertDontSee('คำทำนายนี้ตรงใจไหมคะ');
            $this->actingAs($u)->post(route('tarot.review', $r), ['rating' => 5])->assertStatus(422);
        }
    }

    public function test_a_review_is_saved_once_per_reading_and_an_edit_waits_for_approval_again(): void
    {
        $u = User::factory()->create(['name' => 'สมหญิง ใจดี', 'email' => 'somying@example.test']);
        $r = $this->reading($u);

        $this->actingAs($u)->post(route('tarot.review', $r), ['rating' => 5, 'message' => 'ตรงมาก <b>ขอบคุณ</b>', 'allow_public' => '1'])
            ->assertRedirect(route('tarot.show', $r).'#review');

        $t = Testimonial::sole();
        $this->assertSame($r->id, $t->reading_id);
        $this->assertSame($u->id, $t->user_id);
        $this->assertSame('คุณสมหญิง', $t->name);
        $this->assertSame('ตรงมาก ขอบคุณ', $t->message);
        $this->assertTrue($t->allow_public);
        $this->assertFalse($t->approved);

        $t->update(['approved' => true]);
        $this->actingAs($u)->post(route('tarot.review', $r), ['rating' => 3, 'message' => 'แก้แล้ว']);

        $this->assertSame(1, Testimonial::count());
        $t->refresh();
        $this->assertSame(3, $t->rating);
        $this->assertFalse($t->approved, 'แก้รีวิวแล้วต้องรอแอดมินอนุมัติใหม่');
        $this->assertFalse($t->allow_public, 'ไม่ติ๊กยอม = ไม่ขึ้นหน้าแรก');
    }

    public function test_someone_elses_reading_cannot_be_reviewed_or_shared(): void
    {
        $owner = User::factory()->create();
        $r = $this->reading($owner);
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('tarot.review', $r), ['rating' => 1])->assertForbidden();
        $this->actingAs($other)->post(route('tarot.share', $r), ['public' => '1'])->assertForbidden();
        $this->assertSame(0, Testimonial::count());
        $this->assertFalse($r->fresh()->shared_public);
    }

    public function test_a_rating_is_required_and_bounded(): void
    {
        $u = User::factory()->create();
        $r = $this->reading($u);

        $this->actingAs($u)->post(route('tarot.review', $r), ['rating' => 9])->assertSessionHasErrors('rating');
        $this->actingAs($u)->post(route('tarot.review', $r), ['message' => 'ไม่มีดาว'])->assertSessionHasErrors('rating');
        $this->assertSame(0, Testimonial::count());
    }

    public function test_a_customer_review_reaches_the_home_page_only_when_both_customer_and_admin_agree(): void
    {
        $u = User::factory()->create();
        $mk = fn (bool $public, bool $approved) => Testimonial::create([
            'name' => 'คุณเอ', 'service' => 'ไพ่สามใบ', 'rating' => 5, 'message' => $public ? 'ยอม' : 'ไม่ยอม',
            'user_id' => $u->id, 'reading_id' => $this->reading($u)->id, 'allow_public' => $public, 'approved' => $approved,
        ]);
        $mk(true, true);
        $mk(false, true);
        $mk(true, false);
        Testimonial::create(['name' => 'แอดมิน', 'service' => 'x', 'rating' => 5, 'message' => 'เขียนเอง', 'approved' => true]);

        $this->assertEqualsCanonicalizing(['ยอม', 'เขียนเอง'], Testimonial::approved()->pluck('message')->all());
    }

    public function test_display_name_never_leaks_an_email_or_surname(): void
    {
        $this->assertSame('ลูกค้าจันทรา', Testimonial::displayNameFor(new User(['name' => 'me@mail.com'])));
        $this->assertSame('ลูกค้าจันทรา', Testimonial::displayNameFor(null));
        $this->assertSame('คุณมานี', Testimonial::displayNameFor(new User(['name' => '  มานี มีนา  '])));
    }

    /* ══════════ แชร์ลิงก์ ══════════ */

    public function test_the_owner_can_open_and_close_the_share_link(): void
    {
        $u = User::factory()->create();
        $r = $this->reading($u);

        $this->get(route('tarot.show', $r))->assertForbidden();

        $this->actingAs($u)->post(route('tarot.share', $r), ['public' => '1'])->assertRedirect(route('tarot.show', $r).'#share');
        $this->assertTrue($r->fresh()->shared_public);
        $this->actingAs($u)->get(route('tarot.show', $r))->assertSee('ส่งทาง LINE')->assertSee(urlencode(route('tarot.show', $r)), false);

        auth()->logout();
        $this->get(route('tarot.show', $r))->assertOk();

        $this->actingAs($u)->post(route('tarot.share', $r), ['public' => '0']);
        $this->assertFalse($r->fresh()->shared_public);
        auth()->logout();
        $this->get(route('tarot.show', $r))->assertForbidden();
    }

    /* ══════════ เช็กอินรับดวง ══════════ */

    public function test_checking_in_twice_a_day_counts_once_and_a_streak_grows_day_by_day(): void
    {
        $u = User::factory()->create();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-07 09:00', DailyLuck::TZ));

        $this->actingAs($u)->post(route('account.checkin'))->assertRedirect(route('account.dashboard').'#daily-luck');
        $this->actingAs($u)->post(route('account.checkin'));
        $this->assertSame(1, DailyCheckin::count());
        $this->assertSame(1, DailyLuck::currentStreak($u));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-08 23:30', DailyLuck::TZ));
        $this->assertSame(2, DailyLuck::checkIn($u)->streak);

        // ขาดหนึ่งวัน → วันต่อเนื่องดับ แล้วเริ่ม 1 ใหม่
        // (ช่วงหัวค่ำเวลาไทย = ช่วงที่วัน UTC กับวันไทยห่างกันมากสุด — การเทียบข้ามเขตเวลาจะพลาดตรงนี้)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 20:00', DailyLuck::TZ));
        $this->assertSame(0, DailyLuck::currentStreak($u));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 08:00', DailyLuck::TZ));
        $this->assertSame(0, DailyLuck::currentStreak($u));
        $this->assertSame(1, DailyLuck::checkIn($u)->streak);
    }

    public function test_the_day_follows_thai_time_not_utc(): void
    {
        $u = User::factory()->create();
        // 23:30 UTC ของวันที่ 7 = 06:30 ของวันที่ 8 เวลาไทย
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-07 23:30', 'UTC'));
        $this->assertSame('2026-11-08', DailyLuck::checkIn($u)->day->toDateString());
    }

    public function test_the_daily_luck_is_the_same_all_day_and_changes_the_next_day(): void
    {
        $u = User::factory()->create();
        $d = CarbonImmutable::parse('2026-11-07', DailyLuck::TZ);

        $a = DailyLuck::reading($u, $d);
        $this->assertSame($a, DailyLuck::reading($u, $d));
        $this->assertSame('เสาร์', $a['day_name']);
        $this->assertSame('ม่วง', $a['day_color']['name']);
        $this->assertMatchesRegularExpression('/^\d{2}$/', $a['number']);

        $week = collect(range(1, 7))->map(fn ($i) => DailyLuck::reading($u, $d->addDays($i))['blessing']);
        $this->assertGreaterThan(1, $week->unique()->count(), 'ทั้งสัปดาห์ไม่ควรได้คำอวยพรเดิมทุกวัน');
    }

    public function test_the_dashboard_hides_the_luck_until_the_member_checks_in(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get(route('account.dashboard'))->assertOk()
            ->assertSee('รับดวงวันนี้')->assertDontSee('เลขนำโชค</span>', false);

        DailyLuck::checkIn($u);
        $luck = DailyLuck::reading($u);
        $this->actingAs($u)->get(route('account.dashboard'))->assertOk()
            ->assertSee('เลขนำโชค')->assertSee($luck['blessing']);
    }

    public function test_streak_milestones(): void
    {
        $this->assertSame(['days' => 3, 'label' => 'ดาวเริ่มส่องทาง', 'left' => 2], DailyLuck::nextMilestone(1));
        $this->assertNull(DailyLuck::nextMilestone(30));
        $this->assertSame([3, 7], array_keys(DailyLuck::earnedMilestones(8)));
    }

    /* ══════════ สมุดสะสมไพ่ ══════════ */

    public function test_the_collection_counts_only_cards_from_your_own_successful_readings(): void
    {
        $cards = collect(range(1, 4))->map(fn ($i) => TarotCard::create([
            'slug' => "c{$i}", 'name_en' => "C{$i}", 'name_th' => "ไพ่ทดสอบ{$i}", 'arcana' => 'major', 'suit' => 'major', 'number' => $i,
            'keywords_th' => '-', 'upright_meaning_th' => '-', 'reversed_meaning_th' => '-', 'active' => true,
        ]));
        $u = User::factory()->create();
        $other = User::factory()->create();

        $ok = $this->reading($u);
        $failed = $this->reading($u, Reading::STATUS_FAILED);
        $theirs = $this->reading($other);
        $deal = fn (Reading $r, TarotCard $c, int $pos) => TarotReadingCard::create(['reading_id' => $r->id, 'tarot_card_id' => $c->id, 'position' => $pos, 'position_label' => 'ตำแหน่ง']);
        $deal($ok, $cards[0], 1);
        $deal($ok, $cards[0], 2);
        $deal($ok, $cards[1], 3);
        $deal($failed, $cards[2], 1);
        $deal($theirs, $cards[3], 1);

        $this->actingAs($u)->get(route('account.collection'))->assertOk()
            ->assertSee('เจอแล้ว <em style="color:var(--gold)">2</em> จาก 4 ใบ', false)
            ->assertSee('ไพ่ทดสอบ1')->assertSee('2 ครั้ง')->assertSee('ไพ่ทดสอบ2')
            ->assertDontSee('ไพ่ทดสอบ3')->assertDontSee('ไพ่ทดสอบ4');
    }

    public function test_the_collection_needs_a_login(): void
    {
        $this->get(route('account.collection'))->assertRedirect(route('login'));
    }

    /* ══════════ ป๊อปอัปมีอะไรใหม่ ══════════ */

    public function test_the_whats_new_popup_is_for_members_and_stays_off_the_ritual_pages(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get(route('account.dashboard'))->assertSee('jw-whats-new-2026-11-07b');
        $this->actingAs($u)->get(route('tarot.show', $this->reading($u)))->assertDontSee('jw-whats-new-2026-11-07b');

        auth()->logout();
        $this->get('/')->assertDontSee('jw-whats-new-2026-11-07b');
    }

    /** 💬 (2026-11-07) ลูกค้าอยู่ในห้องแชทเกือบตลอด — เด้งในห้องได้ แต่ห้ามบังตอนกำลังทำนาย */
    public function test_the_whats_new_popup_reaches_the_chat_room_but_never_during_a_reading(): void
    {
        $this->withoutVite();
        $u = User::factory()->create();
        $this->actingAs($u)->get('/chat?room=maemor')->assertOk()->assertSee('jw-whats-new-2026-11-07b')->assertSee('กดไมค์พูดแทนพิมพ์');

        $this->actingAs($u)->post('/tarot/begin', ['spread' => 'celtic']);
        $this->actingAs($u)->get('/chat?room=maemor')->assertOk()->assertDontSee('jw-whats-new-2026-11-07b');
    }
}
