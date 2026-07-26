<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "บริการทุกอย่างต้องบันทึกและเรียกดูย้อนหลังได้"
 *
 * 🔴 บั๊กที่กันไว้ (2026-07-26): หน้าประวัติมีปุ่ม "ดูผล" เฉพาะไพ่ยิปซี — เลขศาสตร์
 * ลายมือ ฤกษ์ยาม ดูดวงเชิงลึก ถูกบันทึกลง readings ครบทุกครั้งแต่ไม่มีเส้นทางไหน
 * เปิดอ่านได้เลย ลูกค้าจ่ายเงินแล้วเห็นผลได้ครั้งเดียว ปิดแท็บ = หายถาวร
 * ทั้งที่หน้าประวัติเขียนไว้เองว่า "ย้อนกลับไปอ่านได้ตลอด"
 */
class ReadingHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    private function member(float $balance = 500): User
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, $balance, 'seed');

        return $u;
    }

    /* ══════════ ฤกษ์ยาม: ซื้อ → เก็บ → เปิดซ้ำได้ ══════════ */

    public function test_auspicious_purchase_lands_on_a_replayable_result_page(): void
    {
        $user = $this->member();

        $resp = $this->actingAs($user)->post('/auspicious/find', [
            'occasion'      => 'เปิดร้านกาแฟ',
            'occasion_type' => 'business',
        ]);

        $reading = Reading::where('type', 'auspicious')->firstOrFail();
        $resp->assertRedirect(route('reading.show', $reading));

        // เปิดจากลิงก์ที่เพิ่ง redirect มา แล้วเปิดซ้ำอีกรอบ — ต้องได้เหมือนเดิมทั้งสองครั้ง
        foreach ([1, 2] as $round) {
            $this->actingAs($user)->get(route('reading.show', $reading))
                ->assertOk()
                ->assertSee('เปิดร้านกาแฟ')
                ->assertSee('ฤกษ์')
                ->assertSee('คะแนนฤกษ์', false);
        }
    }

    /** ผลที่เก็บไว้ต้องครบพอ render หน้าใหม่ได้โดยไม่ต้องคำนวณดวงจันทร์ซ้ำ */
    public function test_stored_payload_carries_the_full_ruek_detail(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post('/auspicious/find', [
            'occasion' => 'แต่งงานลูกสาว', 'occasion_type' => 'wedding',
        ]);

        $p = Reading::where('type', 'auspicious')->firstOrFail()->payload;

        $this->assertSame('wedding', $p['occasion_type']);
        $this->assertSame('thai-astro-v1', $p['engine']);
        $this->assertNotEmpty($p['candidates']);

        $first = $p['candidates'][0];
        foreach (['date', 'label', 'score', 'score_pct', 'grade', 'weekday', 'nakshatra', 'ruek', 'tithi', 'reasons'] as $key) {
            $this->assertArrayHasKey($key, $first, "payload ต้องมี {$key} ไม่งั้นหน้าย้อนหลัง render ไม่ครบ");
        }
        $this->assertNotEmpty($first['ruek']['name']);
        $this->assertNotEmpty($first['tithi']['label']);
        // สเกล 0-10 เดิมต้องอยู่ครบ แอพมือถือที่ deploy ไปแล้วอ่านคีย์นี้
        $this->assertLessThanOrEqual(10, $first['score']);
    }

    /** คำแนะนำต้องอ้างถึงวันและฤกษ์ของลูกค้าคนนั้นจริง ไม่ใช่ข้อความสำเร็จรูป */
    public function test_advice_is_specific_to_the_computed_days(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post('/auspicious/find', [
            'occasion' => 'ขึ้นบ้านใหม่', 'occasion_type' => 'housewarming',
        ]);

        $reading = Reading::where('type', 'auspicious')->firstOrFail();
        $top = $reading->payload['candidates'][0];

        $this->assertStringContainsString($top['ruek']['name'], $reading->result);
        $this->assertStringContainsString($top['label'], $reading->result);
        $this->assertStringContainsString('ขึ้นบ้านใหม่', $reading->result);
        $this->assertGreaterThan(300, mb_strlen($reading->result), 'คำแนะนำต้องมีเนื้อพอสมกับที่เก็บเงิน');
    }

    /* ══════════ หน้าประวัติต้องพาไปดูผลได้ทุกบริการ ══════════ */

    public function test_history_links_every_service_to_its_result_page(): void
    {
        $user = $this->member();

        $types = [
            'auspicious' => ['occasion' => 'เปิดร้าน', 'candidates' => []],
            'numerology' => ['name' => 'สมชาย ใจดี', 'birth_date' => '1990-05-15'],
            'palmistry'  => ['image_path' => 'palmistry/x.jpg'],
            'deep'       => ['questions' => ['เรื่องงานจะเป็นอย่างไร'], 'birth_date' => '1990-05-15'],
        ];
        foreach ($types as $type => $payload) {
            Reading::create([
                'user_id'       => $user->id,
                'session_token' => "tok-{$type}",
                'type'          => $type,
                'question'      => "คำถาม {$type}",
                'payload'       => $payload,
                'result'        => "ผลของ {$type}",
            ]);
        }

        $page = $this->actingAs($user)->get('/account/history')->assertOk();
        foreach (Reading::all() as $r) {
            $page->assertSee(route('reading.show', $r), false);
        }

        // ทุกใบต้องเปิดได้จริง ไม่ใช่แค่มีลิงก์
        foreach (Reading::all() as $r) {
            $this->actingAs($user)->get(route('reading.show', $r))
                ->assertOk()
                ->assertSee("ผลของ {$r->type}");
        }
    }

    public function test_history_can_be_filtered_by_service(): void
    {
        $user = $this->member();
        Reading::create(['user_id' => $user->id, 'session_token' => 'a', 'type' => 'auspicious', 'question' => 'ถามฤกษ์', 'payload' => [], 'result' => 'x']);
        Reading::create(['user_id' => $user->id, 'session_token' => 'b', 'type' => 'numerology', 'question' => 'ถามเลข', 'payload' => [], 'result' => 'y']);

        $this->actingAs($user)->get('/account/history?type=auspicious')
            ->assertOk()->assertSee('ถามฤกษ์')->assertDontSee('ถามเลข');
    }

    /* ══════════ สิทธิ์การเข้าถึง ══════════ */

    public function test_other_members_cannot_open_someone_elses_reading(): void
    {
        $owner = $this->member();
        $reading = Reading::create([
            'user_id' => $owner->id, 'session_token' => 'own', 'type' => 'auspicious',
            'question' => 'ความลับ', 'payload' => [], 'result' => 'ผลลับ',
        ]);

        // 404 ไม่ใช่ 403 — 403 ยืนยันกลาย ๆ ว่า id นี้มีอยู่จริง ทำให้ไล่เดา id ได้
        $this->actingAs($this->member())->get(route('reading.show', $reading))->assertNotFound();
        $this->get(route('reading.show', $reading))->assertNotFound();
    }

    public function test_admin_can_open_any_reading_for_support(): void
    {
        $owner = $this->member();
        $reading = Reading::create([
            'user_id' => $owner->id, 'session_token' => 'own2', 'type' => 'auspicious',
            'question' => 'ถาม', 'payload' => [], 'result' => 'ผล',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('reading.show', $reading))->assertOk();
    }

    /* ══════════ หน้าแรกของบริการ ══════════ */

    public function test_auspicious_index_shows_a_live_preview_and_the_ruek_reference(): void
    {
        $this->get('/auspicious')
            ->assertOk()
            ->assertSee('ฤกษ์ 14 วันข้างหน้า')
            ->assertSee('ฤกษ์บน 9 ตามตำราไทย')
            ->assertSee('ภูมิปาโลฤกษ์')
            ->assertSee('ประเภทงาน');
    }
}
