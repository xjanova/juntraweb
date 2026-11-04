<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🌙 (2026-11-03 FB web-only) ลูกค้าที่ย้ายมาจากแชทเพจ FB → /chat/continue/{handoff}
 *
 * 🔮 (2026-11-04 เจ้าของสั่ง) การ์ด "เข้าสู่ระบบดูดวง" ในเพจ → สมัคร/เข้าระบบ แล้วลงหน้าเลือกห้องดูดวงเลย
 * "ไม่ต้องส่งบริบทที่คุยในเฟชบุ๊คมา" — ล็อกกติกา:
 *   - ทุกทาง (ลิงก์ใหม่ / ลิงก์ใช้แล้ว / ลิงก์เสีย) ไปหน้าเลือกห้อง ไม่ตรงเข้าห้องแม่หมอ
 *   - ไม่เปิดห้อง "คุยต่อจากเพจ" ไม่มีคำทักทายต่อเรื่องจากเพจ ไม่เรียก Thaiprompt ที่นี่
 *   - เลือกห้องแล้วส่งข้อความ → ยังส่ง thaiprompt_user_id ให้ Thaiprompt (รู้จักลูกค้าจาก RPG/อุปนิสัย)
 */
class FbHandoffChatTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    private const SESSION = '5b0f7a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

    private const HANDOFF = '9d6f3c1a-2b4e-4f6a-8c0d-1e2f3a4b5c6d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '0', 'pricing', false);
        Cache::flush();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => [
                    'session_id' => self::SESSION, 'greeting' => 'สวัสดีค่ะลูก', 'persona' => 'busaba', 'display_name' => 'บุษบา',
                    'offer_topic' => null, 'continued_from' => 'facebook', 'handoff_id' => null, 'handoff_consumed' => false,
                    'customer' => ['display_name' => 'น้องฝน', 'returning' => true],
                ]], 201),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => [
                    'session_id' => self::SESSION, 'reply' => 'บุษบาฟังอยู่ค่ะ', 'kind' => 'reply', 'offer_topic' => null,
                ]]),
                default => Http::response(['message' => 'unexpected ' . $url], 500),
            };
        });
    }

    private function fbMember(?int $tpId = 4242): User
    {
        return User::factory()->create([
            'thaiprompt_user_id' => $tpId,
            'thaiprompt_token'   => null,
            'facebook_user_id'   => '6155' . Str::random(10),
        ]);
    }

    private function upstreamRequests(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/server/chat/'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_the_page_card_lands_on_the_room_picker_without_opening_a_room(): void
    {
        $user = $this->fbMember();
        $this->actingAs($user)->withSession(['chat_room' => 'maemor']);

        $this->get('/chat/continue/' . self::HANDOFF)->assertRedirect(route('chat.index', ['pick' => 1]));

        $this->assertSame(0, ChatConversation::where('user_id', $user->id)->count(), 'ไม่เปิดห้อง "คุยต่อจากเพจ"');
        $this->assertSame(0, ChatMessage::count(), 'ไม่มีคำทักทายต่อเรื่องจากเพจ');
        $this->assertCount(0, $this->upstreamRequests(), 'ไม่เรียก Thaiprompt ตอนกดลิงก์');

        $this->get(route('chat.index', ['pick' => 1]))->assertOk()->assertViewIs('pages.chat.rooms');
    }

    public function test_an_existing_room_is_never_touched(): void
    {
        $user = $this->fbMember();
        $room = ChatConversation::create(['user_id' => $user->id, 'session_token' => (string) Str::uuid(), 'title' => 'สนทนากับแม่หมอ']);
        $room->messages()->create(['role' => 'user', 'content' => 'เรื่องงานค่ะแม่หมอ']);

        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF)->assertRedirect(route('chat.index', ['pick' => 1]));
        // กดซ้ำ (ลิงก์เดิม / กดเบิ้ล) ก็ได้หน้าเดิม
        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF)->assertRedirect(route('chat.index', ['pick' => 1]));

        $this->assertSame(1, ChatConversation::where('user_id', $user->id)->count());
        $this->assertSame(1, $room->messages()->count());
    }

    public function test_after_picking_a_room_the_first_message_carries_thaiprompt_user_id_and_no_handoff(): void
    {
        $user = $this->fbMember(4242);
        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF);

        $this->get(route('chat.index', ['room' => 'maemor']))->assertOk();
        $this->postJson(route('chat.send'), ['message' => 'สวัสดีค่ะ'])->assertOk();

        $start = collect($this->upstreamRequests())->first(fn ($r) => str_ends_with($r->url(), '/server/chat/start'));
        $this->assertNotNull($start, 'ห้องเปิดตอนส่งข้อความแรก');
        $this->assertSame(4242, $start['thaiprompt_user_id']);
        $this->assertSame('maemor', $start['persona'] ?? 'maemor');
        $this->assertNull($start['handoff'] ?? null, 'ไม่ส่งบริบทจากเพจ');
    }

    public function test_non_uuid_link_lands_on_the_room_picker_instead_of_404(): void
    {
        $user = $this->fbMember();

        $this->actingAs($user)->get('/chat/continue/not-a-uuid')->assertRedirect('/chat?pick=1');
        $this->actingAs($user)->get('/chat/continue/')->assertRedirect('/chat?pick=1');
        $this->assertCount(0, $this->upstreamRequests());
    }

    public function test_guest_must_log_in_first(): void
    {
        $this->get('/chat/continue/' . self::HANDOFF)->assertRedirect(route('login'));
        $this->assertCount(0, $this->upstreamRequests());
    }
}
