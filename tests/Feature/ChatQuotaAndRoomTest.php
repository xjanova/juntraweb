<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Setting;
use App\Models\User;
use App\Support\ChatPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ล็อกบั๊กระดับเงิน/ข้อมูลของระบบแชทไว้ 3 ตัว:
 *
 *  1. ห้องสนทนาแตกเป็นสองแถวหลังล็อกอิน — index() หาห้องด้วยคู่
 *     (session_token, user_id) แต่ send() หาด้วย session_token อย่างเดียว
 *     ข้อความจึงถูกเขียนลงห้อง guest ที่หน้าจอไม่ได้ render → รีเฟรชแล้วแชท
 *     หายเกลี้ยง ประวัติไม่ขึ้นใน /account/chats และตัวนับโควตานับไม่เจอ
 *     (= คุยฟรีได้ไม่จำกัดถาวร)
 *
 *  2. เพดานคุยฟรีรายวันมีเฉพาะฝั่งเว็บ — API มือถือไม่เช็คเลย ผู้ใช้แอพจึง
 *     ยิง AI ฟรีได้ไม่จำกัด และยังไปกินโควตาของเว็บ
 *
 *  3. โควตานับรวมทุกช่องทาง (เว็บ + แอพ) เพราะเป็นคนเดียวกัน ต้นทุนก้อนเดียวกัน
 */
class ChatQuotaAndRoomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ต้อง fake upstream เสมอ ไม่งั้นการยิงจริงจะได้ 401 แล้ว FortuneBotClient
        // จะล้าง thaiprompt_token ทิ้ง (พฤติกรรมที่ถูกของ prod) ทำให้ gate ตีกลับ
        // เป็น "session หมดอายุ" และเทสต์ล้มด้วยเหตุผลที่ไม่เกี่ยวกับสิ่งที่ทดสอบ
        Http::fake([
            '*/chat/mae-mor/start' => Http::response(['data' => ['session_id' => 'sess-test']], 200),
            '*/chat/mae-mor/send'  => Http::response(['data' => ['reply' => 'แม่หมอรับฟังอยู่ค่ะลูก']], 200),
            '*'                    => Http::response([], 200),
        ]);
    }

    private function freeMode(int $limit = 3): void
    {
        Setting::put('pricing_chat_message', '0', 'pricing', false);
        Setting::put('chat_daily_limit', (string) $limit, 'chat', false);
        Cache::flush();
    }

    private function member(): User
    {
        return User::factory()->create([
            'thaiprompt_token' => 'tok-'.Str::random(6),
        ]);
    }

    /**
     * เส้นทางที่ผู้ใช้เจอจริง: เปิด /chat ตอนยังไม่ล็อกอิน (สร้างห้อง guest)
     * → ล็อกอิน → เปิด /chat อีกครั้ง → ส่งข้อความ
     * ข้อความต้องอยู่ในห้องเดียวกับที่หน้าจอ render และต้องถูกนับโควตา
     */
    public function test_message_lands_in_the_room_the_page_renders_after_login(): void
    {
        $this->freeMode();

        // 1) guest เปิดหน้าแชท → เกิด chat_token + ห้อง user_id = null
        $this->get('/chat')->assertOk();
        $this->assertSame(1, ChatConversation::whereNull('user_id')->count());

        // 2) ล็อกอินแล้วกลับมาหน้าแชท (session เดิม → chat_token เดิม)
        $user = $this->member();
        $this->actingAs($user)->get('/chat')->assertOk();

        // ห้อง guest ต้องถูก "ยึด" มาเป็นของเจ้าของ ไม่ใช่ทิ้งไว้แล้วสร้างใหม่
        $this->assertSame(0, ChatConversation::whereNull('user_id')->count());
        $this->assertSame(1, ChatConversation::where('user_id', $user->id)->count());

        // 3) ส่งข้อความ
        $this->actingAs($user)
            ->postJson('/chat/send', ['message' => 'สวัสดีค่ะแม่หมอ'])
            ->assertOk();

        $room = ChatConversation::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue(
            ChatMessage::where('chat_conversation_id', $room->id)
                ->where('role', 'user')->exists(),
            'ข้อความของผู้ใช้ต้องอยู่ในห้องที่ผูกกับเจ้าของ ไม่ใช่ห้องกำพร้า',
        );

        // และต้องถูกนับโควตา (เดิมนับไม่เจอ = bypass เพดานถาวร)
        $this->assertSame(1, ChatPolicy::dailyUsed($user));
    }

    /** ครบเพดานแล้วเว็บต้องตอบ 429 พร้อม reason_code ให้ UI สลับเป็นปุ่มทางออก */
    public function test_web_send_blocks_at_daily_limit(): void
    {
        $this->freeMode(limit: 2);
        $user = $this->member();

        $this->actingAs($user)->get('/chat')->assertOk();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ข้อความ 1'])->assertOk();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ข้อความ 2'])->assertOk();

        $this->actingAs($user)
            ->postJson('/chat/send', ['message' => 'ข้อความ 3'])
            ->assertStatus(429)
            ->assertJsonPath('reason_code', 'daily_limit')
            ->assertJsonPath('daily_left', 0);
    }

    /** เพดานเดียวกันต้องบังคับกับ API มือถือด้วย — เดิมแอพยิงฟรีไม่จำกัด */
    public function test_mobile_api_enforces_the_same_daily_limit(): void
    {
        $this->freeMode(limit: 1);
        $user = $this->member();

        $convo = ChatConversation::create([
            'user_id'       => $user->id,
            'session_token' => (string) Str::uuid(),
            'title'         => 'สนทนากับแม่หมอ',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'ข้อความแรก'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'ข้อความที่สอง'])
            ->assertStatus(429)
            ->assertJsonPath('reason_code', 'daily_limit');
    }

    /** โควตาเป็นก้อนเดียวกันทั้งเว็บและแอพ — คุยบนเว็บแล้วแอพต้องเหลือน้อยลง */
    public function test_quota_is_shared_between_web_and_mobile(): void
    {
        $this->freeMode(limit: 2);
        $user = $this->member();

        $this->actingAs($user)->get('/chat')->assertOk();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'คุยบนเว็บ'])->assertOk();

        $convo = ChatConversation::create([
            'user_id'       => $user->id,
            'session_token' => (string) Str::uuid(),
            'title'         => 'จากแอพ',
        ]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'คุยบนแอพ'])
            ->assertOk();

        // ใช้ไป 2 จาก 2 → เหลือ 0
        $res->assertJsonPath('data.daily_left', 0);
    }

    /**
     * ค่าเริ่มต้น = ไม่จำกัด (คุยฟรีเหมือนแชทกับบอทแม่หมอใน Facebook)
     * ตามที่เจ้าของกำหนด — ห้ามมีเพดานโผล่มาเองถ้าแอดมินไม่ได้ตั้งค่า
     */
    public function test_free_chat_is_unlimited_by_default(): void
    {
        Setting::put('pricing_chat_message', '0', 'pricing', false);
        Cache::flush(); // ไม่ตั้ง chat_daily_limit เลย = ใช้ค่าเริ่มต้น

        $user = $this->member();

        $this->assertSame(0, ChatPolicy::dailyLimit(), 'ค่าเริ่มต้นต้องเป็นไม่จำกัด');
        $this->assertNull(ChatPolicy::dailyLeft($user));
        $this->assertFalse(ChatPolicy::exhausted($user));

        $this->actingAs($user)->get('/chat')->assertOk();
        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($user)
                ->postJson('/chat/send', ['message' => "ข้อความที่ {$i}"])
                ->assertOk();
        }
    }

    /** แอดมินตั้งเพดานเมื่อไหร่ ต้องมีผลทันทีทั้งเว็บและแอพ */
    public function test_admin_can_cap_the_daily_limit_later(): void
    {
        Setting::put('pricing_chat_message', '0', 'pricing', false);
        Cache::flush();
        $user = $this->member();

        $this->assertSame(0, ChatPolicy::dailyLimit());

        // แอดมินตั้งเพดานเป็น 1
        Setting::put('chat_daily_limit', '1', 'chat', false);
        Cache::flush();

        $this->assertSame(1, ChatPolicy::dailyLimit());

        $this->actingAs($user)->get('/chat')->assertOk();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ข้อความแรก'])->assertOk();
        $this->actingAs($user)
            ->postJson('/chat/send', ['message' => 'ข้อความที่สอง'])
            ->assertStatus(429)
            ->assertJsonPath('reason_code', 'daily_limit');
    }

    /** คำตอบต้องมาพร้อม HTML ที่ render markdown แล้ว ไม่ใช่ให้ client เดาเอง */
    public function test_web_send_returns_rendered_html_and_quota_state(): void
    {
        $this->freeMode(limit: 5);
        $user = $this->member();

        $this->actingAs($user)->get('/chat')->assertOk();

        $res = $this->actingAs($user)
            ->postJson('/chat/send', ['message' => 'ขอคำแนะนำหน่อยค่ะ'])
            ->assertOk()
            ->assertJsonStructure(['reply', 'reply_html', 'daily_limit', 'daily_left', 'awaiting', 'suggestions']);

        $this->assertSame(4, $res->json('daily_left'));
    }
}
