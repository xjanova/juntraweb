<?php

namespace Tests\Feature\Api;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Chat\FreeReadingRoom;
use App\Services\Chat\LiveRoomStats;
use App\Services\Wallet\WalletService;
use App\Support\ChatRooms;
use App\Support\ChatTakeover;
use App\Support\FreeAskClock;
use App\Support\ReadingQaClock;
use App\Support\VoicePremium;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🔮 (2026-11-12) A1–A8 ของสัญญา web-parity — ห้องผู้พยากรณ์ในแอพต้องได้กติกาเดียวกับหน้าเว็บทุกข้อ
 * (นาฬิกาถามต่อ · ถามฟรีหลังไพ่ฟรี · ถามวิธีใช้ไม่คิดเงิน · แอดมินคุมห้อง · ทีละข้อความ · บิลค้างเฉพาะช่องทางตรง)
 */
class ChatRoomsApiTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '0', 'pricing', false);
        Setting::put('promptpay_id', '0812345678', 'pricing', false);
        Setting::put('promptpay_name', 'ร้านแม่หมอ', 'pricing', false);
        Setting::put('tarot_quick_visible', '1', 'tarot', false);
        Setting::put('tarot_kunsai_visible', '1', 'pricing', false);
        config(['chat_rooms.persona_upstream' => true]);
        Cache::flush();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => ['session_id' => 'sess-1', 'reply' => 'ไพ่บอกว่าไปต่อได้ค่ะ', 'kind' => 'reply']]),
                str_ends_with($url, '/voice/transcribe') => Http::response(['data' => ['text' => 'อยากรู้เรื่องงานค่ะ']]),
                str_ends_with($url, '/voice/speak') => Http::response(['data' => ['status' => 'completed', 'clip_id' => 7, 'url' => '/storage/voice/clip-7.mp3']]),
                default => Http::response([], 503),
            };
        });
    }

    private function member(float $credit = 0): User
    {
        $u = User::factory()->create(['thaiprompt_token' => null, 'facebook_user_id' => null, 'line_user_id' => null]);
        if ($credit > 0) {
            app(WalletService::class)->credit($u, $credit, 'seed');
        }

        return $u;
    }

    private function reading(User $user, string $type = 'tarot_celtic', array $payload = []): Reading
    {
        return Reading::create([
            'user_id' => $user->id, 'session_token' => (string) Str::uuid(), 'type' => $type,
            'question' => 'งานจะไปต่อไหม', 'payload' => $payload + ['cost' => 99], 'result' => 'ไพ่ชุดนี้บอกว่างานไปได้ดี',
        ]);
    }

    /** คำทำนายของห้องบุษบาที่กำลังอ่านอยู่ = ล็อกห้อง */
    private function readingInProgress(User $user): Reading
    {
        return Reading::create([
            'user_id' => $user->id, 'session_token' => (string) Str::uuid(), 'type' => 'tarot_quick',
            'question' => 'งานจะไปต่อไหม', 'payload' => ['cost' => 39, 'room' => 'busaba'], 'status' => Reading::STATUS_PENDING,
        ]);
    }

    private function sends(): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/server/chat/send'))->count();
    }

    private function roomConversation(User $user, string $room = 'maemor'): ChatConversation
    {
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/chat/conversations', ['room' => $room])->assertCreated()->json('data.conversation.id');

        return ChatConversation::findOrFail($id);
    }

    public function test_rooms_are_public_with_media_meta_and_no_lock_for_guests(): void
    {
        $res = $this->getJson('/api/v1/chat/rooms')->assertOk();

        $this->assertSame(ChatRooms::keys(), array_column($res->json('data'), 'key'), 'ลำดับเดียวกับ ChatRooms::all()');
        $busaba = collect($res->json('data'))->firstWhere('key', 'busaba');
        $this->assertSame('#7cc7f6', $busaba['accent']);
        $this->assertTrue($busaba['open']);
        $this->assertStringStartsWith('http', $busaba['image_url']);
        $this->assertSame('quick', $busaba['product']['spread']);
        $this->assertSame('quick', $busaba['spreads'][0], 'แบบประจำห้องขึ้นก่อน');
        $this->assertStringStartsWith('http', $busaba['media']['theater']['plate_url']);
        $this->assertNull($busaba['media']['theater']['bg_url']);
        foreach ($busaba['media']['loops'] as $loop) {
            $this->assertStringContainsString('.mp4', $loop, 'แอพวาดได้แค่ mp4');
        }

        $this->assertNull($res->json('meta.lock'));
        $this->assertEquals(\App\Support\ChatPolicy::cost(), $res->json('meta.chat_cost'));
        $this->assertStringContainsString(ChatRooms::PICKER_TAIL, $res->json('meta.lede'));
        $this->assertStringContainsString('หมอดู AI', $res->json('meta.ai_disclosure'));
    }

    public function test_rooms_with_a_bearer_report_the_reading_lock(): void
    {
        $user = $this->member();
        $reading = $this->readingInProgress($user);
        Sanctum::actingAs($user);

        $lock = $this->getJson('/api/v1/chat/rooms')->assertOk()->json('meta.lock');
        $this->assertSame('busaba', $lock['room']);
        $this->assertSame('reading', $lock['reason']);
        $this->assertSame($reading->id, $lock['reading_id']);
        $this->assertGreaterThan(now()->getTimestamp(), $lock['until']);
        $this->assertNotSame('', $lock['message']);
    }

    public function test_live_stats_match_the_web_numbers(): void
    {
        $this->getJson('/api/v1/chat/live')->assertOk()->assertJson(['data' => app(LiveRoomStats::class)->get()]);
    }

    public function test_a_room_resumes_its_latest_conversation_unless_new(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/chat/conversations', ['room' => 'busaba'])->assertCreated();
        $convo = $first->json('data.conversation');
        $this->assertSame('busaba', $convo['persona']);
        $this->assertSame('busaba', $convo['room']['key']);
        $this->assertSame('#7cc7f6', $convo['room']['accent']);
        $this->assertCount(1, $convo['messages'], 'คำทักทายในเครื่อง ไม่ถาม AI');
        $this->assertNull($convo['qa']);
        $this->assertNull($convo['free_ask']);
        $this->assertNull($convo['pending_bill']);
        $this->assertSame('quick', $convo['menu'][0]['spread']);
        $this->assertIsInt($convo['server_time']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/server/chat/start'));

        $again = $this->postJson('/api/v1/chat/conversations', ['room' => 'busaba'])->assertOk();
        $this->assertSame($convo['id'], $again->json('data.conversation.id'));

        $new = $this->postJson('/api/v1/chat/conversations', ['room' => 'busaba', 'new' => true])->assertCreated();
        $this->assertNotSame($convo['id'], $new->json('data.conversation.id'));

        // แอพรุ่นเก่า (ไม่ส่ง room) = ห้องแม่หมอใหม่เหมือนเดิม
        $old = $this->postJson('/api/v1/chat/conversations', [])->assertCreated();
        $this->assertSame('maemor', $old->json('data.conversation.persona'));
    }

    public function test_closed_and_locked_rooms_are_refused(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        config(['chat_rooms.closed' => ['kunsai']]);
        $this->postJson('/api/v1/chat/conversations', ['room' => 'kunsai'])
            ->assertStatus(422)->assertJsonPath('reason_code', 'room_closed');
        config(['chat_rooms.closed' => []]);

        $this->readingInProgress($user);
        $this->postJson('/api/v1/chat/conversations', ['room' => 'maemor'])
            ->assertStatus(423)->assertJsonPath('reason_code', 'room_locked')->assertJsonPath('lock.room', 'busaba');
        // ห้องที่ล็อกอยู่เองเข้าได้
        $this->postJson('/api/v1/chat/conversations', ['room' => 'busaba'])->assertCreated();
    }

    public function test_the_list_filters_by_room_and_names_the_room(): void
    {
        $user = $this->member();
        $this->roomConversation($user, 'busaba');
        $this->roomConversation($user, 'maemor');

        $rows = $this->getJson('/api/v1/chat/conversations?room=busaba')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('busaba', $rows[0]['persona']);
        $this->assertSame('น้องบุษบา', $rows[0]['room']['name']);
        $this->assertCount(2, $this->getJson('/api/v1/chat/conversations')->json('data'));
        $this->getJson('/api/v1/chat/conversations?room=nope')->assertStatus(422);
    }

    public function test_a_pending_bill_reaches_the_direct_app_only(): void
    {
        $user = $this->member();
        $bill = $this->actingAs($user)->postJson(route('chat.topup.store'), ['amount' => 100])->assertOk();
        $convo = $this->roomConversation($user, 'busaba');

        $direct = $this->getJson("/api/v1/chat/conversations/{$convo->id}", ['X-App-Channel' => 'direct'])->assertOk();
        $this->assertSame($bill->json('reference_code'), $direct->json('data.pending_bill.ref'));
        $this->assertSame((float) $bill->json('payable'), (float) $direct->json('data.pending_bill.payable_amount'));
        $this->assertStringStartsWith('000201', (string) $direct->json('data.pending_bill.qr_payload'));
        $this->assertTrue($direct->json('data.pending_bill.blocks'), 'เงินไม่พอเปิดไพ่ = บิลขวางเมนู (เหมือนเว็บ)');
        $this->assertSame([], $direct->json('data.menu'));

        // Google Play: ห้ามมีบิล/QR และบิลจากเว็บไม่ขวางเมนู · บอทก็ไม่ทวงบิลแทนการ์ด
        $play = $this->getJson("/api/v1/chat/conversations/{$convo->id}", ['X-App-Channel' => 'play'])->assertOk();
        $this->assertNull($play->json('data.pending_bill'));
        $this->assertNotSame([], $play->json('data.menu'));
        $this->assertStringNotContainsString('000201', $play->getContent());

        $res = $this->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'เปิดไพ่ให้หน่อย'], ['X-App-Channel' => 'play'])->assertOk();
        $this->assertStringNotContainsString((string) $bill->json('reference_code'), (string) $res->json('data.reply'));
        $this->assertNull($res->json('data.pending_bill'));
    }

    public function test_a_help_question_is_free_even_when_chat_is_paid(): void
    {
        Setting::put('pricing_chat_message_enabled', '1', 'pricing', false);
        Setting::put('chat_daily_limit', '0', 'chat', false);
        Cache::forget('settings.all');
        $user = $this->member();
        $convo = $this->roomConversation($user);

        $res = $this->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'เติมเงินยังไงคะ'])->assertOk();
        $this->assertSame('help', $res->json('data.kind'));
        $this->assertSame(0, (int) $res->json('data.cost'));
        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->where('type', 'debit')->count());

        // ข้อความทั่วไปยังคิดเงินตามปกติ (เครดิต 0 = 402)
        $this->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'สวัสดีค่ะ'])
            ->assertStatus(402)->assertJsonPath('reason_code', 'insufficient_funds');
    }

    public function test_follow_up_time_over_is_409_and_costs_nothing(): void
    {
        Setting::put('pricing_chat_message_enabled', '1', 'pricing', false);
        Setting::put('chat_daily_limit', '0', 'chat', false);
        Cache::forget('settings.all');
        $user = $this->member(50);
        $reading = $this->reading($user);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/chat/conversations', ['reading_id' => $reading->id])->assertCreated()->json('data.conversation.id');

        $first = $this->postJson("/api/v1/chat/conversations/{$id}/send", ['message' => 'ข้อแรกค่ะ'])->assertOk();
        $this->assertTrue($first->json('data.qa.started'));
        $this->assertSame([], $first->json('data.menu'), 'ระหว่างถามต่อไม่มีเมนูไพ่ชุดใหม่');

        ChatConversation::whereKey($id)->update(['qa_started_at' => now()->subHours(2)]);
        $debits = WalletTransaction::where('user_id', $user->id)->where('type', 'debit')->count();
        $messages = ChatMessage::where('chat_conversation_id', $id)->count();
        $sends = $this->sends();

        $this->postJson("/api/v1/chat/conversations/{$id}/send", ['message' => 'ข้อสองค่ะ'])
            ->assertStatus(409)->assertJsonPath('reason_code', 'qa_over')->assertJsonPath('qa.over', true)
            ->assertJsonPath('message', ReadingQaClock::overMessage(ChatConversation::find($id)->persona));
        $this->assertSame($debits, WalletTransaction::where('user_id', $user->id)->where('type', 'debit')->count());
        $this->assertSame($messages, ChatMessage::where('chat_conversation_id', $id)->count());
        $this->assertSame($sends, $this->sends(), 'หมดเวลาแล้วไม่ถาม AI');
    }

    public function test_enough_ends_the_clock_once(): void
    {
        $user = $this->member();
        $reading = $this->reading($user);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/chat/conversations', ['reading_id' => $reading->id])->assertCreated()->json('data.conversation.id');
        $this->postJson("/api/v1/chat/conversations/{$id}/send", ['message' => 'ข้อแรกค่ะ'])->assertOk();

        $end = $this->postJson("/api/v1/chat/conversations/{$id}/qa/end")->assertOk();
        $this->assertTrue($end->json('data.qa.over'));
        $this->assertSame(ReadingQaClock::farewell(ChatConversation::find($id)->persona), $end->json('data.message.content'));

        $this->postJson("/api/v1/chat/conversations/{$id}/qa/end")->assertOk()->assertJsonPath('data.message', null);
        $this->assertSame(1, ChatMessage::where('chat_conversation_id', $id)->where('content', $end->json('data.message.content'))->count());

        $plain = $this->roomConversation($user);
        $this->postJson("/api/v1/chat/conversations/{$plain->id}/qa/end")->assertStatus(422)->assertJsonPath('reason_code', 'no_clock');
    }

    public function test_after_free_time_the_room_invites_a_full_spread_without_ai_or_charge(): void
    {
        $user = $this->member();
        $free = $this->reading($user, 'tarot_single', ['free' => true]);
        $convo = FreeReadingRoom::open($user, $free);
        Sanctum::actingAs($user);

        $show = $this->getJson("/api/v1/chat/conversations/{$convo->id}")->assertOk();
        $this->assertSame($free->id, $show->json('data.free_reading_id'));
        $this->assertSame(FreeAskClock::minutes(), $show->json('data.free_ask.minutes'));

        ChatConversation::whereKey($convo->id)->update(['qa_started_at' => now()->subMinutes(10)]);
        $sends = $this->sends();
        $res = $this->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'แฟนจะกลับมาไหมคะ'])->assertOk();

        $this->assertSame($sends, $this->sends(), 'หมดเวลาถามฟรีแล้วต้องไม่ถาม AI');
        $this->assertSame('offer', $res->json('data.kind'));
        $this->assertSame(0, (int) $res->json('data.cost'));
        $this->assertTrue($res->json('data.free_ask.over'));
        $this->assertNotSame([], $res->json('data.offers'));
        $this->assertSame('assistant', $res->json('data.message.role'));
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_staff_takeover_holds_the_message_and_the_feed_returns_staff_lines(): void
    {
        $user = $this->member();
        $convo = $this->roomConversation($user);
        $staff = User::factory()->create();
        $said = ChatTakeover::say($convo, $staff, 'สวัสดีค่ะ ทีมงานช่วยดูให้นะคะ', ChatMessage::AS_ADMIN);
        Sanctum::actingAs($user);

        $sends = $this->sends();
        $res = $this->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'ขอบคุณค่ะ'])->assertOk();
        $this->assertTrue($res->json('data.held'));
        $this->assertNull($res->json('data.message'));
        $this->assertSame($sends, $this->sends());
        $this->assertSame(ChatMessage::HELD, ChatMessage::where('content', 'ขอบคุณค่ะ')->value('admin_as'));

        $feed = $this->getJson("/api/v1/chat/conversations/{$convo->id}/feed?after=0")->assertOk();
        $this->assertSame([$said->id], array_column($feed->json('data'), 'id'));
        $this->assertSame('admin', $feed->json('data.0.admin_as'));
        $this->assertTrue($feed->json('data.0.from_admin'));
        $this->assertTrue($feed->json('meta.held'));
        $this->assertSame([], $this->getJson("/api/v1/chat/conversations/{$convo->id}/feed?after={$said->id}")->json('data'));

        Sanctum::actingAs($this->member());
        $this->getJson("/api/v1/chat/conversations/{$convo->id}/feed")->assertForbidden();
    }

    public function test_a_second_device_sending_at_the_same_time_waits_without_charge(): void
    {
        $user = $this->member();
        $convo = $this->roomConversation($user);
        $lock = Cache::lock("chat:inflight:u{$user->id}", 60);
        $this->assertTrue($lock->get());

        $this->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'สวัสดีค่ะ'], ['Idempotency-Key' => 'k-1'])
            ->assertStatus(409)->assertJsonPath('reason_code', 'busy_other');
        $this->assertSame(0, ChatMessage::where('chat_conversation_id', $convo->id)->where('role', 'user')->count());

        // ข้อความแรกตอบเสร็จ → ส่งใหม่ด้วยคีย์เดิมได้ทันที (ไม่ค้าง in_flight)
        $lock->release();
        $this->postJson("/api/v1/chat/conversations/{$convo->id}/send", ['message' => 'สวัสดีค่ะ'], ['Idempotency-Key' => 'k-1'])->assertOk()
            ->assertJsonPath('data.reply_voice', null)
            ->assertJsonStructure(['data' => ['message', 'reply', 'qa', 'free_ask', 'pending_bill', 'menu', 'server_time', 'ai_suggestions']]);
    }

    public function test_a_premium_follow_up_answer_carries_the_room_voice(): void
    {
        $user = $this->member(200);
        $tx = app(WalletService::class)->debit($user, 100, 'พรีเมียมเสียงผู้พยากรณ์: ทดสอบ', [
            'reference_type' => 'voice_premium', 'idempotency_key' => VoicePremium::idempotencyKey($user->id, (string) Str::uuid()),
        ]);
        $card = TarotCard::create([
            'slug' => 'the-sun', 'name_en' => 'The Sun', 'name_th' => 'ดวงอาทิตย์', 'arcana' => 'major', 'suit' => 'major',
            'number' => 19, 'keywords_th' => 'สำเร็จ', 'upright_meaning_th' => 'ความสำเร็จ', 'reversed_meaning_th' => 'ล่าช้า', 'active' => true,
        ]);
        $reading = $this->reading($user, 'tarot_celtic', [
            'premium' => ['status' => 'paid', 'price' => 100, 'tx_id' => $tx->id, 'at' => now()->toIso8601String()],
        ]);
        $reading->tarotCards()->create(['tarot_card_id' => $card->id, 'position' => 1, 'position_label' => 'ปัจจุบัน', 'reversed' => false]);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/chat/conversations', ['reading_id' => $reading->id])->assertCreated()->json('data.conversation.id');

        $res = $this->postJson("/api/v1/chat/conversations/{$id}/send", ['message' => 'งานจะไปต่อไหมคะ'])->assertOk();
        $this->assertSame(self::TP . '/storage/voice/clip-7.mp3', $res->json('data.reply_voice.url'));
    }

    public function test_the_mic_returns_text_with_the_web_limits(): void
    {
        Sanctum::actingAs($this->member());

        $this->post('/api/v1/chat/transcribe', ['audio' => UploadedFile::fake()->createWithContent('mic.wav', $this->wav(20_000))], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.text', 'อยากรู้เรื่องงานค่ะ');
        $this->post('/api/v1/chat/transcribe', ['audio' => UploadedFile::fake()->createWithContent('mic.wav', $this->wav(900_000))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.audio.0', 'ยาวเกินไปค่ะ ลองพูดสั้น ๆ ทีละเรื่องนะคะ');
    }

    /** ไฟล์ WAV จริง (หัวไฟล์ถูกต้อง) — กฎ mimetypes อ่านชนิดไฟล์จากเนื้อไฟล์ ไม่ใช่นามสกุล */
    private function wav(int $bytes): string
    {
        $data = str_repeat("\0", $bytes);

        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            . 'data' . pack('V', strlen($data)) . $data;
    }

    public function test_guests_cannot_use_room_conversations(): void
    {
        $this->postJson('/api/v1/chat/conversations', ['room' => 'busaba'])->assertUnauthorized();
        $this->postJson('/api/v1/chat/transcribe')->assertUnauthorized();
    }
}
