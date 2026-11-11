<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\TarotReadingCard;
use App\Models\User;
use App\Services\Chat\ChatOffers;
use App\Support\ChatPolicy;
use App\Support\ChatRooms;
use App\Support\Pricing;
use App\Support\TarotSpreads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔮 (2026-11-03) ห้องผู้พยากรณ์ 3 ห้อง — บุษบา ฿39 · แม่หมอ ฿99 · คุณไสย ฿139
 *
 * สิ่งที่ล็อกไว้:
 *  - ยังไม่เปิด CHAT_PERSONA_UPSTREAM = เว็บทำงานเหมือนเดิมทุกอย่าง (เข้าห้องแม่หมอตรง ไม่ส่ง persona)
 *    เพราะ Thaiprompt รุ่นที่ยังไม่รู้จัก persona จะให้แม่หมอตอบในห้องบุษบา = บุคลิกไม่ตรงห้อง
 *  - เปิดแล้ว: หน้าเลือกห้อง · ห้องแยกต่อผู้พยากรณ์ · persona ไปกับทุกข้อความ
 *  - ข้อความทีละข้อความต่อคน (409) · ห้องปิดส่งไม่ได้ (403)
 */
class ChatRoomsTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    protected function setUp(): void
    {
        parent::setUp();

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
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => ['session_id' => 'sess-1', 'reply' => 'รับฟังอยู่ค่ะ', 'kind' => 'reply']]),
                default => Http::response(['message' => 'unexpected ' . $url], 500),
            };
        });
    }

    /** เปิดทุกห้อง: Thaiprompt รู้จัก persona แล้ว + แพ็กเกจ "ดูไว" ของบุษบาเปิดขาย */
    private function allRoomsOpen(): void
    {
        config(['chat_rooms.persona_upstream' => true]);
        Setting::put('tarot_quick_visible', '1', 'tarot', false);
        Setting::put('tarot_kunsai_visible', '1', 'pricing', false);
        Cache::flush();
    }

    private function member(): User
    {
        return User::factory()->create(['thaiprompt_token' => null]);
    }

    /** @return list<array> payload ที่ส่งไป /server/chat/* */
    private function chatCalls(string $path): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), $path))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_room_products_and_prices(): void
    {
        $this->assertSame('quick', ChatRooms::spread('busaba'));
        $this->assertSame('celtic', ChatRooms::spread('maemor'));
        $this->assertSame('kunsai', ChatRooms::spread('kunsai'));
        $this->assertEquals(39, Pricing::for('tarot_quick'));
        $this->assertEquals(139, Pricing::for('tarot_kunsai'));

        // persona ของคำทำนายหาจากแพ็กเกจ — ซื้อจากหน้า /tarot ตรง ๆ ก็ได้ผู้พยากรณ์ที่ถูกห้อง
        $this->assertSame('busaba', ChatRooms::personaForSpread('quick'));
        $this->assertSame('kunsai', ChatRooms::personaForSpread('kunsai'));
        $this->assertSame('maemor', ChatRooms::personaForSpread('love'));
        $this->assertSame('maemor', ChatRooms::personaForSpread(null));
    }

    public function test_quick_spread_stays_hidden_until_the_owner_turns_it_on(): void
    {
        $this->assertArrayNotHasKey('quick', TarotSpreads::all());

        Setting::put('tarot_quick_visible', '1', 'tarot', false);
        Cache::flush();
        $this->assertArrayHasKey('quick', TarotSpreads::all());
        $this->assertSame(3, TarotSpreads::cardCount('quick'));
    }

    public function test_without_the_flag_the_chat_page_is_exactly_the_old_mae_mor_room(): void
    {
        $user = $this->member();

        $this->actingAs($user)->get('/chat')
            ->assertOk()
            ->assertViewIs('pages.chat.index')
            ->assertSee('แม่หมอจันทรา')
            ->assertDontSee('เปลี่ยนผู้พยากรณ์');

        $this->assertSame('maemor', ChatConversation::where('user_id', $user->id)->value('persona'));
        $this->assertFalse(ChatRooms::isOpen('busaba'));
        $this->assertFalse(ChatRooms::isOpen('kunsai'));

        // ไม่ส่ง persona เลย — คำขอหน้าตาเดิมทุกไบต์
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'สวัสดีค่ะ'])->assertOk();
        foreach (array_merge($this->chatCalls('/server/chat/start'), $this->chatCalls('/server/chat/send')) as $payload) {
            $this->assertArrayNotHasKey('persona', $payload);
        }
    }

    public function test_closed_room_cannot_be_entered_or_messaged(): void
    {
        $user = $this->member();

        $this->actingAs($user)->get('/chat?room=busaba')
            ->assertRedirect(route('chat.index', ['pick' => 1]));

        $this->actingAs($user)
            ->postJson('/chat/send', ['message' => 'สวัสดี', 'room' => 'busaba'])
            ->assertStatus(403)
            ->assertJsonPath('reason_code', 'room_closed');

        // หน้าเลือกห้องยังเปิดดูได้ — ห้องที่ปิดขึ้น "เร็ว ๆ นี้"
        $this->actingAs($user)->get('/chat?pick=1')
            ->assertOk()
            ->assertViewIs('pages.chat.rooms')
            ->assertSee('เร็ว ๆ นี้');
    }

    /** 🚪 (2026-11-04) เปิดบุษบาก่อน คุณไสยรอ — CHAT_ROOMS_CLOSED ปิดทีละห้อง แต่ห้องหลักแม่หมอปิดไม่ได้ */
    public function test_rooms_can_be_held_closed_one_by_one(): void
    {
        $this->allRoomsOpen();
        config(['chat_rooms.closed' => ['kunsai', 'maemor']]);

        $this->assertTrue(ChatRooms::isOpen('busaba'));
        $this->assertFalse(ChatRooms::isOpen('kunsai'));
        $this->assertTrue(ChatRooms::isOpen('maemor'));
        // ไพ่คุณไสยที่ซื้อไปแล้วยังคุยต่อได้ — ที่ห้องแม่หมอ
        $this->assertSame('maemor', ChatRooms::roomForSpread('kunsai'));
        $this->assertSame('busaba', ChatRooms::roomForSpread('quick'));

        $user = $this->member();
        $this->actingAs($user)->get('/chat?room=kunsai')->assertRedirect(route('chat.index', ['pick' => 1]));
        $this->actingAs($user)->get('/chat?room=busaba')->assertOk()->assertViewIs('pages.chat.index');
    }

    /** ไพ่ quick อ่านโดยบุษบา — เสียงและปุ่ม "ถามต่อ" ต้องเป็นบุษบา แม้ลูกค้าจะมาจากห้องแม่หมอ */
    public function test_quick_reading_speaks_as_busaba_even_from_mae_mor_room(): void
    {
        $this->allRoomsOpen();
        session(['chat_room' => 'maemor']);

        $this->assertSame('busaba', \App\Support\OracleMedia::personaFor('quick'));
        $this->assertSame('kunsai', \App\Support\OracleMedia::personaFor('kunsai'));
        // 🌸 (2026-11-11) ไพ่ 3 ใบเป็นของบุษบาแล้ว · 12 เดือนยังเป็นแม่หมอ
        $this->assertSame('busaba', \App\Support\OracleMedia::personaFor('three'));
        $this->assertSame('maemor', \App\Support\OracleMedia::personaFor('year'));
    }

    public function test_closed_room_notice_shows_once_on_the_picker(): void
    {
        // QA prod 2026-11-03: เลย์เอาต์กับหน้าเลือกห้องต่างวางข้อความเดียวกัน → ขึ้นซ้ำสองรอบ
        $html = $this->actingAs($this->member())->followingRedirects()->get('/chat?room=busaba')
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'ยังไม่เปิดให้คุยนะคะ'));
    }

    public function test_with_all_rooms_open_a_new_visitor_picks_a_room_first(): void
    {
        $this->allRoomsOpen();

        $this->actingAs($this->member())->get('/chat')
            ->assertOk()
            ->assertViewIs('pages.chat.rooms')
            ->assertSee('น้องบุษบา')
            ->assertSee('แม่หมอจันทรา')
            ->assertSee('คุณไสย')
            ->assertSee('฿39')
            ->assertSee('฿99')
            ->assertSee('฿139');
    }

    public function test_each_oracle_gets_its_own_room_and_persona_goes_upstream(): void
    {
        $this->allRoomsOpen();
        $user = $this->member();

        $this->actingAs($user)->get('/chat?room=busaba')->assertOk()->assertSee('น้องบุษบา')->assertSee('เปลี่ยนผู้พยากรณ์');
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'งานใหม่จะผ่านไหม', 'room' => 'busaba'])->assertOk();

        $this->actingAs($user)->get('/chat?room=kunsai')->assertOk();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'รู้สึกเหมือนโดนของ', 'room' => 'kunsai'])->assertOk();

        $rooms = ChatConversation::where('user_id', $user->id)->pluck('persona')->sort()->values()->all();
        $this->assertSame(['busaba', 'kunsai'], $rooms);

        $sent = collect($this->chatCalls('/server/chat/send'))->pluck('persona')->all();
        $this->assertSame(['busaba', 'kunsai'], $sent);
        $this->assertSame(['busaba', 'kunsai'], collect($this->chatCalls('/server/chat/start'))->pluck('persona')->all());

        // ข้อความลงห้องที่ถูกต้อง
        $busaba = ChatConversation::where('user_id', $user->id)->where('persona', 'busaba')->firstOrFail();
        $this->assertTrue($busaba->messages()->where('role', 'user')->where('content', 'งานใหม่จะผ่านไหม')->exists());
    }

    public function test_one_package_per_room_only_once_the_rooms_are_live(): void
    {
        // ก่อนเปิดห้อง: การ์ดตามเรื่องแบบเดิม (ไม่เปลี่ยนสิ่งที่ลูกค้าเห็นบน prod)
        $this->assertGreaterThan(1, count(\App\Services\Chat\ChatOffers::forRoom('maemor', 'love')));

        $this->allRoomsOpen();
        $keys = fn (string $room, string $topic) => array_column(\App\Services\Chat\ChatOffers::forRoom($room, $topic), 'key');

        $this->assertSame(['tarot_celtic'], $keys('maemor', 'love'));
        // 🌸 (2026-11-11) บุษบาอ่านทุกแบบที่ไม่ถึง 10 ใบ → การ์ดประจำห้องก่อน ตามด้วยแบบที่ตรงเรื่อง
        $this->assertSame(['tarot_quick', 'tarot_career', 'tarot_decision'], $keys('busaba', 'career'));
        $this->assertSame(['tarot_quick', 'tarot_love', 'tarot_three'], $keys('busaba', 'love'));
        $this->assertSame(['tarot_kunsai'], $keys('kunsai', 'general'));
        // เรื่องของ/คุณไสยในห้องอื่น → การ์ดคุณไสย ตรงกับที่ผู้พยากรณ์บอกว่า "ต้องให้แม่หมอส่องให้โดยเฉพาะ"
        $this->assertSame(['tarot_kunsai'], $keys('busaba', 'kunsai'));
        $this->assertSame(['tarot_kunsai'], $keys('maemor', 'kunsai'));
    }

    public function test_opening_a_room_does_not_call_the_ai(): void
    {
        $this->allRoomsOpen();

        $this->actingAs($this->member())->get('/chat?room=maemor')->assertOk();

        // คำทักทายประกอบในเครื่อง — เปิดหน้าเฉย ๆ ไม่ถือ worker ไปรอ AI
        $this->assertSame([], $this->chatCalls('/server/chat/start'));
        $this->assertSame([], $this->chatCalls('/server/chat/send'));
    }

    public function test_one_message_at_a_time_per_person(): void
    {
        $user = $this->member();
        $this->actingAs($user)->get('/chat')->assertOk();

        $held = Cache::lock("chat:inflight:u{$user->id}", 60);
        $this->assertTrue($held->get());

        $this->actingAs($user)
            ->postJson('/chat/send', ['message' => 'ส่งจากอีกแท็บ'])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'busy_other');

        $held->release();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ส่งใหม่'])->assertOk();
    }

    /** คำพยากรณ์ที่อ่านเสร็จแล้ว 1 ใบ ของแพ็กเกจ $spread */
    private function finishedReading(User $user, string $spread): Reading
    {
        $reading = Reading::create([
            'user_id' => $user->id, 'session_token' => "reading-{$spread}-{$user->id}", 'type' => "tarot_{$spread}",
            'question' => 'เรื่องที่ค้างใจ', 'payload' => ['cost' => 99], 'result' => 'คำพยากรณ์ฉบับเต็ม',
        ]);
        $card = TarotCard::create([
            'slug' => "card-{$spread}", 'name_en' => 'Test Card', 'name_th' => 'ไพ่ทดสอบ',
            'arcana' => 'major', 'suit' => 'major', 'number' => 1,
            'upright_meaning_th' => 'ตั้งตรง', 'reversed_meaning_th' => 'กลับหัว', 'active' => true,
        ]);
        TarotReadingCard::create([
            'reading_id' => $reading->id, 'tarot_card_id' => $card->id,
            'position' => 1, 'position_label' => 'ใบที่ 1', 'reversed' => false,
        ]);

        return $reading;
    }

    public function test_a_kunsai_reading_still_opens_a_chat_while_its_room_is_closed(): void
    {
        // ยังไม่เปิด flag: ห้องคุณไสยปิด แต่แพ็กเกจคุณไสยขายอยู่ — ลูกค้าจ่ายแล้วกด "ถามแม่หมอ" ต้องได้คุยต่อ
        Setting::put('tarot_kunsai_visible', '1', 'pricing', false);
        Cache::flush();
        $user = $this->member();
        $reading = $this->finishedReading($user, 'kunsai');

        $this->actingAs($user)->post(route('chat.from-reading', $reading))->assertRedirect(route('chat.index'));
        $this->actingAs($user)->get(route('chat.index'))->assertOk()->assertViewIs('pages.chat.index');

        $room = ChatConversation::where('reading_id', $reading->id)->firstOrFail();
        $this->assertSame('maemor', $room->persona);
        $this->assertStringStartsWith('แม่หมอเห็นไพ่ทั้ง 1 ใบ', $room->messages()->value('content'));

        // คำทำนายก็ไม่ส่ง persona ตอนยังไม่เปิด flag
        $this->assertSame([], array_filter(collect(Http::recorded())->map(fn ($p) => $p[0]->data()['persona'] ?? null)->all()));
    }

    public function test_a_busaba_reading_is_greeted_in_busaba_voice(): void
    {
        $this->allRoomsOpen();
        $user = $this->member();
        $reading = $this->finishedReading($user, 'quick');

        $this->actingAs($user)->post(route('chat.from-reading', $reading))->assertRedirect(route('chat.index'));

        $room = ChatConversation::where('reading_id', $reading->id)->firstOrFail();
        $this->assertSame('busaba', $room->persona);
        $greeting = $room->messages()->value('content');
        $this->assertStringStartsWith('บุษบาเห็นไพ่ทั้ง 1 ใบ', $greeting);
        $this->assertStringContainsString('ของคุณ', $greeting);
        $this->assertStringNotContainsString('แม่หมอ', $greeting);
    }

    /** 🌸 (2026-11-11, owner) "เธอต้องดูหมด เลยที่ไพ่ไม่ถึง 10 ใบ" — แบบใหม่ที่ไม่ถึง 10 ใบต้องตกเป็นของบุษบาด้วย */
    public function test_every_spread_under_ten_cards_is_busabas(): void
    {
        $this->allRoomsOpen();

        foreach (array_keys(config('tarot_spreads')) as $spread) {
            if (! is_array(config("tarot_spreads.{$spread}.positions"))) {
                continue;
            }
            $owner = ChatRooms::personaForSpread($spread);
            if ($spread === 'kunsai') {
                $this->assertSame('kunsai', $owner);
            } elseif (\App\Support\TarotSpreads::cardCount($spread) < 10) {
                $this->assertSame('busaba', $owner, "{$spread} ไม่ถึง 10 ใบ ต้องเป็นของบุษบา");
            } else {
                $this->assertSame('maemor', $owner, "{$spread} 10 ใบขึ้นไป ต้องเป็นของแม่หมอ");
            }
        }
    }

    /** การ์ดหน้าเลือกผู้พยากรณ์บอกทุกแบบที่ห้องนั้นเปิดไพ่ให้ พร้อมราคา — ไม่ใช่แค่ "ดูไว 3 ใบ" */
    public function test_room_picker_lists_every_spread_of_the_room_with_its_price(): void
    {
        $this->allRoomsOpen();
        $user = $this->member();

        $html = $this->actingAs($user)->get('/chat?pick=1')->assertOk()->assertViewIs('pages.chat.rooms')->getContent();
        $card = function (string $room) use ($html): string {
            $this->assertSame(1, preg_match('~<article[^>]*data-persona="' . $room . '".*?</article>~s', $html, $m), "ไม่เจอการ์ดห้อง {$room}");

            return $m[0];
        };
        $busaba = $card('busaba');
        $maemor = $card('maemor');

        foreach (['quick', 'single', 'three', 'love', 'career', 'decision'] as $spread) {
            $name  = e((string) config("tarot_spreads.{$spread}.name_th"));
            $price = (float) \App\Support\Pricing::for(\App\Support\TarotSpreads::priceKey($spread));
            $this->assertStringContainsString($name, $busaba, "การ์ดบุษบาต้องมี {$spread}");
            $this->assertStringContainsString('฿' . number_format($price, $price == intval($price) ? 0 : 2), $busaba);
            $this->assertStringContainsString(e(route('tarot.index', ['spread' => $spread])), $busaba);
            $this->assertStringNotContainsString($name, $maemor);
        }
        $this->assertStringContainsString(e((string) config('tarot_spreads.celtic.name_th')), $maemor);
        $this->assertStringNotContainsString(e((string) config('tarot_spreads.celtic.name_th')), $busaba);
        $this->assertStringContainsString('ไพ่ 1-5 ใบ', $busaba);
    }

    public function test_room_texts_speak_in_the_rooms_voice_and_mae_mor_text_is_unchanged(): void
    {
        Setting::put('chat_daily_limit', '10', 'chat', false);

        $this->assertSame(
            'วันนี้คุณคุยกับแม่หมอครบ 10 ข้อความแล้วค่ะ ✨ พรุ่งนี้แม่หมอรอฟังเรื่องราวต่อนะคะ — หรือถ้าอยากรู้ลึกถึงดวงชะตา ให้แม่หมอเปิดไพ่ดูแบบเจาะจงได้เลยค่ะ 🔮',
            ChatPolicy::limitMessage(),
        );
        $busaba = ChatPolicy::limitMessage('busaba');
        $this->assertStringContainsString('วันนี้คุณคุยกับบุษบาครบ 10 ข้อความ', $busaba);
        $this->assertStringNotContainsString('แม่หมอ', $busaba);
    }

    public function test_invite_matches_the_card_that_is_actually_shown(): void
    {
        $this->allRoomsOpen();
        $user = $this->member();

        // เรื่องของในห้องบุษบา → การ์ดคุณไสย → คำพูดต้องส่งต่อ ไม่ใช่ "บุษบาเปิดไพ่ 3 ใบให้"
        $this->assertSame(['tarot_kunsai'], array_column(ChatOffers::forRoom('busaba', 'kunsai', $user), 'key'));
        $this->assertSame(config('chat_rooms.rooms.busaba.handoff'), ChatOffers::invite('kunsai', 'busaba', $user));

        // ห้องบุษบามีการ์ด → คำชวนไม่ผูกกับ "3 ใบ" (ยื่นได้หลายแบบแล้ว)
        $this->assertStringNotContainsString('3 ใบ', ChatOffers::invite('career', 'busaba', $user));

        // เพิ่งเปิดไพ่ทุกแบบที่ตรงเรื่องไป (ติดข้อห้ามเปิดซ้ำ) → ไม่มีการ์ด → ห้ามพูดว่า "กดการ์ดด้านล่าง"
        foreach (['quick', 'career', 'decision'] as $i => $spread) {
            Setting::put("tarot_{$spread}_cooldown_days", '7', 'tarot', false);
            Reading::create([
                'user_id' => $user->id, 'session_token' => "q{$i}", 'type' => "tarot_{$spread}",
                'question' => 'งานใหม่จะผ่านไหม', 'payload' => ['cost' => 39], 'result' => 'ผ่าน',
            ]);
        }
        Cache::flush();
        $this->assertSame([], ChatOffers::forRoom('busaba', 'career', $user));
        $line = ChatOffers::invite('career', 'busaba', $user);
        $this->assertSame(config('chat_rooms.rooms.busaba.no_offer'), $line);
        $this->assertStringNotContainsString('การ์ดด้านล่าง', $line);

        // ห้องแม่หมอ — คำชวนเรียก "คุณ" (เจ้าของสั่ง 2026-11-10 เลิกใช้ "ลูก")
        $this->assertStringStartsWith('ได้เลยค่ะ แม่หมอจะเปิดไพ่ดูเรื่องหัวใจให้นะคะ', ChatOffers::invite('love', 'maemor', $user));
    }

    public function test_a_room_without_its_oracle_answers_busy_for_free_and_keeps_the_free_quota(): void
    {
        $this->allRoomsOpen();
        Setting::put('chat_daily_limit', '10', 'chat', false);
        // Thaiprompt ทางของเว็บใช้ไม่ได้ + ลูกค้ามี token บอทเดิม — ห้องบุษบาห้ามถอยไปให้บอทแม่หมอตอบ
        Setting::put('thaiprompt_client_id', '', 'thaiprompt');
        Cache::flush();
        $user = User::factory()->create(['thaiprompt_token' => 'user-token']);

        $this->actingAs($user)->get('/chat?room=busaba')->assertOk();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'งานใหม่เป็นยังไงบ้าง', 'room' => 'busaba'])
            ->assertOk()
            ->assertJsonPath('degraded', true)
            ->assertJsonPath('reply', config('chat_rooms.rooms.busaba.busy_reply'))
            ->assertJsonPath('daily_left', 10);

        $this->assertSame(0, ChatPolicy::dailyUsed($user));
        $this->assertFalse(ChatMessage::where('content', 'งานใหม่เป็นยังไงบ้าง')->exists());
        $this->assertSame([], Http::recorded()->all(), 'ไม่มีคำขอออกไปเลย — ไม่ถอยไปบอทแม่หมอ');
    }

    public function test_a_stale_tab_without_a_room_field_lands_in_mae_mor_room(): void
    {
        $this->allRoomsOpen();
        $user = $this->member();

        $this->actingAs($user)->get('/chat?room=busaba')->assertOk();
        // แท็บเก่า (ก่อนมีห้อง) ส่งมาโดยไม่มี room — ต้องไม่ไปโผล่ห้องบุษบาที่เพิ่งเปิดอีกแท็บ
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'จากแท็บเก่า'])->assertOk();

        $room = ChatMessage::where('content', 'จากแท็บเก่า')->firstOrFail()->conversation;
        $this->assertSame('maemor', $room->persona);
    }

    public function test_kunsai_price_migration_only_moves_the_old_default(): void
    {
        $migration = require database_path('migrations/2026_11_03_000002_raise_kunsai_price_to_139.php');

        Setting::put('pricing_tarot_kunsai', '99', 'pricing', false);
        $migration->up();
        $this->assertSame('139', Setting::where('key', 'pricing_tarot_kunsai')->value('value'));

        // ราคาที่แอดมินตั้งเองไว้ ไม่แตะ
        Setting::put('pricing_tarot_kunsai', '150', 'pricing', false);
        $migration->up();
        $this->assertSame('150', Setting::where('key', 'pricing_tarot_kunsai')->value('value'));
    }

    /** 🌸 (2026-11-11, owner) "ไพ่ 1 ใบ ไม่ถึง 9 ใบเป็นของ บุษบาทั้งหมด" — ห้อง หน้าตา เสียง และคำทำนายเป็นบุษบา */
    public function test_every_spread_under_nine_cards_belongs_to_busaba_once_her_room_is_open(): void
    {
        // ห้องบุษบายังปิด → ไพ่ 1/3/5 ใบยังขายและอ่านในห้องแม่หมอ (ไม่มีบุษบาโผล่ในห้องที่เปิดไม่ได้)
        $this->assertSame('maemor', ChatRooms::personaForSpread('love'));
        $this->assertSame('maemor', \App\Support\OracleMedia::personaFor('single'));

        $this->allRoomsOpen();
        session(['chat_room' => 'maemor']);
        foreach (['single', 'three', 'quick', 'love', 'career', 'decision'] as $spread) {
            $this->assertLessThan(9, \App\Support\TarotSpreads::cardCount($spread), $spread);
            $this->assertSame('busaba', ChatRooms::personaForSpread($spread), $spread);
            $this->assertSame('busaba', ChatOffers::menuRoomOf($spread), $spread);
            // หน้าเลือกไพ่/ผลทำนายพูดด้วยเสียงบุษบา แม้ลูกค้าจะเพิ่งมาจากห้องแม่หมอ
            $this->assertSame('busaba', \App\Support\OracleMedia::personaFor($spread), $spread);
        }
        foreach (['celtic', 'year'] as $spread) {
            $this->assertSame('maemor', ChatRooms::personaForSpread($spread), $spread);
        }
        $this->assertSame('kunsai', ChatRooms::personaForSpread('kunsai'));

        // เปิดห้องบุษบาจากห้องอื่นแล้ว 12 เดือนยังเป็นแม่หมอ (เดิมใช้ห้องใน session → เสียงบุษบาอ่านไพ่แม่หมอ)
        session(['chat_room' => 'busaba']);
        $this->assertSame('maemor', \App\Support\OracleMedia::personaFor('year'));

        // เมนูไพ่ของแต่ละห้อง — แบบประจำห้องขึ้นก่อน
        $this->assertSame(
            ['tarot_quick', 'tarot_single', 'tarot_three', 'tarot_love', 'tarot_career', 'tarot_decision'],
            array_column(ChatOffers::roomMenu('busaba'), 'key'),
        );
        $this->assertSame(['tarot_celtic', 'tarot_year', 'deep'], array_column(ChatOffers::roomMenu('maemor'), 'key'));

        // ห้องบุษบาปิดทีละห้อง → ทุกแบบกลับไปห้องแม่หมอ ยกเว้น "ดูไว" ที่บุษบาอ่านเสมอ
        config(['chat_rooms.closed' => ['busaba']]);
        $this->assertSame('maemor', ChatRooms::personaForSpread('love'));
        $this->assertSame('maemor', ChatOffers::menuRoomOf('love'));
        $this->assertSame('busaba', ChatRooms::personaForSpread('quick'));
    }

    /** ห้องแม่หมอไม่ยื่นการ์ดไพ่ของห้องบุษบา — กดไปแล้วกลายเป็นคำทำนายของบุษบาในห้องแม่หมอ */
    public function test_mae_mor_fallback_cards_skip_busabas_spreads(): void
    {
        $this->allRoomsOpen();
        $user = $this->member();
        // แพ็กเกจประจำห้อง (Celtic) ติดข้อห้ามเปิดซ้ำ → ถอยไปการ์ดตามเรื่อง
        Setting::put('tarot_celtic_cooldown_days', '7', 'pricing', false);
        Cache::flush();
        \App\Models\Reading::create(['user_id' => $user->id, 'session_token' => 's', 'type' => 'tarot_celtic', 'question' => 'q', 'result' => 'r']);
        $cards = ChatOffers::forRoom('maemor', 'love', $user);

        foreach ($cards as $c) {
            if ($c['kind'] === 'tarot') {
                $this->assertSame('maemor', ChatOffers::menuRoomOf((string) $c['spread']), $c['key']);
            }
        }
        $this->assertNotContains('tarot_celtic', array_column($cards, 'key'));
        $this->assertNotContains('tarot_love', array_column($cards, 'key'));
        $this->assertNotContains('tarot_three', array_column($cards, 'key'));
    }
}
