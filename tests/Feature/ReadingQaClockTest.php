<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ⏳ (2026-11-05) นาฬิกาถามต่อหลังเปิดไพ่ — กติกาเดียวกับ Pro Session ของบอท FB/LINE
 *
 * เจ้าของสั่ง "นาฬิกาเริ่มนับถอยหลังตามระบบจริงที่เราจับเวลา" · "ทำไมบอทไม่รู้ว่าเหลือเวลาเท่าไหร่"
 *            "ปุ่ม พอแล้ว ด้วย หมายถึงเลิกก่อนเวลา และควรมีนาฬิกาให้เห็นนับถอยหลัง จากคำถามแรก"
 */
class ReadingQaClockTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    /** จำลองระบบผู้พยากรณ์ล่ม (ส่งข้อความแล้วไม่ได้คำตอบจริง) */
    private bool $down = false;

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
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => $this->down
                    ? Http::response([], 503)
                    : Http::response(['data' => ['session_id' => 'sess-1', 'reply' => 'ไพ่บอกว่าไปต่อได้จ้ะ', 'kind' => 'reply']]),
                default => Http::response([], 503),
            };
        });
    }

    private function member(): User
    {
        return User::factory()->create(['thaiprompt_token' => null, 'facebook_user_id' => null, 'line_user_id' => null]);
    }

    private function reading(User $user, string $type = 'tarot_celtic'): Reading
    {
        return Reading::create([
            'user_id' => $user->id, 'session_token' => 'r-' . $user->id . '-' . $type, 'type' => $type,
            'question' => 'งานจะไปต่อไหม', 'payload' => ['cost' => 99], 'result' => 'ไพ่ชุดนี้บอกว่างานไปได้ดี',
        ]);
    }

    /** เข้าห้องคุยต่อจากไพ่ → คืนห้อง */
    private function enter(User $user, Reading $reading): ChatConversation
    {
        $this->actingAs($user)->post(route('chat.from-reading', $reading))->assertRedirect();

        return ChatConversation::where('reading_id', $reading->id)->firstOrFail();
    }

    private function contexts(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($p) => str_ends_with($p[0]->url(), '/server/chat/send'))
            ->map(fn ($p) => (string) ($p[0]->data()['context'] ?? ''))
            ->values()->all();
    }

    public function test_the_clock_starts_at_the_first_question_and_the_oracle_is_told_the_time_left(): void
    {
        $user = $this->member();
        $room = $this->enter($user, $this->reading($user));

        // เปิดห้องแล้วยังไม่นับ — นับจากคำถามแรก
        $page = $this->actingAs($user)->get('/chat?room=' . $room->persona)->assertOk();
        $page->assertViewHas('qa', fn ($q) => $q['minutes'] === 15 && $q['started'] === false && $q['over'] === false);
        $page->assertSee('พอแล้ว', false);

        $this->travel(2)->minutes();
        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'งานจะไปต่อไหมคะ', 'room' => $room->persona])->assertOk();
        $this->assertTrue($res->json('qa.started'));
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), $res->json('qa.ends_at'));
        $this->assertStringStartsWith('⏱️ เวลาถามต่อของไพ่ชุดนี้: เหลืออีกประมาณ 15 นาที จากทั้งหมด 15 นาที', $this->contexts()[0]);
        $this->assertStringContainsString('ไพ่ชุดนี้บอกว่างานไปได้ดี', $this->contexts()[0], 'บริบทไพ่ต้องยังอยู่ครบ');

        // ใกล้หมด → ผู้พยากรณ์ถูกบอกให้เตือนลูกค้า
        $this->travel(13)->minutes();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'แล้วเรื่องเงินล่ะคะ', 'room' => $room->persona])->assertOk();
        $this->assertStringContainsString('ใกล้หมดเวลาแล้ว', $this->contexts()[1]);
        $this->assertStringContainsString('เหลืออีกประมาณ 2 นาที', $this->contexts()[1]);
    }

    public function test_after_time_is_up_nothing_is_sent_or_charged(): void
    {
        Setting::put('pricing_chat_message_enabled', '1', 'pricing', false);
        Setting::put('chat_daily_limit', '0', 'chat', false);
        Cache::flush();
        $user = $this->member();
        app(\App\Services\Wallet\WalletService::class)->credit($user, 50, 'เครดิตทดสอบ');
        $reading = $this->reading($user, 'tarot_three');           // ชุดเล็ก = 7 นาที
        $room = $this->enter($user, $reading);

        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ข้อแรกค่ะ', 'room' => $room->persona])->assertOk()
            ->assertJsonPath('qa.minutes', 7);
        $debits = WalletTransaction::where('user_id', $user->id)->where('type', 'debit')->count();
        $messages = ChatMessage::where('chat_conversation_id', $room->id)->count();

        $this->travel(8)->minutes();
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ข้อสองค่ะ', 'room' => $room->persona])
            ->assertStatus(403)->assertJsonPath('reason_code', 'qa_over')->assertJsonPath('qa.over', true);

        $this->assertSame($debits, WalletTransaction::where('user_id', $user->id)->where('type', 'debit')->count(), 'หมดเวลาแล้วห้ามคิดเงิน');
        $this->assertSame($messages, ChatMessage::where('chat_conversation_id', $room->id)->count());
        $this->assertCount(1, $this->contexts(), 'หมดเวลาแล้วห้ามถาม AI');
    }

    public function test_enough_ends_early_once_and_talking_about_something_else_opens_a_plain_room(): void
    {
        $user = $this->member();
        $room = $this->enter($user, $this->reading($user));
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ข้อแรกค่ะ', 'room' => $room->persona])->assertOk();

        $end = $this->actingAs($user)->postJson(route('chat.qa.end'), ['room' => $room->persona])->assertOk();
        $this->assertTrue($end->json('qa.over'));
        $this->assertStringContainsString('ขอบคุณที่ให้', (string) $end->json('reply'));

        // กดซ้ำ — ไม่มีคำลาซ้ำ
        $again = $this->actingAs($user)->postJson(route('chat.qa.end'), ['room' => $room->persona])->assertOk();
        $this->assertNull($again->json('reply'));
        $this->assertSame(1, ChatMessage::where('chat_conversation_id', $room->id)->where('content', 'like', 'ขอบคุณที่ให้%')->count());

        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ถามอีกข้อ', 'room' => $room->persona])
            ->assertStatus(403)->assertJsonPath('reason_code', 'qa_over');

        // "คุยเรื่องอื่นต่อ" → ห้องคุยทั่วไป (ไม่มีนาฬิกา) ห้องของไพ่ยังอยู่ในประวัติ
        $this->actingAs($user)->get('/chat?room=' . $room->persona . '&fresh=1')->assertOk()->assertViewHas('qa', null);
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'คุยเรื่องอื่นค่ะ', 'room' => $room->persona])->assertOk()
            ->assertJsonPath('qa', null);
        $this->assertNotNull(ChatConversation::find($room->id));
    }

    public function test_no_new_spread_is_offered_while_the_follow_up_window_is_open(): void
    {
        $user = $this->member();
        $room = $this->enter($user, $this->reading($user));
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ข้อแรกค่ะ', 'room' => $room->persona])->assertOk();

        // ระหว่างถามต่อ = ยังอยู่ในการทำนาย → ไม่มีป้ายเปิดไพ่ชุดใหม่บนโต๊ะ
        $this->actingAs($user)->get('/chat?room=' . $room->persona)->assertOk()
            ->assertViewHas('tableOffers', [])
            ->assertViewHas('offerCards', fn ($c) => $c['offers'] === []);

        // กด "พอแล้ว" → จบการทำนาย ป้ายกลับมา
        $this->actingAs($user)->postJson(route('chat.qa.end'), ['room' => $room->persona])->assertOk();
        $this->actingAs($user)->get('/chat?room=' . $room->persona)->assertOk()
            ->assertViewHas('tableOffers', fn ($o) => $o !== []);
    }

    public function test_a_first_question_that_got_no_real_answer_does_not_start_the_clock(): void
    {
        $user = $this->member();
        $room = $this->enter($user, $this->reading($user));

        $this->down = true;
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'ข้อแรกค่ะ', 'room' => $room->persona]);
        $this->assertNull($room->fresh()->qa_started_at, 'ระบบล่มตอนคำถามแรก ห้ามเริ่มนับเวลาของลูกค้า');

        $this->down = false;
        $this->travel(5)->minutes();
        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'ข้อแรกอีกครั้งค่ะ', 'room' => $room->persona])->assertOk();
        $this->assertTrue($res->json('qa.started'));
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), $res->json('qa.ends_at'), 'นับเต็ม 15 นาทีจากคำถามที่ได้คำตอบจริง');
    }

    public function test_a_plain_room_has_no_clock_and_enough_is_refused(): void
    {
        $user = $this->member();
        $this->actingAs($user)->get('/chat?room=maemor')->assertOk()->assertViewHas('qa', null);
        $this->actingAs($user)->postJson(route('chat.qa.end'), ['room' => 'maemor'])->assertStatus(422);
        \Illuminate\Support\Facades\Auth::logout();
        $this->post(route('chat.qa.end'))->assertRedirect();   // ยังไม่ล็อกอิน
    }
}
