<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Setting;
use App\Models\User;
use App\Services\Chat\ChatReadingIntent;
use App\Services\Wallet\WalletService;
use App\Support\ChatCrisis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🤍 (2026-11-06) ลูกค้าที่บอกว่าอยากทำร้ายตัวเอง — Thaiprompt #2649 ส่ง data.crisis / data.crisis_room มา
 *
 *   - เทิร์นที่เป็นคำปลอบ (crisis) ห้ามคิดค่าแชท — kind ยังเป็น "guard" จึงแยกจาก kind ไม่ได้
 *   - ห้องวิกฤต (crisis_room) ห้ามขาย: ไม่มีการ์ดเปิดไพ่ (รวมการ์ดจากด่าน ChatReadingIntent ที่ตัดสินก่อนถาม AI)
 *     ไม่มีแพ็กเกจบนโต๊ะ ไม่มีปุ่มลัด/ปุ่ม AI และข้อความโควตาหมดไม่ชวนเปิดไพ่
 *   - Thaiprompt รุ่นก่อน (ไม่มีฟิลด์) = คิดเงินเหมือนเดิมทุกอย่าง
 */
class ChatCrisisTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    private array $upstream = ['reply' => 'รับฟังอยู่ค่ะ', 'kind' => 'reply'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Setting::put('tarot_quick_visible', '1', 'tarot', false);
        Cache::flush();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => ['session_id' => 'sess-1'] + $this->upstream]),
                default => Http::response([], 503),
            };
        });
    }

    /** คิดเงินทุกข้อความ ฿2 และลูกค้ามีเงิน ฿10 */
    private function paidMember(): User
    {
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '1', 'pricing', false);
        Setting::put('chat_daily_limit', '0', 'chat', false);
        Cache::flush();

        $user = User::factory()->create(['thaiprompt_token' => null]);
        app(WalletService::class)->credit($user, 10, 'เติมเงิน');

        return $user;
    }

    private function balance(User $user): float
    {
        return (float) app(WalletService::class)->balance($user);
    }

    private function sendCount(): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/server/chat/send'))->count();
    }

    private function crisisReply(): array
    {
        return ['reply' => 'แม่หมออยู่ตรงนี้นะคะลูก 🤍', 'kind' => 'guard', 'crisis' => true, 'crisis_room' => true, 'suggestions' => []];
    }

    public function test_a_crisis_turn_is_never_charged_on_the_web(): void
    {
        $user = $this->paidMember();
        $this->upstream = $this->crisisReply();

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'ไม่อยากอยู่แล้วค่ะ'])->assertOk();

        $res->assertJsonPath('crisis', true)->assertJsonPath('crisis_room', true);
        $this->assertSame(0.0, (float) $res->json('cost'));
        $this->assertSame(10.0, $this->balance($user), 'เทิร์นปลอบใจต้องไม่ถูกหักเงิน');
        $this->assertSame([], $res->json('suggestions'), 'ไม่มีปุ่มลัด (มี "ดูดวงเชิงลึก") ในห้องวิกฤต');
        $this->assertSame([], $res->json('ai_suggestions'));
        $this->assertSame([], $res->json('offers'));
    }

    public function test_an_upstream_without_crisis_fields_is_charged_as_before(): void
    {
        $user = $this->paidMember();

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'สวัสดีค่ะ'])->assertOk();

        $res->assertJsonPath('crisis', false)->assertJsonPath('crisis_room', false);
        $this->assertSame(2.0, (float) $res->json('cost'));
        $this->assertSame(8.0, $this->balance($user));
        $this->assertNotEmpty($res->json('suggestions'));
        $this->assertFalse(ChatCrisis::active($user));
    }

    public function test_a_crisis_room_never_shows_an_offer_card_and_asks_the_reader_instead(): void
    {
        $user = $this->paidMember();
        $ask = 'อยากดูดวงความรักค่ะ';
        $this->assertNotNull(ChatReadingIntent::detect($ask), 'ข้อความนี้ต้องเป็นคำขอดูดวงที่ด่านแรกจับได้');

        $this->upstream = $this->crisisReply();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'อยากตายค่ะ'])->assertOk();
        $this->assertSame(1, $this->sendCount());

        // เทิร์นถัดไปในห้องวิกฤต — ไม่ใช่คำปลอบ (crisis=false) แต่ห้องยังวิกฤต
        $this->upstream = ['reply' => 'ค่อย ๆ เล่าให้แม่หมอฟังนะคะ', 'kind' => 'reply', 'crisis' => false, 'crisis_room' => true];
        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => $ask])->assertOk();

        $this->assertSame(2, $this->sendCount(), 'ต้องส่งให้ผู้พยากรณ์ตอบ ไม่ใช่ยื่นใบเสนอราคา');
        $this->assertSame('reply', $res->json('kind'));
        $this->assertSame([], $res->json('offers'));
        $res->assertJsonPath('crisis_room', true);
        // เทิร์นปกติในห้องวิกฤตคิดเงินตามปกติ — ยกเว้นเฉพาะเทิร์นปลอบใจ
        $this->assertSame(8.0, $this->balance($user));
    }

    public function test_the_chat_page_of_a_crisis_room_has_no_packages_or_chips(): void
    {
        $user = $this->paidMember();
        $this->upstream = $this->crisisReply();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ไม่อยากมีชีวิตอยู่แล้ว'])->assertOk();

        $this->actingAs($user)->get('/chat')->assertOk()
            ->assertViewHas('crisisRoom', true)
            ->assertViewHas('tableOffers', [])
            ->assertViewHas('suggestions', [])
            ->assertViewHas('aiSuggestions', []);
    }

    public function test_crisis_room_false_lifts_the_flag_and_offers_return(): void
    {
        $user = $this->paidMember();
        $this->upstream = $this->crisisReply();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'อยากตาย'])->assertOk();
        $this->assertTrue(ChatCrisis::active($user));

        $this->upstream = ['reply' => 'ดีใจที่วันนี้ดีขึ้นนะคะ', 'kind' => 'reply', 'crisis' => false, 'crisis_room' => false];
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'วันนี้ดีขึ้นแล้วค่ะ'])->assertOk();
        $this->assertFalse(ChatCrisis::active($user));

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'อยากดูดวงความรักค่ะ'])->assertOk();
        $this->assertSame('offer', $res->json('kind'));
        $this->assertNotEmpty($res->json('offers'));
    }

    public function test_an_old_upstream_reply_does_not_clear_a_remembered_crisis(): void
    {
        $user = $this->paidMember();
        ChatCrisis::remember($user, true);

        $this->upstream = ['reply' => 'รับฟังอยู่ค่ะ', 'kind' => 'reply'];   // ไม่มีฟิลด์ crisis_room
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'สวัสดีค่ะ'])->assertOk();

        $this->assertTrue(ChatCrisis::active($user));
    }

    public function test_the_daily_limit_message_in_a_crisis_room_does_not_sell_a_reading(): void
    {
        // โหมดคุยฟรีล้วน (เพดานบล็อกเฉพาะโหมดนี้)
        Setting::put('pricing_chat_message', '0', 'pricing', false);
        Setting::put('chat_daily_limit', '1', 'chat', false);
        Cache::flush();
        $user = User::factory()->create(['thaiprompt_token' => null]);

        $this->upstream = $this->crisisReply();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'อยากตาย'])->assertOk();

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'ยังอยู่ไหมคะ'])->assertStatus(429);
        $this->assertStringNotContainsString('เปิดไพ่', (string) $res->json('error'));
        $this->assertStringContainsString('🤍', (string) $res->json('error'));
    }

    public function test_a_crisis_turn_is_never_charged_in_the_app(): void
    {
        $user = $this->paidMember();
        $convo = ChatConversation::create([
            'user_id'       => $user->id,
            'session_token' => (string) Str::uuid(),
            'title'         => 'จากแอพ',
        ]);
        $this->upstream = $this->crisisReply();

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'ไม่อยากอยู่แล้ว'])
            ->assertOk();

        $res->assertJsonPath('data.crisis', true)->assertJsonPath('data.crisis_room', true);
        $this->assertSame(0.0, (float) $res->json('data.cost'));
        $this->assertSame([], $res->json('data.suggestions'));
        $this->assertSame(10.0, $this->balance($user));

        // ห้องวิกฤตในแอพ: คำขอดูดวงไปถึงผู้พยากรณ์ ไม่ได้รายการแพ็กเกจ
        $this->upstream = ['reply' => 'เล่าให้ฟังได้นะคะ', 'kind' => 'reply', 'crisis' => false, 'crisis_room' => true];
        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'อยากดูดวงความรักค่ะ'])
            ->assertOk();
        $this->assertSame([], $res->json('data.offers'));
        $this->assertSame('reply', $res->json('data.kind'));
    }

    /* ── 2026-11-07: ด่านขายข้ามข้อความที่อาจเป็นวิกฤต + คำปัดของด่าน guard ไม่คิดเงิน ─────── */

    /** ข้อความแรก "อยากตาย ดูดวงให้หน่อย" — เดิมด่านแรกยื่นการ์ดขายโดยไม่ถาม Thaiprompt เลย */
    public function test_a_first_message_that_sounds_like_crisis_reaches_thaiprompt_instead_of_an_offer_card(): void
    {
        $user = $this->paidMember();
        $this->upstream = $this->crisisReply();

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'อยากตาย ดูดวงให้หน่อย'])->assertOk();

        $this->assertSame(1, $this->sendCount(), 'ต้องส่งให้ Thaiprompt ตัดสิน ไม่ใช่ตอบด้วยการ์ดขายจากด่านแรก');
        $this->assertSame([], $res->json('offers'));
        $res->assertJsonPath('crisis', true);
        $this->assertSame(10.0, $this->balance($user));
        $this->assertTrue(ChatCrisis::active($user), 'Thaiprompt ปักธงแล้ว ห้องถัดไปต้องไม่ขายด้วย');
    }

    public function test_the_app_also_asks_thaiprompt_first_for_a_message_that_sounds_like_crisis(): void
    {
        $user = $this->paidMember();
        $convo = ChatConversation::create([
            'user_id'       => $user->id,
            'session_token' => (string) Str::uuid(),
            'title'         => 'จากแอพ',
        ]);
        $this->upstream = $this->crisisReply();

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'ไม่อยากมีชีวิตอยู่แล้ว ช่วยทำนายหน่อย'])
            ->assertOk();

        $this->assertSame(1, $this->sendCount());
        $this->assertSame([], $res->json('data.offers'));
        $this->assertSame(10.0, $this->balance($user));
    }

    public function test_crisis_hint_is_broad_but_plain_reading_requests_still_get_the_offer_gate(): void
    {
        foreach (['อยากตาย', 'อยาก ตาย ค่ะ', "อยาก\u{200B}ตาย", 'คิดจะฆ่าตัวตาย', 'กินยาเกินขนาดดีไหม', 'I want to die', 'ไม่อยากอยู่บนโลกนี้แล้ว'] as $t) {
            $this->assertTrue(ChatCrisis::mayBeCrisis($t), $t);
            $this->assertNull(ChatReadingIntent::detect($t.' ดูดวงให้หน่อย'), $t);
        }
        foreach (['ดูดวงความรักให้หน่อยค่ะ', 'เปิดไพ่เรื่องงาน', 'ดวงเดือนนี้เป็นยังไง'] as $t) {
            $this->assertFalse(ChatCrisis::mayBeCrisis($t), $t);
            $this->assertNotNull(ChatReadingIntent::detect($t), $t);
        }
    }

    /** คำปัดสำเร็จรูปของด่าน scope (kind=guard, ไม่ใช่วิกฤต) — ลูกค้าไม่ได้คำตอบ จึงไม่คิดเงิน */
    public function test_a_guard_deflection_is_not_charged_on_the_web(): void
    {
        $user = $this->paidMember();
        $this->upstream = ['reply' => 'เรื่องนี้แม่หมอช่วยไม่ได้นะคะ', 'kind' => 'guard', 'crisis' => false, 'crisis_room' => false];

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'ช่วยเขียนเรียงความให้หน่อย'])->assertOk();

        $this->assertSame(0.0, (float) $res->json('cost'));
        $this->assertSame(10.0, $this->balance($user));
    }

    public function test_a_guard_deflection_is_not_charged_in_the_app(): void
    {
        $user = $this->paidMember();
        $convo = ChatConversation::create([
            'user_id'       => $user->id,
            'session_token' => (string) Str::uuid(),
            'title'         => 'จากแอพ',
        ]);
        $this->upstream = ['reply' => 'เรื่องนี้แม่หมอช่วยไม่ได้นะคะ', 'kind' => 'guard', 'crisis' => false, 'crisis_room' => false];

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'แปลภาษาให้หน่อย'])
            ->assertOk();

        $this->assertSame(0.0, (float) $res->json('data.cost'));
        $this->assertSame(10.0, $this->balance($user));
    }

    /** kind แปลก ๆ ที่ไม่รู้จัก = reply → คิดเงินตามปกติ (กันช่องโหว่ "ส่ง kind อะไรก็ได้มาให้ฟรี") */
    public function test_an_unknown_kind_is_charged_as_a_normal_reply(): void
    {
        $user = $this->paidMember();
        $this->upstream = ['reply' => 'ตอบแล้วค่ะ', 'kind' => 'free'];

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'สวัสดีค่ะ'])->assertOk();

        $this->assertSame(2.0, (float) $res->json('cost'));
        $this->assertSame(8.0, $this->balance($user));
    }
}
