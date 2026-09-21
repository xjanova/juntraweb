<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\TarotReadingCard;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🌙 เจ้าของ (2026-09-15): "ทุกคนที่ล็อกอินคุยได้" + "แชทไม่เสียเงิน จนกว่าจะเริ่มการทำนาย"
 *
 * บน production ก่อนแก้: สมาชิก 4 คน มี token Thaiprompt คนเดียว · 60 วันไม่มีข้อความจากลูกค้าสักข้อความ
 * ลูกค้าเบอร์/อีเมลคุยไม่ได้ จ่ายเปิดไพ่แล้วได้ข้อความประกอบจากความหมายไพ่ ดูดวงเชิงลึกถูกคืนเงินทุกครั้ง
 * ทุกเคสในไฟล์นี้ใช้ลูกค้าที่ "ไม่มี token" — ทางของเว็บเอง (juntra.server) ต้องพาไปได้ทั้งหมด
 */
class MaeMorForEveryoneTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    private const SESSION = '5b0f7a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

    /** คำตอบของ /server/chat/send รอบถัดไป */
    private array $chatReply = ['reply' => 'แม่หมอรับฟังอยู่ค่ะลูก', 'kind' => 'reply', 'offer_topic' => null];

    /** ทางคำทำนายของเว็บล่ม (AI เงียบ) */
    private bool $fortuneDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Setting::put('pricing_tarot_single', '19', 'pricing', false);
        Setting::put('pricing_tarot_career', '29', 'pricing', false);
        Setting::put('pricing_deep', '39', 'pricing', false);
        Cache::flush();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => self::SESSION, 'greeting' => 'สวัสดีค่ะลูก']], 201),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => ['session_id' => self::SESSION] + $this->chatReply]),
                str_ends_with($url, '/server/fortune/tarot/interpret') => $this->fortuneDown
                    ? Http::response(['message' => 'ระบบคำทำนายไม่พร้อมชั่วคราว'], 503)
                    : Http::response(['data' => ['interpretation' => 'ไพ่คนโง่ตั้งตรง — ลูกกำลังจะได้เริ่มต้นสิ่งใหม่ค่ะ', 'ai_provider' => 'gemini', 'ai_model' => 'flash']]),
                str_ends_with($url, '/server/fortune/deep') => Http::response(['data' => ['reading' => 'ดวงเชิงลึกของลูก: การงานเด่นช่วงปลายปีค่ะ', 'ai_provider' => 'gemini', 'ai_model' => 'pro']]),
                default => Http::response(['message' => 'unexpected ' . $url], 500),
            };
        });
    }

    private function freeChat(): void
    {
        // สวิตช์เดียวกับหลังบ้าน "เก็บเงินค่าแชท" = ปิด
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '0', 'pricing', false);
        Cache::flush();
    }

    private function paidChat(): void
    {
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '1', 'pricing', false);
        Setting::put('chat_daily_limit', '0', 'chat', false);
        Cache::flush();
    }

    /** สมาชิกที่สมัครด้วยเบอร์โทร/อีเมล — ไม่มี token Thaiprompt ไม่ได้มาจาก FB/LINE */
    private function phoneMember(float $credit = 100): User
    {
        $user = User::factory()->create(['thaiprompt_token' => null, 'facebook_user_id' => null, 'line_user_id' => null]);
        if ($credit > 0) {
            app(WalletService::class)->credit($user, $credit, 'seed');
        }

        return $user;
    }

    private function balance(User $user): float
    {
        return (float) app(WalletService::class)->balance($user->fresh());
    }

    private function sentTo(string $suffix): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), $suffix))->count();
    }

    /* ─────────────────────────── แชทบนเว็บ ─────────────────────────── */

    public function test_a_phone_member_chats_with_mae_mor_for_free(): void
    {
        $this->freeChat();
        $user = $this->phoneMember();

        $this->actingAs($user)->get(route('chat.index'))->assertOk();
        $this->assertSame('สวัสดีค่ะลูก', ChatMessage::where('role', 'assistant')->value('content'), 'คำทักทายมาจากแม่หมอจริง');

        $res = $this->actingAs($user)->postJson(route('chat.send'), ['message' => 'วันนี้เหนื่อยจังเลยค่ะแม่หมอ'])
            ->assertOk()
            ->assertJsonPath('reply', 'แม่หมอรับฟังอยู่ค่ะลูก')
            ->assertJsonPath('degraded', false)
            ->assertJsonPath('kind', 'reply');

        $this->assertSame(0.0, (float) $res->json('cost'));
        $this->assertSame(100.0, $this->balance($user), 'คุยฟรี = ไม่แตะวอลเลต');
        $this->assertSame(0, $this->sentTo('/chat/mae-mor/send'), 'ไม่ใช้ทาง token ของลูกค้า');

        // ส่งชื่อ + id ฝั่งเว็บไปเป็นเจ้าของห้อง ไม่ใช่ token
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/server/chat/send')
            && $r['user_ref'] === (string) $user->id
            && $r['session_id'] === self::SESSION
            && $r->hasHeader('Authorization', 'Bearer srv-token'));
    }

    public function test_asking_for_a_reading_gets_package_cards_without_asking_the_ai_or_charging(): void
    {
        $this->paidChat();   // แม้ตั้งราคาแชทไว้ ใบเสนอราคาก็ต้องไม่คิดเงิน
        $user = $this->phoneMember();

        $res = $this->actingAs($user)->postJson(route('chat.send'), ['message' => 'ช่วยดูดวงความรักให้หน่อยค่ะ'])
            ->assertOk()
            ->assertJsonPath('kind', 'offer')
            ->assertJsonPath('question', 'ช่วยดูดวงความรักให้หน่อยค่ะ')
            ->assertJsonPath('offers.0.key', 'tarot_love')
            ->assertJsonPath('offers.0.spread', 'love')
            ->assertJsonPath('offers.2.kind', 'deep');

        $this->assertCount(3, $res->json('offers'));
        $this->assertSame(0.0, (float) $res->json('cost'));
        $this->assertSame(100.0, $this->balance($user), 'ใบเสนอแพ็กเกจไม่ใช่คำตอบ — ห้ามหักเงิน');
        $this->assertSame(0, $this->sentTo('/server/chat/send'), 'คำขอดูดวงตรง ๆ ไม่ต้องถาม AI');
        $this->assertSame('love', ChatMessage::where('role', 'assistant')->latest('id')->value('offer_topic'));

        // รีเฟรชแล้วการ์ดยังอยู่ (อ่านจาก offer_topic)
        $this->actingAs($user)->get(route('chat.index'))
            ->assertOk()
            ->assertSee('tarot_love', false);
    }

    public function test_when_mae_mor_herself_decides_it_is_a_reading_request_it_is_not_charged(): void
    {
        $this->paidChat();
        $user = $this->phoneMember();
        $this->chatReply = ['reply' => 'แม่หมอจะเปิดไพ่ให้นะคะ เลือกแบบด้านล่างได้เลย', 'kind' => 'offer', 'offer_topic' => 'career'];

        $res = $this->actingAs($user)->postJson(route('chat.send'), ['message' => 'หัวหน้าจะเลื่อนตำแหน่งให้ไหมคะ'])
            ->assertOk()
            ->assertJsonPath('kind', 'offer')
            ->assertJsonPath('offers.0.key', 'tarot_career');

        $this->assertSame(100.0, $this->balance($user));
        $this->assertSame(0.0, (float) $res->json('cost'));
    }

    public function test_paid_chat_mode_still_charges_exactly_once_for_a_real_answer(): void
    {
        $this->paidChat();
        $user = $this->phoneMember();

        $this->actingAs($user)->postJson(route('chat.send'), ['message' => 'สวัสดีค่ะแม่หมอ'])
            ->assertOk()->assertJsonPath('cost', 2);

        $this->assertSame(98.0, $this->balance($user));
    }

    public function test_a_silent_mae_mor_is_never_charged(): void
    {
        $this->paidChat();
        Setting::put('ai_api_key', '', 'ai', true);   // ไม่มีคีย์ในเครื่อง = คำตอบสำรองเป็นแค่ข้อความรอง
        Cache::flush();
        $user = $this->phoneMember();
        $this->chatReply = ['reply' => '', 'kind' => 'reply', 'offer_topic' => null];

        $this->actingAs($user)->postJson(route('chat.send'), ['message' => 'สวัสดีค่ะแม่หมอ'])
            ->assertOk()->assertJsonPath('degraded', true);

        $this->assertSame(100.0, $this->balance($user), 'แม่หมอไม่ตอบ = ไม่หักเงิน');
    }

    public function test_a_room_grounded_on_a_paid_reading_asks_about_the_cards_instead_of_being_sold_again(): void
    {
        $this->freeChat();
        $user = $this->phoneMember();

        // 💬 (2026-09-21) ห้องผูกกับไพ่ในฐานข้อมูล (reading_id) — เดิมเป็นแค่ป้ายใน session เบราว์เซอร์
        $reading = Reading::create([
            'user_id' => $user->id, 'session_token' => 'reading-tok', 'type' => 'tarot_single',
            'result' => 'ไพ่คนโง่ตั้งตรง — ลูกกำลังจะได้เริ่มต้นสิ่งใหม่ค่ะ',
        ]);
        TarotReadingCard::create([
            'reading_id' => $reading->id, 'tarot_card_id' => $this->seedDeck()->id,
            'position' => 1, 'position_label' => 'คำตอบของไพ่', 'reversed' => false,
        ]);
        ChatConversation::create(['user_id' => $user->id, 'session_token' => 'room-tok', 'reading_id' => $reading->id]);

        $this->actingAs($user)
            ->withSession([
                'chat_token' => 'room-tok',
                'thaiprompt_chat_session' => 'srv:' . self::SESSION,
            ])
            ->postJson(route('chat.send'), ['message' => 'จากไพ่ชุดนี้ ดวงความรักของหนูเป็นยังไงคะ'])
            ->assertOk()
            ->assertJsonPath('kind', 'reply');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/server/chat/send')
            && (int) $r['grounded'] === 1
            && str_contains((string) $r['context'], 'ไพ่คนโง่ตั้งตรง'));
    }

    /* ─────────────────────────── แชทในแอพ ─────────────────────────── */

    public function test_the_app_chat_works_for_a_phone_member_and_offers_are_free_and_readable_in_old_apps(): void
    {
        $this->paidChat();
        $user = $this->phoneMember();
        Sanctum::actingAs($user);

        $convo = $this->postJson(route('api.v1.chat.conversations.start'))
            ->assertCreated()
            ->assertJsonPath('data.conversation.messages.0.content', 'สวัสดีค่ะลูก')
            ->json('data.conversation.id');

        $res = $this->postJson(route('api.v1.chat.conversations.send', $convo), ['message' => 'อยากให้แม่หมอเปิดไพ่เรื่องการเงินค่ะ'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'offer')
            ->assertJsonPath('data.cost', 0);

        // แอพรุ่นที่ออกไปแล้วไม่รู้จัก offers — รายการแพ็กเกจต้องอยู่ในข้อความด้วย
        $this->assertStringContainsString('฿29.00', $res->json('data.reply'));
        $this->assertSame(100.0, $this->balance($user));

        // เปิดห้องเดิมอีกครั้ง — การ์ดยังอยู่
        $this->getJson(route('api.v1.chat.conversations.show', $convo))
            ->assertOk()
            ->assertJsonPath('data.messages.2.offer_topic', 'money')
            ->assertJsonPath('data.messages.2.offers.0.key', 'tarot_career');

        $this->assertSame(0, $this->sentTo('/chat/mae-mor/start'));
    }

    /* ─────────────────────────── เปิดไพ่ / ดูดวงเชิงลึก ─────────────────────────── */

    private function seedDeck(): TarotCard
    {
        return TarotCard::create([
            'slug' => 'the-fool', 'name_en' => 'The Fool', 'name_th' => 'คนโง่',
            'arcana' => 'major', 'suit' => 'major', 'number' => 0,
            'keywords_th' => 'เริ่มต้น', 'upright_meaning_th' => 'การเริ่มต้นใหม่', 'reversed_meaning_th' => 'ความประมาท',
            'active' => true,
        ]);
    }

    public function test_a_phone_member_pays_for_tarot_and_gets_a_real_reading(): void
    {
        $card = $this->seedDeck();
        $user = $this->phoneMember();

        $this->actingAs($user)
            ->post(route('tarot.cast'), ['spread' => 'single', 'question' => 'งานใหม่จะดีไหม', 'picked' => [$card->id]])
            ->assertRedirect();

        $reading = Reading::sole();
        $this->assertSame('ไพ่คนโง่ตั้งตรง — ลูกกำลังจะได้เริ่มต้นสิ่งใหม่ค่ะ', $reading->result);
        $this->assertSame('gemini', $reading->ai_provider);
        $this->assertSame(81.0, $this->balance($user), 'หักราคาเปิดไพ่ครั้งเดียว');
    }

    public function test_tarot_money_comes_back_when_mae_mor_cannot_read(): void
    {
        $card = $this->seedDeck();
        $user = $this->phoneMember();
        $this->fortuneDown = true;

        // แม่หมออ่านเบื้องหลัง (หลังส่งหน้า) — ลูกค้าไปหน้าผล ซึ่งบอกว่าไม่สำเร็จและคืนเงินแล้ว
        $res = $this->actingAs($user)->post(route('tarot.cast'), ['spread' => 'single', 'picked' => [$card->id]]);
        $reading = Reading::sole();
        $res->assertRedirect(route('tarot.show', $reading));

        $this->assertSame(100.0, $this->balance($user), 'ได้ข้อความประกอบจากความหมายไพ่ ≠ คำทำนาย — ต้องคืนเงิน');
        $this->assertTrue($reading->fresh()->isFailed());
        $this->actingAs($user)->get(route('tarot.show', $reading))
            ->assertOk()->assertSee('อ่านไพ่ไม่สำเร็จ')->assertSee('คืนเข้ากระเป๋า');
        $this->actingAs($user)->get(route('account.history'))->assertOk()->assertDontSee(route('tarot.show', $reading), false);
    }

    public function test_a_phone_member_can_buy_a_deep_reading(): void
    {
        $user = $this->phoneMember();

        $this->actingAs($user)
            ->post(route('deep.store'), ['questions' => ['ปีนี้การงานจะเป็นอย่างไร']])
            ->assertRedirect();

        $reading = Reading::sole();
        $this->assertSame('deep', $reading->type);
        $this->assertSame('ดวงเชิงลึกของลูก: การงานเด่นช่วงปลายปีค่ะ', $reading->result);
        $this->assertSame(61.0, $this->balance($user));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/server/fortune/deep') && $r['questions'] === ['ปีนี้การงานจะเป็นอย่างไร']);
    }
}
