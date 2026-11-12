<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Support\BusabaTour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🌸 (2026-11-12, owner) ครั้งแรกที่เข้าเว็บ: หน้าเลือก "ดูดวงฟรีเลย" / "ให้บุษบาพาทัวร์" + ทัวร์เสียงบุษบา
 *
 * owner: "ครั้งแรกของคนไม่เคยเข้าเว็บต้องง่ายในการใช้งานควรพอไปดู ดวงฟรีทันที แล้วค่อยให้ บุษบา แนะนำการใช้งาน
 *         เป็นเสียงพากย์ พาทัวร์ได้ มีปุ่มให้เลือกว่าจะไปทัวร์ไหม หรือ ดูดวงฟรีเลย"
 *
 * ล็อกไว้:
 *   - ใครเห็นหน้าเลือก: คนใหม่ (ไม่มี cookie · บัญชียังไม่เคยเลือก · ยังไม่เคยดูดวง) บนหน้าแรก/หน้าเลือกห้องเท่านั้น
 *   - เลือกแล้ว/ปิดแล้ว = ไม่ขึ้นอีก (cookie ของเครื่อง + tour_seen_at ของบัญชี)
 *   - ทัวร์ที่กำลังเดิน (cookie _run) ตามไปทุกหน้า และป๊อปอัป "มีอะไรใหม่" ไม่เด้งทับ
 *   - ปุ่มดูดวงฟรีพาไปทางที่สั้นที่สุด · ปิดรอบฟรีอยู่ห้ามเขียนว่าฟรี
 *   - ทุกขั้นมีไฟล์เสียงบุษบา และทุกชิ้นที่ทัวร์ชี้มีอยู่จริงบนหน้านั้น
 *   - ทัวร์สอนใช้เว็บเท่านั้น: ไม่พูดราคา/ตัวเลข (ราคาเปลี่ยนในแอดมินได้ เสียงที่อัดไว้จะโกหก)
 *     และสอนเติมเงินแบบที่ถูก (โอนตรงยอดรวมสตางค์ ไม่ส่งสลิปในแชทเฟซบุ๊ก)
 */
class BusabaTourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 404)]);
        // /public/build อยู่ใน .gitignore — ผลเทสต์ต้องไม่ขึ้นกับว่าเครื่องนี้ build มาแล้วหรือยัง
        $this->withoutVite();
        Cache::flush();
    }

    private function cookie(): string
    {
        return BusabaTour::cookie();
    }

    // ── ใครเห็นหน้าเลือก ─────────────────────────────────────────────────────

    public function test_a_first_time_guest_sees_the_two_buttons_on_the_home_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="bt-welcome"', false)
            ->assertSee('ให้บุษบาพาทัวร์')
            ->assertSee('ดูดวงฟรีเลย')
            ->assertSee('id="bt-data"', false);
    }

    public function test_the_room_picker_also_offers_the_choice(): void
    {
        // สภาพจริงบน prod: ห้องบุษบาเปิด (CHAT_PERSONA_UPSTREAM=true)
        config(['chat_rooms.persona_upstream' => true]);
        Setting::put('tarot_quick_visible', '1', 'tarot', false);
        Cache::flush();

        $this->get('/chat?pick=1')
            ->assertOk()
            ->assertSee('id="bt-welcome"', false)
            // ชิ้นที่ทัวร์ชี้บนหน้านี้ต้องมีจริง (config/busaba_tour.php: rooms · maemor · ask)
            ->assertSee('data-tour="room-busaba"', false)
            ->assertSee('data-tour="room-maemor"', false)
            ->assertSee('data-tour="room-busaba-enter"', false);
    }

    public function test_a_device_that_already_chose_never_sees_it_again(): void
    {
        foreach (['free', 'tour', 'later', 'done', 'skip'] as $choice) {
            $this->withUnencryptedCookie($this->cookie(), $choice)
                ->get('/')
                ->assertOk()
                ->assertDontSee('id="bt-welcome"', false)
                ->assertDontSee('id="bt-data"', false);
        }
    }

    public function test_a_new_member_sees_it_but_not_after_choosing_or_after_a_first_reading(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertSee('id="bt-welcome"', false);

        // เลือกบนเครื่องอื่นแล้ว (ไม่มี cookie บนเครื่องนี้) — บัญชีจำไว้
        $user->forceFill(['tour_seen_at' => now()])->saveQuietly();
        $this->actingAs($user->fresh())->get('/')->assertDontSee('id="bt-welcome"', false);

        // คนที่ดูดวงเป็นแล้ว ไม่ใช่คนครั้งแรก
        $old = User::factory()->create();
        Reading::create(['user_id' => $old->id, 'session_token' => 'tok-old', 'type' => 'tarot_single', 'question' => 'q', 'payload' => [], 'result' => 'r', 'status' => 'done']);
        $this->actingAs($old)->get('/')->assertDontSee('id="bt-welcome"', false);
    }

    public function test_pages_with_their_own_job_are_never_covered(): void
    {
        $this->get('/tarot/free')->assertOk()->assertDontSee('id="bt-welcome"', false);
        $this->get('/login')->assertOk()->assertDontSee('id="bt-welcome"', false);

        $user = User::factory()->create();
        $this->actingAs($user)->get('/wallet/topup')->assertOk()->assertDontSee('id="bt-welcome"', false);
        $this->actingAs($user)->get('/?theater=1')->assertDontSee('id="bt-welcome"', false);
    }

    // ── จำว่าเลือกแล้ว ──────────────────────────────────────────────────────

    public function test_choosing_remembers_the_device_and_the_account_once(): void
    {
        $this->postJson('/tour/seen', ['choice' => 'free'])
            ->assertOk()
            ->assertJson(['ok' => true, 'choice' => 'free'])
            ->assertCookie($this->cookie(), 'free', false);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/tour/seen', ['choice' => 'tour'])->assertOk();
        $first = $user->fresh()->tour_seen_at;
        $this->assertNotNull($first);

        $this->travel(5)->minutes();
        $this->actingAs($user)->postJson('/tour/seen', ['choice' => 'done'])->assertOk();
        $this->assertTrue($first->equalTo($user->fresh()->tour_seen_at), 'the first choice time must not be overwritten');

        // ค่าแปลก ๆ = later (ไม่เก็บสิ่งที่ส่งมาตรง ๆ)
        $this->postJson('/tour/seen', ['choice' => '<script>'])->assertJson(['choice' => 'later']);
    }

    public function test_the_tour_cookies_are_readable_by_the_page(): void
    {
        // หน้าเว็บเขียน cookie ขั้นปัจจุบันเอง (JS) — ต้องไม่ถูกเข้ารหัส ไม่งั้นหน้าถัดไปไม่รู้ว่าทัวร์เดินอยู่
        $this->assertSame('jw_tour_v1', BusabaTour::cookie(), 'bootstrap/app.php encryptCookies(except:) lists this name');
        $this->assertSame('jw_tour_v1_run', BusabaTour::runCookie());

        $this->postJson('/tour/seen', ['choice' => 'skip'])
            ->assertCookie($this->cookie(), 'skip', false);
    }

    // ── ทัวร์ที่กำลังเดิน ───────────────────────────────────────────────────

    public function test_a_running_tour_follows_the_member_to_every_page_it_points_at(): void
    {
        $user = User::factory()->create(['tour_seen_at' => now()]);

        $pages = [
            '/wallet'          => 'data-tour="wallet-balance"',
            '/wallet/topup'    => 'data-tour="topup-cards"',
            '/account/history' => 'data-tour="history"',
            '/account/dashboard' => 'data-tour="account-menu"',
            '/profile'         => 'data-tour="profile"',
        ];
        foreach ($pages as $url => $target) {
            $this->actingAs($user)
                ->withUnencryptedCookie(BusabaTour::runCookie(), '6')
                ->get($url)
                ->assertOk()
                ->assertSee('id="bt-data"', false)
                ->assertSee($target, false)
                ->assertDontSee('id="bt-welcome"', false)
                // ทัวร์อยู่บนจอ = ป๊อปอัปมีอะไรใหม่ไม่เด้งทับ
                ->assertDontSee('wn-backdrop', false);
        }

        $this->actingAs($user)
            ->withUnencryptedCookie(BusabaTour::runCookie(), '7')
            ->get('/wallet/topup')
            ->assertSee('data-tour="topup-exact"', false);
    }

    public function test_tour_query_replays_the_tour_for_anyone(): void
    {
        $this->withUnencryptedCookie($this->cookie(), 'done')
            ->get('/?tour=1')
            ->assertOk()
            ->assertSee('id="bt-data"', false)
            ->assertDontSee('id="bt-welcome"', false);
    }

    public function test_the_menu_and_free_link_carry_their_tour_marks_in_both_themes(): void
    {
        foreach (['mae-mor-chantra', 'juntra-payakorn'] as $theme) {
            $html = view("themes.{$theme}.nav")->render();
            foreach (['data-tour-nav', 'data-tour="menu"', 'data-tour="free"', 'data-tour="burger"'] as $mark) {
                $this->assertStringContainsString($mark, $html, "$theme nav lost $mark");
            }
        }
    }

    // ── ปุ่มดูดวงฟรี ──────────────────────────────────────────────────────

    public function test_the_free_button_takes_the_shortest_way(): void
    {
        $this->assertSame(route('thaiprompt.redirect', ['to' => '/tarot/free']), BusabaTour::freeUrl(null));
        $this->assertSame(route('tarot.free'), BusabaTour::freeUrl(User::factory()->create()));

        // ปิดรอบดูดวงฟรี → ห้องบุษบา และปุ่มต้องไม่เขียนว่าฟรี
        Setting::put('free_reading_enabled', '0', 'general', false);
        Cache::flush();
        $this->assertFalse(\App\Support\FreeReadingPolicy::enabled());
        $this->assertSame(route('chat.index', ['room' => 'busaba']), BusabaTour::freeUrl(null));
        $this->get('/')->assertSee('เข้าห้องบุษบา')->assertDontSee('ดูดวงฟรีเลย');
    }

    // ── เนื้อหาทัวร์ ──────────────────────────────────────────────────────

    public function test_every_step_has_busaba_voice_and_a_clean_caption(): void
    {
        $steps = BusabaTour::steps();
        $this->assertGreaterThanOrEqual(10, count($steps));

        foreach ($steps as $s) {
            $this->assertFileExists(public_path(BusabaTour::audioPath($s['key'])), "tour_{$s['key']}.mp3 missing — record it with Busaba's voice");
            $this->assertNotNull($s['audio']);
            $this->assertStringNotContainsString('[', $s['text'], "ElevenLabs tags must not show on screen ({$s['key']})");
            $this->assertNotSame('', $s['title']);
            // ราคาเปลี่ยนในแอดมินได้ — เสียงที่อัดไว้ห้ามพูดตัวเลขหรือราคา
            $this->assertDoesNotMatchRegularExpression('/[0-9๐-๙฿]|บาท/u', $s['text'], "step {$s['key']} must not speak numbers or prices");
        }

        // หัวข้อที่เจ้าของสั่งให้สอน ต้องอยู่ครบ
        $keys = array_column($steps, 'key');
        foreach (['rooms', 'free', 'wallet', 'topup', 'exact', 'history', 'account', 'profile', 'menu', 'ask'] as $must) {
            $this->assertContains($must, $keys);
        }
    }

    public function test_the_tour_teaches_the_right_top_up_habit_and_the_hand_off(): void
    {
        $by = collect(BusabaTour::steps())->keyBy('key');

        $exact = $by['exact']['text'];
        $this->assertStringContainsString('ตรงยอด', $exact);
        $this->assertStringContainsString('สตางค์', $exact);
        $this->assertStringContainsString('ไม่ต้องส่งสลิปในแชทเฟซบุ๊ก', $exact);
        $this->assertStringContainsString('การ์ดยอดเงิน', $by['topup']['text']);

        // เรื่องที่เกินไพ่ของบุษบา (ไม่ถึง 10 ใบ) → แม่หมอจันทรา
        $this->assertStringContainsString('ไม่ถึงสิบใบ', $by['rooms']['text']);
        $this->assertStringContainsString('แม่หมอจันทรา', $by['maemor']['text']);

        // ขั้นของหน้าสมาชิกต้องประกาศ auth — คนที่ยังไม่ล็อกอินจะไม่ถูกพาไปเด้งหน้าล็อกอิน
        foreach (['wallet', 'topup', 'exact', 'history', 'account', 'profile'] as $k) {
            $this->assertTrue($by[$k]['auth'], "$k is a members-only page");
            $this->assertNotNull($by[$k]['url']);
        }
        $this->assertNull($by['hello']['url'], 'the first step plays on the page the tap happened on');
    }

    /**
     * 🗣️ (2026-11-12) เจ้าของ: "ห้อง บุด สะ บา ดู ไว ออกเสียงผิดเป็น บุดบา ดูไว"
     * ElevenLabs อ่าน "บุษบา" เป็น "บุดบา" → ข้อความที่ส่งไปอัดต้องสะกดตามเสียง ส่วนบนจอยังสะกดถูก
     * (ตรวจเสียงที่อัดใหม่ด้วย faster-whisper: คลิปเก่าได้ยิน "บุตบา" · คลิปใหม่ได้ยิน "บุตศบา"/"บุษบา")
     */
    public function test_busaba_name_is_spelled_by_sound_for_the_voice_but_right_on_screen(): void
    {
        $withName = 0;
        foreach (config('busaba_tour.steps') as $key => $s) {
            $spoken = BusabaTour::spoken($s['say']);
            $this->assertStringNotContainsString('บุษบา', $spoken, "$key would be read as บุดบา");
            if (str_contains($s['say'], 'บุษบา')) {
                $withName++;
                $this->assertStringContainsString('บุดสะบา', $spoken);
            }
        }
        $this->assertGreaterThan(0, $withName, 'the tour says her name somewhere');

        foreach (BusabaTour::steps() as $s) {
            $this->assertStringNotContainsString('บุดสะบา', $s['text'], 'captions keep the real spelling');
        }
    }

    public function test_the_page_knows_how_much_bigger_the_pose_clip_frame_is(): void
    {
        // คลิปท่าทางมีที่ว่างเหนือหัว — หน้าเว็บต้องรู้สัดส่วนนี้ ไม่งั้นคลิปกับภาพนิ่งซ้อนไม่ตรงกัน (ภาพซ้อน)
        $html = $this->get('/')->assertOk()->getContent();
        preg_match('#<script type="application/json" id="bt-data">(.*?)</script>#s', $html, $m);
        $data = json_decode($m[1] ?? 'null', true);
        $this->assertIsArray($data);
        $this->assertEqualsWithDelta((float) config('oracle_media.rooms.busaba.poses.scale', 1), $data['poseScale'], 0.0001);
    }
}
