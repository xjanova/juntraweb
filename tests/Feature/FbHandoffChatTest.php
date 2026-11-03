<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
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
 * เจ้าของสั่ง: FB ห้ามดูดวง/รับเงินในแชทแล้ว ลูกค้าทุกคนย้ายมาเว็บ แม่หมอบนเว็บต้องรู้จักลูกค้า
 * และคุยต่อจากเรื่องที่ทักไว้ในเพจ — ล็อกกติกา:
 *   - เปิดห้องส่ง thaiprompt_user_id + handoff ไปให้ Thaiprompt (ส่งข้อความก็ส่ง thaiprompt_user_id)
 *   - ห้องเดิมมีข้อความลูกค้าแล้ว → เปิดห้องใหม่ ห้องเดิมไม่ถูกแตะ
 *   - handoff เสีย / ใช้แล้ว / Thaiprompt ล่ม / ลิงก์ไม่ใช่ uuid → ไป /chat ไม่ติดทางตัน
 */
class FbHandoffChatTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    private const SESSION = '5b0f7a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

    private const HANDOFF = '9d6f3c1a-2b4e-4f6a-8c0d-1e2f3a4b5c6d';

    /** data ของ /server/chat/start รอบถัดไป (null = Thaiprompt ล่ม 500) */
    private ?array $startData = null;

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

        $this->startData = $this->continued();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => $this->startData === null
                    ? Http::response(['message' => 'ล่ม'], 500)
                    : Http::response(['data' => $this->startData], 201),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => [
                    'session_id' => self::SESSION, 'reply' => 'แม่หมอฟังอยู่ค่ะลูก', 'kind' => 'reply', 'offer_topic' => null,
                ]]),
                default => Http::response(['message' => 'unexpected ' . $url], 500),
            };
        });
    }

    /** คำตอบของ Thaiprompt เมื่อ handoff ใช้ได้ — คำทักทายต่อจากเพจ + การ์ดหัวข้อความรัก */
    private function continued(): array
    {
        return [
            'session_id'     => self::SESSION,
            'greeting'       => 'น้องฝนลูก แม่หมอรับเรื่องความรักที่ลูกทักมาในเพจไว้แล้วนะคะ ✨',
            'persona'        => 'maemor',
            'display_name'   => 'แม่หมอจันทรา',
            'offer_topic'    => 'love',
            'continued_from' => 'facebook',
            'handoff_id'     => self::HANDOFF,
            'handoff_consumed' => true,
            'customer'       => ['display_name' => 'น้องฝน', 'returning' => true],
        ];
    }

    /** handoff เสีย/ใช้แล้ว — Thaiprompt เปิดห้องธรรมดาให้ ไม่มี continued_from */
    private function plain(): array
    {
        return [
            'session_id' => self::SESSION, 'greeting' => 'สวัสดีค่ะลูก', 'persona' => 'maemor', 'display_name' => 'แม่หมอจันทรา',
            'offer_topic' => null, 'continued_from' => null, 'handoff_id' => null, 'customer' => ['display_name' => null, 'returning' => false],
        ];
    }

    private function fbMember(?int $tpId = 4242): User
    {
        return User::factory()->create([
            'thaiprompt_user_id' => $tpId,
            'thaiprompt_token'   => null,
            'facebook_user_id'   => '6155' . Str::random(10),
        ]);
    }

    /** ห้องสดของ session (chat_token) ที่มีข้อความแล้ว */
    private function liveRoom(User $user, bool $customerSpoke, ?int $readingId = null): ChatConversation
    {
        $room = ChatConversation::create([
            'user_id' => $user->id, 'session_token' => (string) Str::uuid(), 'title' => 'สนทนากับแม่หมอ', 'reading_id' => $readingId,
        ]);
        $room->messages()->create(['role' => 'assistant', 'content' => 'สวัสดีค่ะลูก']);
        if ($customerSpoke) {
            $room->messages()->create(['role' => 'user', 'content' => 'เรื่องงานค่ะแม่หมอ']);
        }

        return $room;
    }

    private function startRequests(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/server/chat/start'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_continue_sends_thaiprompt_user_id_and_handoff_and_draws_offer_card(): void
    {
        $user = $this->fbMember(4242);

        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF)
            ->assertRedirect(route('chat.index'))
            ->assertSessionHas('thaiprompt_chat_session', 'srv:' . self::SESSION);

        $starts = $this->startRequests();
        $this->assertCount(1, $starts);
        $this->assertSame(4242, $starts[0]['thaiprompt_user_id']);
        $this->assertSame(self::HANDOFF, $starts[0]['handoff']);
        $this->assertSame((string) $user->id, $starts[0]['user_ref']);

        $greeting = ChatMessage::where('role', 'assistant')->latest('id')->first();
        $this->assertStringContainsString('ความรักที่ลูกทักมาในเพจ', $greeting->content);
        $this->assertSame('love', $greeting->offer_topic, 'ต้องเก็บ offer_topic เพื่อวาดการ์ดแพ็กเกจทันที');

        // หน้า /chat ใช้ห้องนี้เลย ไม่เปิดห้อง Thaiprompt ซ้อนอีกรอบ (คำทักทายมีแล้ว)
        $page = $this->actingAs($user)->get('/chat')->assertOk();
        $shown = $page->viewData('conversation')->messages;
        $this->assertCount(1, $shown);
        $this->assertSame('love', $shown->first()->offer_topic);
        $this->assertCount(1, $this->startRequests());
        $this->assertSame(1, ChatConversation::where('user_id', $user->id)->count());
    }

    public function test_send_after_continue_uses_handoff_room_and_carries_thaiprompt_user_id(): void
    {
        $user = $this->fbMember(4242);

        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF);
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'แฟนจะกลับมาไหมคะ'])->assertOk();

        $send = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/server/chat/send'))->first()[0];
        $this->assertSame(self::SESSION, $send['session_id'], 'ข้อความต้องไปห้อง Thaiprompt ที่จำเรื่องจากเพจ');
        $this->assertSame(4242, $send['thaiprompt_user_id']);
        $this->assertArrayNotHasKey('handoff', $send->data(), 'handoff ใช้ครั้งเดียวตอนเปิดห้องเท่านั้น');
    }

    public function test_busy_room_gets_a_fresh_room_and_old_room_is_untouched(): void
    {
        $user = $this->fbMember();
        $old = $this->liveRoom($user, customerSpoke: true);

        $this->actingAs($user)->withSession([
            'chat_token' => $old->session_token,
            'thaiprompt_chat_session' => 'srv:old-session',
        ])->get('/chat/continue/' . self::HANDOFF)
            ->assertRedirect(route('chat.index'))
            ->assertSessionHas('thaiprompt_chat_session', 'srv:' . self::SESSION);

        $rooms = ChatConversation::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $rooms);
        $fresh = $rooms->last();
        $this->assertNotSame($old->session_token, $fresh->session_token);
        $this->assertSame('คุยต่อจากเพจ', $fresh->title);
        $this->assertSame(session('chat_token'), $fresh->session_token, 'ห้องใหม่ต้องเป็นห้องสดของ session');
        $this->assertSame(2, $old->messages()->count(), 'ห้องเดิมต้องไม่ถูกแตะ');
        $this->assertSame('love', $fresh->messages()->first()->offer_topic);
    }

    public function test_room_with_only_a_greeting_is_reused(): void
    {
        $user = $this->fbMember();
        $live = $this->liveRoom($user, customerSpoke: false);

        $this->actingAs($user)->withSession(['chat_token' => $live->session_token])
            ->get('/chat/continue/' . self::HANDOFF)
            ->assertRedirect(route('chat.index'));

        $this->assertSame(1, ChatConversation::where('user_id', $user->id)->count(), 'ไม่ทิ้งห้องเปล่าไว้ในประวัติ');
        $this->assertSame('love', $live->messages()->get()->last()->offer_topic);
    }

    public function test_reading_room_is_never_reused_for_fb_continuation(): void
    {
        $user = $this->fbMember();
        $reading = Reading::create(['user_id' => $user->id, 'session_token' => 'deep', 'type' => 'deep', 'result' => 'ดวงเชิงลึก']);
        $live = $this->liveRoom($user, customerSpoke: false, readingId: $reading->id);

        $this->actingAs($user)->withSession(['chat_token' => $live->session_token])
            ->get('/chat/continue/' . self::HANDOFF);

        $this->assertSame(2, ChatConversation::where('user_id', $user->id)->count());
        $this->assertNull(ChatConversation::where('user_id', $user->id)->latest('id')->first()->reading_id);
        $this->assertSame(1, $live->messages()->count());
    }

    public function test_used_or_broken_handoff_goes_to_chat_without_touching_rooms(): void
    {
        $this->startData = $this->plain();
        $user = $this->fbMember();
        $old = $this->liveRoom($user, customerSpoke: true);

        $this->actingAs($user)->withSession([
            'chat_token' => $old->session_token,
            'thaiprompt_chat_session' => 'srv:old-session',
        ])->get('/chat/continue/' . self::HANDOFF)
            ->assertRedirect(route('chat.index'))
            ->assertSessionHas('thaiprompt_chat_session', 'srv:old-session')
            ->assertSessionHas('chat_token', $old->session_token);

        $this->assertSame(1, ChatConversation::where('user_id', $user->id)->count());
        $this->assertSame(2, $old->messages()->count());

        // ไปต่อที่ /chat ได้จริง ไม่ติดทางตัน
        $this->actingAs($user)->get('/chat')->assertOk();
    }

    public function test_thaiprompt_down_goes_to_chat(): void
    {
        $this->startData = null;
        $user = $this->fbMember();

        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF)->assertRedirect(route('chat.index'));
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_double_tap_second_request_is_a_no_op(): void
    {
        $user = $this->fbMember();

        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF);
        // Thaiprompt consume ไปแล้ว — ครั้งที่สองได้ห้องธรรมดา
        $this->startData = $this->plain();
        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF)->assertRedirect(route('chat.index'));

        $this->assertSame(1, ChatConversation::where('user_id', $user->id)->count());
        $this->assertSame(1, ChatMessage::count(), 'คำทักทายต้องไม่ซ้ำ');
    }

    public function test_returning_customer_reopening_a_used_link_gets_no_new_room_and_no_repeat_greeting(): void
    {
        $user = $this->fbMember();

        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF);
        $room = ChatConversation::where('user_id', $user->id)->sole();
        $room->messages()->create(['role' => 'user', 'content' => 'แฟนจะกลับมาไหมคะ']);

        // ลูกค้าประจำ: Thaiprompt ยังตอบ continued_from=facebook (มีความจำในเพจ) แต่ลิงก์นี้ถูกใช้ไปแล้ว
        $this->startData = array_merge($this->continued(), [
            'greeting' => 'แม่หมออยู่ตรงนี้แล้วค่ะลูก ✨', 'offer_topic' => null, 'handoff_id' => null, 'handoff_consumed' => false,
        ]);
        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF)
            ->assertRedirect(route('chat.index'))
            ->assertSessionHas('chat_token', $room->session_token);

        $this->assertSame(1, ChatConversation::where('user_id', $user->id)->count(), 'ห้ามเปิดห้องใหม่จากลิงก์เดิม');
        $this->assertSame(1, ChatMessage::where('role', 'assistant')->count(), 'คำทักทายต้องไม่ซ้ำ');

        // Thaiprompt รุ่นเก่า (ไม่มี handoff_consumed) → เทียบ handoff_id แทน
        $old = $this->continued();
        unset($old['handoff_consumed']);
        $old['handoff_id'] = null;
        $this->startData = $old;
        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF)->assertRedirect(route('chat.index'));
        $this->assertSame(1, ChatConversation::where('user_id', $user->id)->count());
    }

    public function test_non_uuid_link_redirects_to_chat_instead_of_404(): void
    {
        $user = $this->fbMember();

        $this->actingAs($user)->get('/chat/continue/not-a-uuid')->assertRedirect('/chat');
        $this->actingAs($user)->get('/chat/continue/')->assertRedirect('/chat');
        $this->assertCount(0, $this->startRequests());
    }

    public function test_guest_must_log_in_first(): void
    {
        $this->get('/chat/continue/' . self::HANDOFF)->assertRedirect(route('login'));
        $this->assertCount(0, $this->startRequests());
    }

    public function test_unlinked_user_sends_no_thaiprompt_user_id(): void
    {
        $user = $this->fbMember(null);

        $this->actingAs($user)->get('/chat/continue/' . self::HANDOFF);

        $start = $this->startRequests()[0];
        $this->assertArrayNotHasKey('thaiprompt_user_id', $start->data());
        $this->assertSame(self::HANDOFF, $start['handoff']);
    }
}
