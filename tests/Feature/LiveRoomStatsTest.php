<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\User;
use App\Services\Chat\LiveRoomStats;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🔴 (2026-11-04) ตัวเลขสดหน้าห้องผู้พยากรณ์ — ต้องเป็นเลขจริงทุกตัว (เว็บประกาศว่า "ไม่เดา ไม่มโน")
 *
 * ตรึงไว้: ใช้เลขของ Thaiprompt ตรง ๆ (ไม่บวกบิลเว็บซ้ำ) · Thaiprompt ยังไม่มี endpoint → ใช้เลขของเว็บเอง ·
 * "ออนไลน์" ขั้นต่ำ 1 = ผู้ชมที่เปิดหน้าอยู่เอง ไม่ใช่คนสมมติ · บิลที่คืนเงินแล้วไม่นับ · คิวค้างนานไม่นับ
 */
class LiveRoomStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function upstream(?array $data): void
    {
        $this->mock(JuntraServerClient::class, function ($m) use ($data) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('liveStats')->andReturn($data);
        });
    }

    private function pendingReading(User $u, ?Carbon $touched = null): Reading
    {
        $r = Reading::create([
            'user_id' => $u->id, 'session_token' => (string) Str::uuid(), 'type' => 'tarot_celtic',
            'status' => Reading::STATUS_PENDING,
        ]);
        if ($touched) {
            $r->forceFill(['updated_at' => $touched])->saveQuietly();
        }

        return $r;
    }

    public function test_uses_thaiprompt_numbers_and_adds_only_the_web_reading_queue(): void
    {
        $this->upstream(['chatting_now' => 7, 'bills_total' => 1234, 'reading_queue' => 2, 'window_minutes' => 5]);
        $u = User::factory()->create();
        $this->pendingReading($u);

        // บิลเว็บอยู่ในยอดของ Thaiprompt แล้ว — ห้ามบวกซ้ำ
        $wallet = app(WalletService::class);
        $wallet->credit($u, 100, 'seed');
        $wallet->debit($u, 9, 'เปิดไพ่', ['reference_type' => 'reading']);

        $s = app(LiveRoomStats::class)->get();

        $this->assertSame(7, $s['online']);
        $this->assertSame(7, $s['chatting']);
        $this->assertSame(1234, $s['bills']);
        $this->assertSame(3, $s['queue']);
        $this->assertSame('all', $s['source']);
    }

    public function test_online_floor_is_the_viewer_only_never_more(): void
    {
        $this->upstream(['chatting_now' => 0, 'bills_total' => 10, 'reading_queue' => 0]);

        $s = app(LiveRoomStats::class)->get();

        $this->assertSame(0, $s['chatting']);
        $this->assertSame(1, $s['online']);
        $this->assertSame(0, $s['queue']);
    }

    public function test_falls_back_to_web_numbers_when_thaiprompt_has_no_endpoint(): void
    {
        $this->upstream(null);
        $u = User::factory()->create();

        // แชทเว็บ: 2 ห้องที่พิมพ์มาใน 5 นาที (ห้องหนึ่งพิมพ์ 2 ข้อความ) + 1 ห้องที่เงียบไปนานแล้ว
        foreach ([0, 0, 1] as $i) {
            $convs[$i] ??= ChatConversation::create(['user_id' => $u->id, 'session_token' => (string) Str::uuid()]);
            ChatMessage::create(['chat_conversation_id' => $convs[$i]->id, 'role' => 'user', 'content' => 'สวัสดี']);
        }
        $old = ChatConversation::create(['user_id' => $u->id, 'session_token' => (string) Str::uuid()]);
        $msg = ChatMessage::create(['chat_conversation_id' => $old->id, 'role' => 'user', 'content' => 'เก่า']);
        $msg->forceFill(['created_at' => now()->subMinutes(30)])->saveQuietly();

        // บิลเว็บ: 2 ใบ คืนเงินไป 1 ใบ
        $wallet = app(WalletService::class);
        $wallet->credit($u, 100, 'seed');
        $wallet->debit($u, 9, 'เปิดไพ่', ['reference_type' => 'reading']);
        $refunded = $wallet->debit($u, 9, 'เปิดไพ่', ['reference_type' => 'reading']);
        $wallet->refund($refunded, 'ทดสอบ');

        // คิวเว็บ: กำลังอ่าน 1 · ค้างมา 2 ชม. ไม่นับ
        $this->pendingReading($u);
        $this->pendingReading($u, now()->subHours(2));

        $s = app(LiveRoomStats::class)->get();

        $this->assertSame('web', $s['source']);
        $this->assertSame(2, $s['chatting']);
        $this->assertSame(2, $s['online']);
        $this->assertSame(1, $s['bills']);
        $this->assertSame(1, $s['queue']);
    }

    public function test_live_endpoint_is_public_json(): void
    {
        $this->upstream(['chatting_now' => 3, 'bills_total' => 99, 'reading_queue' => 1]);

        $this->getJson('/chat/live')
            ->assertOk()
            ->assertJson(['online' => 3, 'bills' => 99, 'queue' => 1, 'source' => 'all']);
    }

    public function test_room_picker_renders_the_live_panel_with_server_numbers(): void
    {
        $this->upstream(['chatting_now' => 12, 'bills_total' => 4321, 'reading_queue' => 0]);

        $this->withoutVite()->get('/chat?pick=1')
            ->assertOk()
            ->assertSee('LIVE · ออนไลน์ตอนนี้')
            ->assertSee('4,321')
            ->assertSee('คิวว่าง · เข้าคุยได้ทันที');
    }
}
