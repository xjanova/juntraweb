<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Support\FreeAskClock;
use App\Support\SiteHelp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🎁 (2026-11-11) ถามฟรีหลังดูไพ่ฟรี 1 ใบ — ไม่เกิน 2 นาที แล้วชวนเปิดไพ่ชุดเต็มแบบนุ่ม ๆ
 *
 * เจ้าของ: "การถามฟรี เวลาควรไม่เกิน 2 นาที และปุ่มคำถามต่อจากเรื่องนี้ ควรขึ้นไพ่หมวดอื่นให้เลือก …
 *          เหมือนแชทผ่านเฟซบุ๊ก ซึ่งจะเริ่มให้ลองเติมเงินเพื่อรับคำทำนายเจาะลึกเนียน ๆ แทน"
 *
 * สิ่งที่ล็อกไว้:
 *  - ห้องของไพ่ฟรีจำไพ่ไว้ที่ free_reading_id (ไม่พึ่ง cache) และยังไม่ผูก reading_id (แพ็กเกจไม่ถูกซ่อน)
 *  - ไม่มีปุ่ม "ถามต่อจากเรื่องนี้" — มีป้ายไพ่หมวดอื่นแทน (ไม่มีไพ่ใบเดียว ซึ่งเพิ่งได้ไปฟรี)
 *  - นาฬิกาเริ่มที่คำถามแรก · คำถามแรกที่ระบบล่มไม่กินเวลา
 *  - ครบ 2 นาทีแล้วพิมพ์มา = คำชวน + การ์ดที่ตรงเรื่อง ไม่ถาม AI ไม่คิดเงิน · ถามวิธีใช้เว็บยังได้คำตอบปกติ
 *  - คำชวนไม่มีราคา ไม่มีคำว่า "ลูก"/"ลูกค้า"
 */
class FreeAskClockTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    private bool $chatDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Setting::put('free_reading_enabled', '1', 'free_reading');
        Setting::put('free_reading_daily_cap', '0', 'free_reading');
        Setting::put('free_reading_batch', 'v1', 'free_reading');
        // คุยฟรีเหมือน prod (ราคาต่อข้อความ 0) — นาฬิกาคือสิ่งเดียวที่จำกัดการถามฟรี
        Setting::put('pricing_chat_message', '0', 'pricing', false);
        Cache::flush();
        $this->seedDeck();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_contains($url, '/fortune/tarot/free') => Http::response(['data' => [
                    'interpretation' => 'ไพ่ใบนี้บอกว่าช่วงนี้ของคุณกำลังเปลี่ยนผ่าน อดทนอีกนิดเรื่องจะคลี่คลาย',
                    'next_questions' => ['เรื่องงานจะลงตัวเมื่อไร', 'คนที่เข้ามาคือใคร'],
                    'ai_provider' => 'openai', 'ai_model' => 'gpt-test',
                ]], 200),
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => $this->chatDown
                    ? Http::response(['message' => 'busy'], 503)
                    : Http::response(['data' => ['session_id' => 'sess-1', 'reply' => 'ไพ่ใบนี้บอกให้คุณใจเย็นอีกนิดนะคะ', 'kind' => 'reply']]),
                default => Http::response(['message' => 'unexpected '.$url], 500),
            };
        });
    }

    private function seedDeck(): void
    {
        TarotCard::create([
            'slug' => 'the-fool', 'name_en' => 'The Fool', 'name_th' => 'คนโง่', 'arcana' => 'major', 'suit' => 'major',
            'number' => 0, 'keywords_th' => 'เริ่มต้น', 'upright_meaning_th' => 'เริ่มต้นใหม่', 'reversed_meaning_th' => 'ประมาท',
            'active' => true,
        ]);
    }

    private function member(): User
    {
        return User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(8)]);
    }

    /** ดูฟรี → เข้าห้องของไพ่ฟรี */
    private function freeRoom(User $user): ChatConversation
    {
        $this->actingAs($user)->post(route('tarot.free.cast'));
        $this->actingAs($user)->get(route('chat.from-free', Reading::firstOrFail()));

        return ChatConversation::where('user_id', $user->id)->sole();
    }

    private function say(User $user, string $text)
    {
        return $this->actingAs($user)->postJson(route('chat.send'), ['message' => $text, 'room' => 'maemor']);
    }

    private function sends(): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/server/chat/send'))->count();
    }

    public function test_free_room_shows_other_spreads_instead_of_follow_up_questions(): void
    {
        $user = $this->member();
        $room = $this->freeRoom($user);

        $this->assertSame(Reading::first()->id, $room->free_reading_id, 'ห้องต้องจำไพ่ฟรีไว้ในฐานข้อมูล ไม่ใช่ cache อย่างเดียว');
        $this->assertNull($room->reading_id, 'ห้องของไพ่ฟรีไม่ผูก reading_id (ห้องผูกไพ่ซ่อนแพ็กเกจ)');

        $page = $this->actingAs($user)->get(route('chat.index', ['room' => 'maemor']))->assertOk();
        $this->assertSame([], $page->viewData('aiSuggestions'), 'ไม่มีปุ่ม "ถามต่อจากเรื่องนี้" แล้ว');
        $picks = array_column($page->viewData('freePicks'), 'spread');
        $this->assertNotEmpty($picks, 'ต้องมีป้ายไพ่หมวดอื่นให้เลือก');
        $this->assertNotContains('single', $picks, 'ไม่ยื่นไพ่ใบเดียว — เพิ่งได้ไปฟรี');
        $this->assertContains('celtic', $picks, 'ต้องมีชุดเจาะลึก');
        $this->assertNotEmpty($page->viewData('tableOffers'), 'แพ็กเกจบนโต๊ะยังอยู่ระหว่างถามฟรี');

        $free = $page->viewData('freeAsk');
        $this->assertSame(2, $free['minutes']);
        $this->assertFalse($free['started'], 'ยังไม่ถาม = ยังไม่เริ่มนับ');
        $this->assertStringContainsString('2 นาที', $room->messages()->orderBy('id')->get()[1]->content);
    }

    public function test_first_question_starts_the_clock_and_mae_mor_sees_the_card_and_time_left(): void
    {
        $user = $this->member();
        $room = $this->freeRoom($user);

        $res = $this->say($user, 'เรื่องนี้จะดีขึ้นไหมคะ')->assertOk();
        $this->assertSame('reply', $res->json('kind'));
        $this->assertTrue($res->json('free_ask.started'));
        $this->assertNotNull($room->fresh()->qa_started_at);

        $send = collect(Http::recorded())->first(fn ($p) => str_ends_with($p[0]->url(), '/server/chat/send'))[0]->data();
        $this->assertStringContainsString('ช่วงถามฟรี', (string) $send['context']);
        $this->assertStringContainsString('กำลังเปลี่ยนผ่าน', (string) $send['context'], 'แม่หมอต้องเห็นคำทำนายของไพ่ฟรีทุกข้อความ');
        $this->assertSame([], $res->json('ai_suggestions'));
    }

    public function test_a_first_question_that_fails_does_not_use_up_free_time(): void
    {
        $user = $this->member();
        $room = $this->freeRoom($user);
        $this->chatDown = true;

        $this->say($user, 'เรื่องนี้จะดีขึ้นไหมคะ');

        $this->assertNull($room->fresh()->qa_started_at);
    }

    public function test_after_two_minutes_mae_mor_invites_a_full_spread_without_asking_ai_or_charging(): void
    {
        $user = $this->member();
        $this->freeRoom($user);
        $this->say($user, 'เรื่องนี้จะดีขึ้นไหมคะ')->assertOk();
        $this->assertSame(1, $this->sends());

        $this->travel(121)->seconds();
        $res = $this->say($user, 'แฟนจะกลับมาไหมคะ')->assertOk();

        $this->assertSame(1, $this->sends(), 'ครบเวลาแล้วต้องไม่ถาม AI');
        $this->assertSame('offer', $res->json('kind'));
        $this->assertSame(0, (int) $res->json('cost'));
        $this->assertTrue($res->json('free_ask.over'));
        $this->assertFalse($res->json('blocked'), 'ไม่ปิดช่องพิมพ์ — ชวนนุ่ม ๆ แบบเพจ FB');
        $keys = array_column($res->json('offers'), 'key');
        $this->assertSame('tarot_love', $keys[0], 'ถามเรื่องความรัก = การ์ดไพ่ความรักก่อน');
        $this->assertContains('tarot_celtic', $keys, 'พร้อมชุดเจาะลึก');

        $reply = (string) $res->json('reply');
        $this->assertSame(FreeAskClock::afterText('maemor', $user), $reply);
        $this->assertDoesNotMatchRegularExpression('/ลูก|฿|\d+\s*บาท/u', $reply, 'คำชวนไม่มีราคาและไม่เรียกลูกค้าว่าลูก');
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame('love', ChatMessage::where('role', 'assistant')->latest('id')->first()->offer_topic);

        // รีเฟรชแล้วการ์ดใต้คำชวนยังเป็นชุดเดิม
        $page = $this->actingAs($user)->get(route('chat.index', ['room' => 'maemor']));
        $this->assertSame($keys, array_column($page->viewData('offerCards')['offers'], 'key'));
        $this->assertTrue($page->viewData('freeAsk')['over']);
    }

    public function test_help_questions_still_get_a_real_answer_after_free_time(): void
    {
        $this->assertTrue(SiteHelp::isHelp('เติมเงินยังไงคะ'), 'ข้อความทดสอบต้องเป็นคำถามวิธีใช้เว็บ');
        $user = $this->member();
        $this->freeRoom($user);
        $this->say($user, 'เรื่องนี้จะดีขึ้นไหมคะ');
        $this->travel(3)->minutes();

        $res = $this->say($user, 'เติมเงินยังไงคะ')->assertOk();

        $this->assertSame(2, $this->sends());
        $this->assertSame('help', $res->json('kind'));
    }

    /** ตั้งราคาแชทไว้ + เครดิต 0 — ถามฟรีต้องฟรีจริง และหมดเวลาแล้วต้องได้คำชวน ไม่ใช่ "เครดิตไม่พอ" */
    public function test_free_room_is_free_even_when_chat_is_priced(): void
    {
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '1', 'pricing', false);
        Cache::flush();
        $user = $this->member();
        $this->freeRoom($user);

        $this->say($user, 'เรื่องนี้จะดีขึ้นไหมคะ')->assertOk()->assertJsonPath('kind', 'reply');
        $this->travel(3)->minutes();
        $this->say($user, 'งานจะดีขึ้นไหมคะ')->assertOk()->assertJsonPath('kind', 'offer');

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_free_time_never_goes_above_two_minutes(): void
    {
        config(['reading_qa.free_minutes' => 10]);
        $this->assertSame(2, FreeAskClock::minutes());
        config(['reading_qa.free_minutes' => 1]);
        $this->assertSame(1, FreeAskClock::minutes());
    }

    public function test_an_old_free_room_found_through_cache_gets_the_clock(): void
    {
        $user = $this->member();
        $room = $this->freeRoom($user);
        $room->update(['free_reading_id' => null]);   // ห้องที่สร้างก่อนมีคอลัมน์

        $this->actingAs($user)->get(route('chat.from-free', Reading::first()));

        $this->assertSame(1, ChatConversation::count());
        $this->assertSame(Reading::first()->id, $room->fresh()->free_reading_id);
    }

    public function test_ordinary_rooms_have_no_free_clock(): void
    {
        $user = $this->member();
        $page = $this->actingAs($user)->get(route('chat.index', ['room' => 'maemor']))->assertOk();

        $this->assertNull($page->viewData('freeAsk'));
        $this->assertSame([], $page->viewData('freePicks'));
    }
}
