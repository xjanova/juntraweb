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
 * 🎁 (2026-07-28) โหมด "คุยฟรี N ข้อความต่อวัน แล้วค่อยหักเครดิตต่อข้อความ"
 *
 * เจ้าของสั่ง: ห้องแชทบนเว็บเป็นหน้าหลักที่รับคนจาก FB/LINE — ให้คุยฟรี 10 ข้อความ
 * ต่อวันก่อน เกินกว่านั้นค่อยคิดเงินต่อข้อความ (ตั้งค่าในแอดมิน)
 *
 * ล็อกกติกาที่พลาดแล้วเสียเงิน/เสียลูกค้า:
 *
 *  1. เดิม `dailyLimit()` คืน 0 ทันทีเมื่อราคา > 0 → ตั้งราคาเมื่อไหร่ โควตาฟรีหายเกลี้ยง
 *     ลูกค้าโดนหักตั้งแต่ข้อความแรก ทั้งที่แอดมินตั้ง "ฟรี 10" ไว้
 *  2. ข้อความที่ N พอดี (ตัวสุดท้ายของโควตา) ต้องยังฟรี — ถ้าอ่านราคาหลังบันทึก
 *     ข้อความ ตัวนับจะรวมข้อความนี้ด้วยแล้วคิดเงินเร็วไป 1 ข้อความ
 *  3. ครบโควตาในโหมดคิดเงิน **ห้ามบล็อก** — คนพร้อมจ่ายต้องคุยต่อได้
 *     (โหมดฟรีล้วนเท่านั้นที่บล็อก เพราะไม่มีอะไรให้จ่ายเพื่อคุยต่อ)
 */
class ChatFreeThenPaidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/chat/mae-mor/start' => Http::response(['data' => ['session_id' => 'sess-test']], 200),
            '*/chat/mae-mor/send' => Http::response(['data' => ['reply' => 'แม่หมอรับฟังอยู่ค่ะลูก']], 200),
            '*' => Http::response([], 200),
        ]);
    }

    /** ฟรี N ต่อวัน แล้วคิดเงินต่อข้อความ */
    private function freeThenPaid(int $freeQuota = 3, string $price = '2'): void
    {
        Setting::put('pricing_chat_message', $price, 'pricing', false);
        Setting::put('chat_daily_limit', (string) $freeQuota, 'chat', false);
        Cache::flush();
    }

    /**
     * ลูกค้าที่มาจาก FB DM ผ่าน Magic Link — Thaiprompt ส่ง PSID มาให้ตอน SSO
     * (`/api/user` ยึด `users.facebook_psid` แล้ว) juntraweb จึงเก็บเป็น facebook_user_id
     */
    private function member(): User
    {
        return User::factory()->create([
            'thaiprompt_token' => 'tok-'.Str::random(6),
            'facebook_user_id' => '6155'.Str::random(10),
        ]);
    }

    /** บัญชีที่ล็อกอินด้วยอีเมลเฉย ๆ — ไม่ได้มาจาก FB/LINE */
    private function unlinkedMember(): User
    {
        return User::factory()->create([
            'thaiprompt_token' => 'tok-'.Str::random(6),
            'facebook_user_id' => null,
            'line_user_id' => null,
        ]);
    }

    /** ยัดข้อความของผู้ใช้เข้าไปในวันนี้ N ข้อความ (จำลองว่าคุยมาแล้ว) */
    private function usedToday(User $user, int $count): void
    {
        $convo = ChatConversation::create([
            'user_id' => $user->id,
            'session_token' => Str::random(20),
        ]);

        for ($i = 0; $i < $count; $i++) {
            ChatMessage::create([
                'chat_conversation_id' => $convo->id,
                'role' => 'user',
                'content' => "ข้อความที่ {$i}",
            ]);
        }
    }

    public function test_ยังอยู่ในโควตา_ต้องไม่คิดเงิน(): void
    {
        $this->freeThenPaid(3, '2');
        $user = $this->member();

        $this->assertSame(0.0, ChatPolicy::costFor($user), 'ข้อความแรกต้องฟรี');

        $this->usedToday($user, 2);
        $this->assertSame(0.0, ChatPolicy::costFor($user), 'ข้อความที่ 3 (ตัวสุดท้ายของโควตา) ต้องยังฟรี');
    }

    public function test_ใช้ครบโควตาแล้วเริ่มคิดเงิน(): void
    {
        $this->freeThenPaid(3, '2');
        $user = $this->member();

        $this->usedToday($user, 3);

        $this->assertSame(2.0, ChatPolicy::costFor($user), 'ข้อความที่ 4 ต้องเริ่มหักเครดิต');
    }

    public function test_ครบโควตาในโหมดคิดเงิน_ต้องไม่ถูกบล็อก(): void
    {
        $this->freeThenPaid(3, '2');
        $user = $this->member();

        $this->usedToday($user, 5);

        $this->assertFalse(
            ChatPolicy::exhausted($user),
            'คนที่พร้อมจ่ายต้องคุยต่อได้ — บล็อกตรงนี้คือปิดปากลูกค้าที่จะจ่ายเงิน'
        );
    }

    public function test_โหมดฟรีล้วน_ครบโควตาแล้วต้องบล็อกเหมือนเดิม(): void
    {
        Setting::put('pricing_chat_message', '0', 'pricing', false);
        Setting::put('chat_daily_limit', '3', 'chat', false);
        Cache::flush();

        $user = $this->member();
        $this->usedToday($user, 3);

        $this->assertTrue(ChatPolicy::exhausted($user), 'ฟรีล้วน + ครบโควตา = หยุดคุยวันนี้ (พฤติกรรมเดิม)');
        $this->assertSame(0.0, ChatPolicy::costFor($user));
    }

    public function test_ไม่ตั้งโควตา_คิดเงินทุกข้อความเหมือนเดิม(): void
    {
        $this->freeThenPaid(0, '2');
        $user = $this->member();

        $this->assertSame(2.0, ChatPolicy::costFor($user), 'ไม่มีโควตาฟรี = หักตั้งแต่ข้อความแรก');
        $this->assertFalse(ChatPolicy::exhausted($user));
    }

    /**
     * เส้นจริง: ส่งข้อความผ่าน /chat/send จนครบโควตา แล้วต้องถูกหักเครดิต
     * และเซิร์ฟเวอร์ต้องบอก client ว่า "ยังไม่ถูกบล็อก"
     */
    public function test_ส่งจริงผ่านเว็บ_ฟรีจนครบแล้วค่อยหักเครดิต(): void
    {
        $this->freeThenPaid(2, '2');
        $user = $this->member();

        // ให้มีเครดิตพอสำหรับข้อความที่เกินโควตา
        app(\App\Services\Wallet\WalletService::class)->credit($user, 100, 'seed');

        $this->actingAs($user);

        // ข้อความที่ 1-2 = ฟรี
        for ($i = 1; $i <= 2; $i++) {
            $res = $this->postJson(route('chat.send'), ['message' => "คำถามที่ {$i} ค่ะแม่หมอ"]);
            $res->assertOk();
            $this->assertSame(0.0, (float) $res->json('cost'), "ข้อความที่ {$i} ต้องฟรี");
            $this->assertFalse($res->json('blocked'), 'ยังไม่ควรถูกบล็อก');
        }

        // ข้อความที่ 3 = เกินโควตา → ต้องคิดเงิน แต่ยังคุยได้
        $res = $this->postJson(route('chat.send'), ['message' => 'ขอถามต่ออีกข้อค่ะแม่หมอ']);
        $res->assertOk();
        $this->assertSame(2.0, (float) $res->json('cost'), 'ข้อความที่ 3 ต้องถูกหักเครดิต');
        $this->assertFalse($res->json('blocked'), 'โหมดคิดเงินห้ามบล็อก');
    }

    /**
     * บัญชีที่ไม่ได้มาจาก FB/LINE ยังคุยฟรีในโควตาได้ (ไม่ถูกกันตั้งแต่ประตู)
     * แต่พอถึงข้อความที่ต้องจ่ายจะถูกขอให้ยืนยันตัวตนก่อน — กติกาเดิมของยุคคิดเงิน
     *
     * ล็อกไว้ให้เห็นชัดว่า **ตั้งใจ** ไม่ใช่หลุด: ถ้าวันหนึ่งอยากให้ทุกคนจ่ายได้
     * โดยไม่ต้องเชื่อมช่องทาง ให้ลบเงื่อนไข isLinkedViaFbOrLine ใน ChatPolicy::gate()
     */
    public function test_บัญชีที่ไม่ได้เชื่อมช่องทาง_ฟรีได้แต่จ่ายไม่ได้(): void
    {
        $this->freeThenPaid(2, '2');
        $user = $this->unlinkedMember();
        app(\App\Services\Wallet\WalletService::class)->credit($user, 100, 'seed');

        $this->actingAs($user);

        // ยังอยู่ในโควตาฟรี → เข้าได้ปกติ
        $this->assertTrue(ChatPolicy::gate($user)['allowed'], 'ในโควตาฟรีต้องไม่ถูกกัน');

        $this->usedToday($user, 2);

        // เกินโควตา = ต้องจ่าย → ถูกขอให้เชื่อมช่องทางก่อน
        $gate = ChatPolicy::gate($user);
        $this->assertFalse($gate['allowed']);
        $this->assertSame('no_link', $gate['code']);
    }
}
