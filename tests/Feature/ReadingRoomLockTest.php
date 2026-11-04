<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Support\ReadingRoomLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🔒 (2026-11-05) เริ่มทำนายแล้วเปลี่ยนห้องไม่ได้จนจบ/หมดเวลา
 *
 * เจ้าของสั่ง: "ถ้าเริ่มทำนายแล้วจะเปลี่ยนห้องไม่ได้จนกว่าจะจบ หมดเวลา"
 *  - กด "เริ่มพิธี" (tarot/begin) = เริ่มทำนาย → ล็อกห้องของผู้พยากรณ์ชุดนั้น
 *  - ระหว่างล็อก: หน้าแชทห้องอื่น/หน้าเลือกห้อง → พากลับห้องที่ล็อก · เริ่ม/ส่งไพ่ชุดของห้องอื่นไม่ได้
 *  - ห้องเดียวกันทำต่อได้ตามปกติ
 *  - ปลดล็อกเมื่อ: คำทำนายเสร็จ/ล้มเหลว · หรือครบเวลา (pick 15 นาที / reading 10 นาที)
 */
class ReadingRoomLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // เปิดทุกห้อง (Thaiprompt รู้จัก persona + แพ็กเกจของบุษบา/คุณไสยเปิดขาย)
        config(['chat_rooms.persona_upstream' => true]);
        Setting::put('tarot_quick_visible', '1', 'tarot', false);
        Setting::put('tarot_kunsai_visible', '1', 'pricing', false);
        Cache::flush();
    }

    private function member(): User
    {
        return User::factory()->create(['thaiprompt_token' => null]);
    }

    private function readingFor(User $user, string $spread, ?string $status, array $payload = []): Reading
    {
        return Reading::create([
            'user_id'       => $user->id,
            'session_token' => Str::uuid()->toString(),
            'type'          => "tarot_{$spread}",
            'payload'       => $payload,
            'status'        => $status,
        ]);
    }

    public function test_begin_stores_the_room_and_start_time(): void
    {
        $this->actingAs($this->member())
            ->post('/tarot/begin', ['spread' => 'quick', 'question' => 'งาน'])
            ->assertRedirect(route('tarot.pick'));

        $pick = session('tarot_pick');
        $this->assertSame('busaba', $pick['room']);
        $this->assertEqualsWithDelta(now()->getTimestamp(), $pick['started_at'], 2);
    }

    public function test_after_begin_another_rooms_spread_is_blocked_but_the_same_room_is_fine(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post('/tarot/begin', ['spread' => 'quick']);

        // ไพ่ชุดของคุณไสย → ถูกพากลับหน้ารวมไพ่ พร้อมเหตุผล และพิธีเดิมยังอยู่
        $this->post('/tarot/begin', ['spread' => 'kunsai'])
            ->assertRedirect(route('tarot.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'เปลี่ยนห้องได้เมื่อ'));
        $this->assertSame('quick', session('tarot_pick.spread'));

        // ห้องเดียวกัน (บุษบา) เริ่มใหม่ได้
        $this->post('/tarot/begin', ['spread' => 'quick', 'question' => 'ความรัก'])
            ->assertRedirect(route('tarot.pick'));
    }

    public function test_cast_with_another_rooms_spread_is_blocked_without_charging(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post('/tarot/begin', ['spread' => 'quick']);

        $this->post('/tarot/cast', ['spread' => 'kunsai', '_idem' => (string) Str::uuid()])
            ->assertRedirect(route('tarot.index'))
            ->assertSessionHas('status');

        $this->assertSame(0, Reading::where('user_id', $user->id)->count());
    }

    public function test_chat_room_switch_is_redirected_back_to_the_locked_room(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post('/tarot/begin', ['spread' => 'kunsai']);

        $this->get('/chat?room=busaba')
            ->assertRedirect(route('chat.index', ['room' => 'kunsai']))
            ->assertSessionHas('status');

        // หน้าเลือกผู้พยากรณ์ก็คือทางเปลี่ยนห้อง — ล็อกด้วย
        $this->get('/chat?pick=1')
            ->assertRedirect(route('chat.index', ['room' => 'kunsai']));

        // ห้องที่ล็อกเอง เข้าได้ปกติ
        $this->get('/chat?room=kunsai')->assertOk();
    }

    public function test_the_pick_lock_expires(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post('/tarot/begin', ['spread' => 'quick']);

        $this->travel(16)->minutes();

        $this->get('/chat?room=maemor')->assertOk();
        $this->post('/tarot/begin', ['spread' => 'kunsai'])
            ->assertRedirect(route('tarot.pick'));
    }

    public function test_an_in_progress_reading_locks_its_room_until_it_finishes(): void
    {
        $user = $this->member();
        $reading = $this->readingFor($user, 'kunsai', Reading::STATUS_PENDING, ['room' => 'kunsai']);

        $this->actingAs($user)->get('/chat?room=busaba')
            ->assertRedirect(route('chat.index', ['room' => 'kunsai']))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'กำลังอ่านไพ่'));
        $this->post('/tarot/begin', ['spread' => 'quick'])
            ->assertRedirect(route('tarot.index'));

        // คำทำนายเสร็จ (status null) → ปลดล็อก
        $reading->update(['status' => null]);
        $this->get('/chat?room=busaba')->assertOk();
    }

    public function test_a_stuck_reading_stops_locking_after_the_timeout(): void
    {
        $user = $this->member();
        $this->readingFor($user, 'kunsai', Reading::STATUS_WORKING, ['room' => 'kunsai']);

        $this->travel(11)->minutes();

        $this->actingAs($user)->get('/chat?room=busaba')->assertOk();
    }

    public function test_reading_room_falls_back_to_the_spread_owner_for_old_rows(): void
    {
        $user = $this->member();
        // รายการก่อนมี payload.room — ห้องหาจากเจ้าของแพ็กเกจ (quick = บุษบา)
        $this->readingFor($user, 'quick', Reading::STATUS_PENDING);

        $this->actingAs($user)->get('/chat?room=maemor')
            ->assertRedirect(route('chat.index', ['room' => 'busaba']));
    }

    public function test_a_failed_reading_does_not_lock(): void
    {
        $user = $this->member();
        $this->readingFor($user, 'kunsai', Reading::STATUS_FAILED, ['room' => 'kunsai']);

        $this->actingAs($user)->get('/chat?room=busaba')->assertOk();
    }
}
