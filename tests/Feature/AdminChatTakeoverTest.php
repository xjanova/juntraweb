<?php

namespace Tests\Feature;

use App\Filament\Pages\ChatConsole;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\ChatPolicy;
use App\Support\ChatTakeover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 🧑‍💼 (2026-11-09) แอดมินคุยกับลูกค้าในห้องผู้พยากรณ์ — บอทต้องหยุดระหว่างที่แอดมินคุม
 *
 * สิ่งที่ล็อกไว้:
 *  - แอดมินพิมพ์ = บอทหยุด · ปุ่มเทคบอท = หยุดโดยไม่ต้องพิมพ์ · คืนให้บอท = บอทตอบต่อ (พร้อมสรุปที่แอดมินคุยไป)
 *  - ระหว่างหยุด ข้อความลูกค้าไม่ไปถึง AI (ทั้งเว็บและแอพ) ไม่คิดเงิน ไม่กินโควตาคุยฟรี
 *  - คำตอบของบอทที่กำลังมาตอนแอดมินกดเทค = ทิ้ง ไม่คิดเงิน
 *  - แอดมินเงียบเกินเวลาที่ตั้ง = บอทกลับมาเอง
 *  - คนที่ไม่ใช่แอดมิน (สมาชิก / editor) เข้าไม่ได้ · ลูกค้าอ่าน feed ได้แค่ห้องตัวเอง
 */
class AdminChatTakeoverTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    /** ระหว่าง AI กำลังคิด ให้เรียกอันนี้ก่อนตอบ (จำลองแอดมินกดเทคบอทกลางคัน) */
    private ?\Closure $duringReply = null;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        // ข้อความละ ฿2 ไม่มีโควตาฟรี — เห็นชัดว่าระหว่างแอดมินคุมห้องไม่โดนหักเงิน
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '1', 'pricing', false);
        Cache::flush();

        Http::fake(function (Request $req) {
            $url = $req->url();
            if (str_ends_with($url, '/server/chat/send') && $this->duringReply) {
                ($this->duringReply)();
            }

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => ['session_id' => 'sess-1', 'reply' => 'แม่หมอรับฟังอยู่ค่ะ', 'kind' => 'reply']]),
                default => Http::response(['message' => 'unexpected ' . $url], 500),
            };
        });
    }

    private function customer(float $credit = 100): User
    {
        $u = User::factory()->create(['thaiprompt_token' => null]);
        if ($credit > 0) {
            app(WalletService::class)->credit($u, $credit, 'เครดิตทดสอบ');
        }

        return $u;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'แอดมินหนึ่ง']);
    }

    /** จำนวนครั้งที่ถาม AI (Thaiprompt /server/chat/send) */
    private function botCalls(): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/server/chat/send'))->count();
    }

    private function lastBotPayload(): array
    {
        return collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/server/chat/send'))->last()[0]->data();
    }

    /** ลูกค้าคุยกับบอทหนึ่งข้อความ → ได้ห้องของลูกค้า */
    private function roomOf(User $customer): ChatConversation
    {
        $this->actingAs($customer)->postJson('/chat/send', ['message' => 'สวัสดีค่ะ', 'room' => 'maemor'])
            ->assertOk()->assertJsonPath('reply', 'แม่หมอรับฟังอยู่ค่ะ');

        return ChatConversation::where('user_id', $customer->id)->firstOrFail();
    }

    public function test_admin_message_pauses_the_bot_and_reaches_the_customer(): void
    {
        $customer = $this->customer();
        $room = $this->roomOf($customer);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/admin-chat/{$room->id}/say", ['message' => 'สวัสดีค่ะ แอดมินมาดูแลเองนะคะ', 'as' => 'oracle'])
            ->assertOk()
            ->assertJsonPath('state.paused', true)
            ->assertJsonPath('message.role', 'admin');

        $this->assertNotNull($room->fresh()->bot_paused_at);
        $this->assertSame($admin->id, $room->fresh()->bot_paused_by);

        // ลูกค้าเห็นข้อความของแอดมินในห้อง — "คุยแทนแม่หมอ" = เห็นเป็นแม่หมอพูด (ไม่มีป้ายแอดมิน)
        $feed = $this->actingAs($customer)->getJson("/chat/feed/{$room->id}?after=0")->assertOk();
        $feed->assertJsonPath('held', true);
        $this->assertSame(['assistant'], collect($feed->json('messages'))->pluck('role')->all());
        $this->assertSame('สวัสดีค่ะ แอดมินมาดูแลเองนะคะ', $feed->json('messages.0.text'));

        // พูดเป็นแอดมิน = ลูกค้าเห็นป้ายแอดมิน
        $this->actingAs($admin)->postJson("/admin-chat/{$room->id}/say", ['message' => 'ทีมงานค่ะ', 'as' => 'admin'])->assertOk();
        $feed = $this->actingAs($customer)->getJson("/chat/feed/{$room->id}?after=" . $feed->json('messages.0.mid'))->assertOk();
        $this->assertSame(['admin'], collect($feed->json('messages'))->pluck('role')->all());
    }

    public function test_bot_does_not_reply_or_charge_while_paused(): void
    {
        $customer = $this->customer();
        $room = $this->roomOf($customer);
        $calls = $this->botCalls();
        $balance = app(WalletService::class)->balance($customer);

        ChatTakeover::pause($room, $this->admin());

        $res = $this->actingAs($customer)->postJson('/chat/send', ['message' => 'ขอคุยกับคนจริงค่ะ', 'room' => 'maemor'])->assertOk();
        $res->assertJsonPath('held', true)->assertJsonPath('reply', null)->assertJsonPath('cost', 0);

        $this->assertSame($calls, $this->botCalls(), 'ระหว่างแอดมินคุมห้อง ห้ามถาม AI');
        $this->assertEquals($balance, app(WalletService::class)->balance($customer), 'ระหว่างแอดมินคุมห้อง ห้ามหักเงิน');
        $last = $room->messages()->get()->last();
        $this->assertSame('user', $last->role, 'ข้อความของลูกค้าต้องอยู่ในห้องให้แอดมินอ่าน และไม่มีคำตอบจากบอท');
        $this->assertSame('ขอคุยกับคนจริงค่ะ', $last->content);
        $this->assertSame(ChatMessage::HELD, $last->admin_as);
    }

    public function test_customer_out_of_credit_or_quota_can_still_talk_to_the_admin(): void
    {
        Setting::put('pricing_chat_message_enabled', '0', 'pricing', false);
        Setting::put('chat_daily_limit', '1', 'chat', false);
        Cache::flush();
        $customer = $this->customer(0);
        $room = $this->roomOf($customer);   // ใช้โควตาฟรีวันนี้หมดแล้ว
        $this->assertTrue(ChatPolicy::exhausted($customer));

        ChatTakeover::pause($room, $this->admin());

        $this->actingAs($customer)->postJson('/chat/send', ['message' => 'ยังอยู่ค่ะ', 'room' => 'maemor'])
            ->assertOk()->assertJsonPath('held', true);
        $this->assertSame(1, ChatPolicy::dailyUsed($customer), 'ข้อความที่คุยกับแอดมินไม่กินโควตาคุยฟรี');
    }

    public function test_take_over_button_pauses_the_bot_without_typing(): void
    {
        $customer = $this->customer();
        $room = $this->roomOf($customer);
        $calls = $this->botCalls();

        $this->actingAs($this->admin())->postJson("/admin-chat/{$room->id}/take")
            ->assertOk()->assertJsonPath('state.paused', true);
        $this->assertSame(0, $room->messages()->whereNotNull('admin_id')->count(), 'กดเทคบอทเฉย ๆ ไม่มีข้อความแอดมิน');

        $this->actingAs($customer)->postJson('/chat/send', ['message' => 'มีใครอยู่ไหม', 'room' => 'maemor'])
            ->assertOk()->assertJsonPath('held', true);
        $this->assertSame($calls, $this->botCalls());
    }

    public function test_release_button_resumes_the_bot_with_what_the_admin_said(): void
    {
        $customer = $this->customer();
        $room = $this->roomOf($customer);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/admin-chat/{$room->id}/say", ['message' => 'ลดให้ครึ่งราคานะคะ'])->assertOk();
        $this->actingAs($customer)->postJson('/chat/send', ['message' => 'ขอบคุณค่ะ', 'room' => 'maemor'])->assertJsonPath('held', true);

        $this->actingAs($admin)->postJson("/admin-chat/{$room->id}/release")
            ->assertOk()->assertJsonPath('state.paused', false);
        $this->assertNull($room->fresh()->bot_paused_at);

        $balance = app(WalletService::class)->balance($customer);
        $this->actingAs($customer)->postJson('/chat/send', ['message' => 'ฟังแล้วสบายใจขึ้นเยอะเลยค่ะ', 'room' => 'maemor'])
            ->assertOk()->assertJsonPath('reply', 'แม่หมอรับฟังอยู่ค่ะ');
        $this->assertEquals($balance - 2, app(WalletService::class)->balance($customer), 'บอทกลับมาตอบ = คิดเงินตามปกติ');

        // บอทได้สรุปว่าทีมงานคุยอะไรไป — ไม่ขัดกับที่แอดมินรับปาก
        $context = (string) ($this->lastBotPayload()['context'] ?? '');
        $this->assertStringContainsString('ลดให้ครึ่งราคานะคะ', $context);
        $this->assertStringContainsString('ทีมงาน', $context);

        // ตอบไปแล้วหนึ่งครั้ง — รอบถัดไปไม่ส่งสรุปซ้ำ
        $this->actingAs($customer)->postJson('/chat/send', ['message' => 'อีกเรื่องค่ะ', 'room' => 'maemor'])->assertOk();
        $this->assertStringNotContainsString('ลดให้ครึ่งราคา', (string) ($this->lastBotPayload()['context'] ?? ''));
    }

    public function test_bot_reply_in_flight_is_dropped_when_admin_takes_over(): void
    {
        $customer = $this->customer();
        $room = $this->roomOf($customer);
        $admin = $this->admin();
        $balance = app(WalletService::class)->balance($customer);
        $before = $room->messages()->where('role', 'assistant')->count();

        // แอดมินกดเทคบอทระหว่างที่ AI กำลังคิดคำตอบ
        $this->duringReply = fn () => ChatTakeover::pause($room, $admin);

        $this->actingAs($customer)->postJson('/chat/send', ['message' => 'เรื่องงานค่ะ', 'room' => 'maemor'])
            ->assertOk()->assertJsonPath('held', true)->assertJsonPath('reply', null);

        $this->assertSame($before, $room->messages()->where('role', 'assistant')->count(), 'คำตอบที่มาหลังแอดมินเทค ต้องไม่ถูกบันทึก');
        $this->assertEquals($balance, app(WalletService::class)->balance($customer), 'และไม่คิดเงิน');
        $this->assertSame(ChatMessage::HELD, $room->messages()->get()->last()->admin_as);
    }

    public function test_idle_timeout_gives_the_room_back_to_the_bot(): void
    {
        ChatTakeover::setIdleMinutes(10);
        $customer = $this->customer();
        $room = $this->roomOf($customer);

        ChatTakeover::pause($room, $this->admin());
        $this->travel(11)->minutes();

        $this->actingAs($customer)->postJson('/chat/send', ['message' => 'ยังอยู่ไหมคะ', 'room' => 'maemor'])
            ->assertOk()->assertJsonPath('reply', 'แม่หมอรับฟังอยู่ค่ะ');
        $this->assertNull($room->fresh()->bot_paused_at);
    }

    public function test_zero_idle_minutes_never_auto_resumes(): void
    {
        ChatTakeover::setIdleMinutes(0);
        $room = $this->roomOf($this->customer());
        ChatTakeover::pause($room, $this->admin());
        $this->travel(3)->days();

        $this->assertTrue(ChatTakeover::isPaused($room));
    }

    public function test_mobile_app_send_is_held_while_paused(): void
    {
        $customer = $this->customer();
        $room = $this->roomOf($customer);
        $calls = $this->botCalls();
        ChatTakeover::pause($room, $this->admin());

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/chat/conversations/{$room->id}/send", ['message' => 'จากแอพค่ะ'])
            ->assertOk()->assertJsonPath('data.held', true)->assertJsonPath('data.message', null);

        $this->assertSame($calls, $this->botCalls());
        $this->assertSame('จากแอพค่ะ', $room->messages()->get()->last()->content);
    }

    public function test_non_admins_are_blocked(): void
    {
        $customer = $this->customer();
        $room = $this->roomOf($customer);
        $editor = User::factory()->create(['role' => 'editor']);

        foreach ([$customer, $editor] as $who) {
            $this->actingAs($who)->get("/admin-chat/{$room->id}")->assertForbidden();
            $this->actingAs($who)->getJson("/admin-chat/{$room->id}/feed")->assertForbidden();
            $this->actingAs($who)->postJson("/admin-chat/{$room->id}/say", ['message' => 'แอบพิมพ์'])->assertForbidden();
            $this->actingAs($who)->postJson("/admin-chat/{$room->id}/take")->assertForbidden();
            $this->actingAs($who)->postJson("/admin-chat/{$room->id}/release")->assertForbidden();
        }
        $this->actingAs($editor)->get('/admin/chat-console')->assertForbidden();
        $this->assertNull($room->fresh()->bot_paused_at);
        $this->assertSame(0, $room->messages()->whereNotNull('admin_id')->count());
    }

    public function test_guests_are_sent_to_login(): void
    {
        $room = ChatConversation::create(['user_id' => $this->customer()->id, 'session_token' => 'tok-guest-test']);

        $this->get("/admin-chat/{$room->id}")->assertRedirect(route('login'));
        $this->postJson("/admin-chat/{$room->id}/say", ['message' => 'x'])->assertUnauthorized();
        $this->getJson("/chat/feed/{$room->id}")->assertUnauthorized();
        $this->assertSame(0, $room->messages()->count());
    }

    public function test_customer_feed_is_only_for_the_room_owner(): void
    {
        $room = $this->roomOf($this->customer());
        $other = $this->customer();

        $this->actingAs($other)->getJson("/chat/feed/{$room->id}")->assertForbidden();
    }

    public function test_admin_sees_the_customer_room_and_console(): void
    {
        $customer = $this->customer();
        $room = $this->roomOf($customer);
        $admin = $this->admin();
        $this->actingAs($admin)->postJson("/admin-chat/{$room->id}/say", ['message' => 'ข้อความจากแอดมิน'])->assertOk();

        $this->actingAs($admin)->get("/admin-chat/{$room->id}")
            ->assertOk()
            ->assertSee('เทคบอท')
            ->assertSee('คืนให้บอท')
            ->assertSee('คุยแทน', false)
            ->assertSee('ข้อความจากแอดมิน');

        // หลังบ้านเห็นทุกข้อความใหม่ (ลูกค้า บอท แอดมิน) แยกชัด
        $feed = $this->actingAs($admin)->getJson("/admin-chat/{$room->id}/feed?after=0")->assertOk();
        $this->assertSame(['user', 'assistant', 'admin'], collect($feed->json('messages'))->pluck('role')->all());

        $this->actingAs($admin)->get('/admin/chat-console')->assertOk()->assertSee($customer->name);
        Livewire::actingAs($admin)->test(ChatConsole::class)
            ->call('open', $room->id)
            ->assertSet('openId', $room->id)
            ->assertSee(route('admin-chat.room', $room->id), false);
    }
}
