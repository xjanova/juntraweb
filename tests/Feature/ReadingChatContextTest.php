<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\TarotReadingCard;
use App\Models\User;
use App\Services\Chat\ReadingChatContext;
use App\Services\FortuneBot\FortuneAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 💬 (2026-09-21) แม่หมอต้อง "รู้จริง" ว่าลูกค้าได้คำพยากรณ์อะไรไป แล้วคุยต่อได้ต่อเนื่อง
 *
 * ตรวจบน prod ก่อนแก้: บริบทไปเป็นข้อความแชทที่ถูกตัดที่ 1,000 ตัว — คำพยากรณ์ 12 เดือน (7,496 ตัว)
 * ไปถึงแม่หมอแค่ไพ่เดือน 1-4 ไม่มีเนื้อคำพยากรณ์เลย ตำแหน่งเป็นแค่ "เดือนที่ N" · ลิงก์ห้อง↔ไพ่อยู่ใน session
 * เบราว์เซอร์ (หมดอายุ ~2 ชม.) · แอพไม่มีทางผูกห้องกับไพ่เลย · ทางสำรองได้แค่ข้อความของลูกค้า
 *
 * วันที่เปิดไพ่ตรึงไว้ (5 ต.ค. 2569 เวลาไทย) แล้ว assert ชื่อเดือนที่ FortuneAiService::yearMonths
 * คำนวณได้ก่อนใช้ — ไม่เดาเอาเองว่าเดือนที่ 6 คือ มี.ค.
 */
class ReadingChatContextTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    /** รหัสห้องที่ /server/chat/start แจกทีละห้อง */
    private int $starts = 0;

    /** ห้องที่ Thaiprompt ไม่รู้จักแล้ว (หมดอายุ) — send เข้าห้องนี้ได้ 503 */
    private ?string $staleSession = null;

    /** Thaiprompt ล่มทั้งสาย */
    private bool $upstreamDown = false;

    private const MARCH_WARNING = 'ระวังเงินรั่วจากคนใกล้ตัวช่วงกลางเดือน';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        // คุยฟรี (สวิตช์เดียวกับหลังบ้าน) — เทสต์นี้ดูบริบท ไม่ได้ดูเงิน
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '0', 'pricing', false);
        Cache::flush();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                $this->upstreamDown && str_contains($url, '/server/chat/') => Http::response(['message' => 'down'], 503),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => [
                    'session_id' => sprintf('5b0f7a3e-1c2d-4e5f-8a9b-%012d', ++$this->starts),
                    'greeting'   => 'สวัสดีค่ะลูก',
                ]], 201),
                str_ends_with($url, '/server/chat/send') && $req['session_id'] === $this->staleSession
                    => Http::response(['message' => 'แม่หมอกำลังพักสายตา', 'reason_code' => 'ai_unavailable'], 503),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => [
                    'session_id' => $req['session_id'], 'reply' => 'เดือนมีนาไพ่เตือนเรื่องเงินค่ะลูก', 'kind' => 'reply', 'offer_topic' => null,
                ]]),
                str_contains($url, 'generativelanguage.googleapis.com') => Http::response([
                    'candidates' => [['content' => ['parts' => [['text' => 'แม่หมอ (สำรอง) ตอบจากไพ่ชุดเดิมค่ะ']]]]],
                ]),
                default => Http::response(['message' => 'unexpected ' . $url], 500),
            };
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function member(): User
    {
        return User::factory()->create(['thaiprompt_token' => null, 'facebook_user_id' => null, 'line_user_id' => null]);
    }

    /** คำพยากรณ์ 12 เดือนแบบที่แม่หมอตอบจริง (หัวข้อ "## 📅 เดือน · โทน") ยาวเกิน 7,000 ตัวเหมือนบน prod */
    private function yearResult(array $months, int $filler = 560): string
    {
        $parts = ["## 🎯 ฟันธง\nผล: ปีแห่งการเติบโต แต่ต้องคุมเงินให้อยู่"];
        foreach ($months as $m) {
            $march = $m === 'มี.ค. 2570';
            $parts[] = "## 📅 {$m} · " . ($march ? 'ระวัง' : 'ดี') . "\n"
                . 'ธีม: ' . ($march ? self::MARCH_WARNING : "จังหวะดีของ {$m}") . "\n"
                . mb_substr(str_repeat("รายละเอียดคำพยากรณ์ของเดือน {$m} ", 40), 0, $filler);
        }
        $parts[] = "## ⚠️ เดือนที่ต้องระวัง\n- มี.ค. 2570: เงินรั่ว\nEND-OF-READING";

        return implode("\n\n", $parts);
    }

    /** ไพ่ 12 เดือนของลูกค้าคนนี้ เปิดเมื่อ 5 ต.ค. 2569 10:00 (เวลาไทย) */
    private function yearReading(User $user, ?string $result = null, ?string $status = null): Reading
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Asia/Bangkok'));

        $reading = Reading::create([
            'user_id' => $user->id, 'session_token' => 'reading-' . $user->id, 'type' => 'tarot_year',
            'question' => 'ปีหน้าการเงินของหนูจะเป็นยังไง', 'payload' => ['cost' => 199],
            'result' => null, 'status' => $status,
        ]);
        for ($i = 1; $i <= 12; $i++) {
            $card = TarotCard::create([
                'slug' => "test-card-{$i}", 'name_en' => "Test Card {$i}", 'name_th' => "ไพ่ทดสอบ {$i}",
                'arcana' => 'major', 'suit' => 'major', 'number' => $i,
                'upright_meaning_th' => "ความหมายตั้งตรง {$i}", 'reversed_meaning_th' => "ความหมายกลับหัว {$i}", 'active' => true,
            ]);
            TarotReadingCard::create([
                'reading_id' => $reading->id, 'tarot_card_id' => $card->id,
                'position' => $i, 'position_label' => "เดือนที่ {$i}", 'reversed' => $i === 6,
            ]);
        }

        $months = FortuneAiService::yearMonths($reading);
        // ตรึงผลคำนวณ: เปิดไพ่วันที่ 5 (ไม่เกิน 20) → เดือนแรก = เดือนนี้ · เดือนที่ 6 = มี.ค. 2570
        $this->assertSame('ต.ค. 2569', $months[0]);
        $this->assertSame('มี.ค. 2570', $months[5]);
        $this->assertSame('ก.ย. 2570', $months[11]);

        $reading->update(['result' => $result ?? $this->yearResult($months)]);
        Carbon::setTestNow();

        return $reading->fresh();
    }

    private function sent(string $suffix)
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), $suffix))->map(fn ($pair) => $pair[0]);
    }

    /* ───────────────────────── บริบท ───────────────────────── */

    public function test_the_year_context_carries_all_twelve_cards_with_real_months_and_the_full_reading(): void
    {
        $reading = $this->yearReading($this->member());
        $months = FortuneAiService::yearMonths($reading);
        $this->assertGreaterThan(7000, mb_strlen($reading->result), 'ยาวระดับเดียวกับคำพยากรณ์จริงบน prod');

        $ctx = ReadingChatContext::for($reading);

        $this->assertStringContainsString('แพ็กเกจ: พยากรณ์ 12 เดือน (ไพ่ 12 ใบ)', $ctx);
        $this->assertStringContainsString('เปิดไพ่เมื่อ: 5 ต.ค. 2569', $ctx);
        $this->assertStringContainsString('คำถามของลูก: ปีหน้าการเงินของหนูจะเป็นยังไง', $ctx);
        foreach ($months as $i => $month) {
            $n = $i + 1;
            $this->assertStringContainsString("{$n}. เดือนที่ {$n} · {$month}: ไพ่ทดสอบ {$n} (Test Card {$n})", $ctx);
        }
        $this->assertStringContainsString('6. เดือนที่ 6 · มี.ค. 2570: ไพ่ทดสอบ 6 (Test Card 6) · กลับหัว — ความหมายพื้นฐาน: ความหมายกลับหัว 6', $ctx);
        $this->assertStringContainsString('## 📅 มี.ค. 2570 · ระวัง', $ctx);
        $this->assertStringContainsString(self::MARCH_WARNING, $ctx);
        $this->assertStringContainsString('END-OF-READING', $ctx, 'คำพยากรณ์ครบถึงบรรทัดสุดท้าย');
        $this->assertStringContainsString($reading->result, $ctx, 'คำพยากรณ์ฉบับเต็ม ไม่ตัดเลย');
        $this->assertLessThanOrEqual(ReadingChatContext::MAX_CHARS, mb_strlen($ctx));
    }

    public function test_an_oversized_reading_is_trimmed_from_the_tail_only(): void
    {
        $user = $this->member();
        $reading = $this->yearReading($user);
        $reading->update(['result' => $this->yearResult(FortuneAiService::yearMonths($reading), 2000)]);
        $this->assertGreaterThan(ReadingChatContext::MAX_CHARS, mb_strlen($reading->result));

        $ctx = ReadingChatContext::for($reading->fresh());

        $this->assertSame(ReadingChatContext::MAX_CHARS, mb_strlen($ctx));
        for ($n = 1; $n <= 12; $n++) {
            $this->assertStringContainsString("{$n}. เดือนที่ {$n} · ", $ctx, 'ไพ่ต้องครบทุกใบเสมอ');
        }
        $this->assertStringContainsString('## 🎯 ฟันธง', $ctx, 'หัวคำพยากรณ์ยังอยู่');
        $this->assertStringNotContainsString('END-OF-READING', $ctx);
        $this->assertStringEndsWith('ตัดท้ายออก)', $ctx);
    }

    /* ───────────────────────── เว็บ ───────────────────────── */

    public function test_asking_mae_mor_sends_the_full_reading_as_start_context_not_as_a_chat_message(): void
    {
        $user = $this->member();
        $reading = $this->yearReading($user);

        $this->actingAs($user)
            ->post(route('chat.from-reading', $reading), ['question' => 'เดือนมีนาที่ไพ่บอกให้ระวัง หมายถึงอะไรคะ'])
            ->assertRedirect(route('chat.index'))
            ->assertSessionHas('chat_autosend', 'เดือนมีนาที่ไพ่บอกให้ระวัง หมายถึงอะไรคะ');

        // ไม่มีข้อความ primer ในแชทอีกแล้ว — บริบทไปกับการเปิดห้องทั้งก้อน
        $this->assertCount(0, $this->sent('/server/chat/send'), 'ไม่เสียรอบสนทนาไปกับการ prime');
        $start = $this->sent('/server/chat/start')->sole();
        $this->assertGreaterThan(1000, mb_strlen($start['context']), 'ไม่ถูกตัดที่ 1,000 แบบข้อความแชท');
        $this->assertStringContainsString('## 📅 มี.ค. 2570 · ระวัง', $start['context']);
        $this->assertStringContainsString(self::MARCH_WARNING, $start['context']);
        $this->assertStringContainsString('12. เดือนที่ 12 · ก.ย. 2570', $start['context']);
        $this->assertStringContainsString('END-OF-READING', $start['context']);

        $room = ChatConversation::where('user_id', $user->id)->sole();
        $this->assertSame($reading->id, $room->reading_id, 'ห้องผูกกับไพ่ในฐานข้อมูล');
        $this->assertStringContainsString('ไพ่ทั้ง 12 ใบ', $room->messages()->sole()->content);

        // หน้าแชทรู้ว่าห้องนี้คุยต่อจากไพ่ (ปุ่มคำถามลัดชุดไพ่ + คำถามเดิม)
        $this->actingAs($user)->get(route('chat.index'))->assertOk()
            ->assertViewHas('suggestions', fn ($s) => collect($s)->contains(fn ($chip) => $chip['label'] === 'ตอบคำถามเดิมให้ชัด'));

        // คำถามแรกที่ส่งอัตโนมัติ — ถามแม่หมอพร้อมบริบทเต็ม ไม่โดนเปลี่ยนเป็นใบเสนอแพ็กเกจ
        $this->actingAs($user)
            ->postJson(route('chat.send'), ['message' => 'เดือนมีนาที่ไพ่บอกให้ระวัง หมายถึงอะไรคะ'])
            ->assertOk()
            ->assertJsonPath('kind', 'reply')
            ->assertJsonPath('reply', 'เดือนมีนาไพ่เตือนเรื่องเงินค่ะลูก');

        $send = $this->sent('/server/chat/send')->sole();
        $this->assertSame('เดือนมีนาที่ไพ่บอกให้ระวัง หมายถึงอะไรคะ', $send['text']);
        $this->assertSame(1, (int) $send['grounded']);
        $this->assertSame($start['context'], $send['context']);
        $this->assertSame($start['user_ref'], $send['user_ref']);
    }

    public function test_coming_back_days_later_reuses_the_room_and_rebuilds_the_context_from_the_database(): void
    {
        $user = $this->member();
        $reading = $this->yearReading($user);

        $this->actingAs($user)->post(route('chat.from-reading', $reading))->assertRedirect(route('chat.index'));
        $room = ChatConversation::where('user_id', $user->id)->sole();

        // กดซ้ำ (รีเฟรช/กดเบิ้ล) ใน session เดิม — ห้องเดิม ไม่มีคำทักทายซ้อน ไม่เปิดห้อง Thaiprompt ใหม่
        $this->actingAs($user)->post(route('chat.from-reading', $reading))->assertRedirect(route('chat.index'));
        $this->assertSame(1, $room->messages()->count());
        $this->assertCount(1, $this->sent('/server/chat/start'));

        // สามวันต่อมา session เบราว์เซอร์หมดอายุไปแล้ว — กดถามแม่หมอจากหน้าผลอีกครั้ง
        $this->flushSession();
        $this->travel(3)->days();

        $this->actingAs($user)
            ->post(route('chat.from-reading', $reading), ['question' => 'แล้วเดือนมีนาควรทำยังไงดีคะ'])
            ->assertRedirect(route('chat.index'))
            ->assertSessionHas('chat_token', $room->session_token);

        $this->assertSame(1, ChatConversation::where('user_id', $user->id)->count(), 'เข้าห้องเดิม ไม่สร้างห้องใหม่');
        $this->assertSame(1, $room->messages()->count(), 'ไม่มีคำทักทายซ้อน');

        $starts = $this->sent('/server/chat/start');
        $this->assertCount(2, $starts);
        $this->assertStringContainsString(self::MARCH_WARNING, $starts->last()['context'], 'บริบทสร้างใหม่จากฐานข้อมูล');

        $this->actingAs($user)->postJson(route('chat.send'), ['message' => 'แล้วเดือนมีนาควรทำยังไงดีคะ'])->assertOk();
        $this->assertStringContainsString(self::MARCH_WARNING, $this->sent('/server/chat/send')->last()['context']);
    }

    public function test_an_expired_upstream_room_is_reopened_with_the_context_rebuilt_from_reading_id(): void
    {
        $user = $this->member();
        $reading = $this->yearReading($user);
        ChatConversation::create(['user_id' => $user->id, 'session_token' => 'room-tok', 'reading_id' => $reading->id]);
        $this->staleSession = 'aaaaaaaa-1c2d-4e5f-8a9b-000000000000';

        // session เบราว์เซอร์มีแค่ห้องสด + ห้อง Thaiprompt ที่หมดอายุไปแล้ว — ไม่มีป้าย/บริบทไพ่อะไรเลย
        $this->actingAs($user)
            ->withSession(['chat_token' => 'room-tok', 'thaiprompt_chat_session' => 'srv:' . $this->staleSession])
            ->postJson(route('chat.send'), ['message' => 'เดือนมีนาหมายถึงอะไรคะ'])
            ->assertOk()
            ->assertJsonPath('degraded', false)
            ->assertJsonPath('kind', 'reply');

        $start = $this->sent('/server/chat/start')->sole();
        $this->assertStringContainsString(self::MARCH_WARNING, $start['context']);

        $ok = $this->sent('/server/chat/send')->last();
        $this->assertNotSame($this->staleSession, $ok['session_id']);
        $this->assertSame(1, (int) $ok['grounded']);
        $this->assertStringContainsString('END-OF-READING', $ok['context']);
    }

    public function test_a_reading_still_being_read_cannot_open_a_room_yet(): void
    {
        $user = $this->member();
        $reading = $this->yearReading($user, status: Reading::STATUS_WORKING);

        // ฟอร์มเก่าค้างในแท็บ — ยังไม่มีคำพยากรณ์ให้คุยต่อ กลับไปรอที่หน้าผล
        $this->actingAs($user)->post(route('chat.from-reading', $reading), ['question' => 'x'])
            ->assertRedirect(route('tarot.show', $reading));

        $this->assertSame(0, ChatConversation::count());
        $this->assertCount(0, $this->sent('/server/chat/start'));
    }

    public function test_a_general_room_sends_no_context_and_keeps_selling_readings(): void
    {
        $user = $this->member();

        $this->actingAs($user)->postJson(route('chat.send'), ['message' => 'วันนี้เหนื่อยจังค่ะ'])->assertOk();
        $this->assertFalse(isset($this->sent('/server/chat/start')->sole()['context']));
        $send = $this->sent('/server/chat/send')->sole();
        $this->assertFalse(isset($send['context']));
        $this->assertFalse(isset($send['grounded']));

        $this->actingAs($user)->postJson(route('chat.send'), ['message' => 'ช่วยดูดวงความรักให้หน่อยค่ะ'])
            ->assertOk()->assertJsonPath('kind', 'offer');
    }

    public function test_the_local_fallback_also_answers_from_the_reading(): void
    {
        $user = $this->member();
        $reading = $this->yearReading($user);
        ChatConversation::create(['user_id' => $user->id, 'session_token' => 'room-tok', 'reading_id' => $reading->id]);
        Setting::put('ai_api_key', 'local-gemini-key', 'ai', true);
        Cache::flush();
        $this->upstreamDown = true;

        $this->actingAs($user)->withSession(['chat_token' => 'room-tok'])
            ->postJson(route('chat.send'), ['message' => 'เดือนมีนาหมายถึงอะไรคะ'])
            ->assertOk()
            ->assertJsonPath('reply', 'แม่หมอ (สำรอง) ตอบจากไพ่ชุดเดิมค่ะ');

        $gemini = Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->sole()[0];
        $prompt = $gemini['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString(self::MARCH_WARNING, $prompt);
        $this->assertStringContainsString('ห้ามเปิดไพ่ใบใหม่', $prompt);
        $this->assertStringContainsString('เดือนมีนาหมายถึงอะไรคะ', $prompt);
    }

    /* ───────────────────────── แอพ ───────────────────────── */

    public function test_the_app_can_open_a_room_on_its_own_tarot_reading(): void
    {
        $user = $this->member();
        $reading = $this->yearReading($user);
        Sanctum::actingAs($user);

        $res = $this->postJson(route('api.v1.chat.conversations.start'), ['reading_id' => $reading->id])
            ->assertCreated()
            ->assertJsonPath('data.conversation.reading_id', $reading->id);
        $convo = $res->json('data.conversation.id');
        $this->assertStringContainsString('ไพ่ทั้ง 12 ใบ', $res->json('data.conversation.messages.0.content'));
        $this->assertContains('ตอบคำถามเดิมให้ชัด', array_column($res->json('data.suggestions'), 'label'));
        $this->assertStringContainsString(self::MARCH_WARNING, $this->sent('/server/chat/start')->sole()['context']);

        // "ดูดวงความรัก…" ในห้องของไพ่ = ถามเรื่องไพ่ชุดนี้ → ไปถามแม่หมอ ไม่ใช่ใบเสนอแพ็กเกจ
        $this->postJson(route('api.v1.chat.conversations.send', $convo), ['message' => 'จากไพ่ชุดนี้ ดวงความรักของหนูเป็นยังไงคะ'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'reply')
            ->assertJsonPath('data.offers', []);
        $send = $this->sent('/server/chat/send')->sole();
        $this->assertSame(1, (int) $send['grounded']);
        $this->assertStringContainsString('END-OF-READING', $send['context']);

        // กดถามจากไพ่ชุดเดิมอีกครั้ง = เปิดห้องเดิมต่อ ไม่สร้างห้องซ้ำ
        $this->postJson(route('api.v1.chat.conversations.start'), ['reading_id' => $reading->id])
            ->assertOk()
            ->assertJsonPath('data.conversation.id', $convo)
            ->assertJsonPath('data.conversation.messages.2.content', 'เดือนมีนาไพ่เตือนเรื่องเงินค่ะลูก');
        $this->assertSame(1, ChatConversation::count());

        $this->getJson(route('api.v1.chat.conversations.show', $convo))->assertOk()->assertJsonPath('data.reading_id', $reading->id);
        $this->getJson(route('api.v1.chat.conversations'))->assertOk()->assertJsonPath('data.0.reading_id', $reading->id);

        // ห้อง Thaiprompt ที่แอพจำไว้หมดอายุ (cache 6 ชม.) → เปิดใหม่ บริบทสร้างใหม่จาก reading_id
        Cache::forget("juntra:chat:upstream_session:{$convo}");
        $this->postJson(route('api.v1.chat.conversations.send', $convo), ['message' => 'แล้วเดือนมีนาล่ะคะ'])->assertOk();
        $this->assertStringContainsString(self::MARCH_WARNING, $this->sent('/server/chat/start')->last()['context']);
    }

    public function test_the_app_cannot_open_a_room_on_a_reading_that_is_not_its_own_or_not_ready(): void
    {
        $owner = $this->member();
        $reading = $this->yearReading($owner);
        Sanctum::actingAs($this->member());

        $this->postJson(route('api.v1.chat.conversations.start'), ['reading_id' => $reading->id])
            ->assertNotFound()
            ->assertJsonPath('reason_code', 'reading_not_found');
        $this->assertSame(0, ChatConversation::count());
        $this->assertCount(0, $this->sent('/server/chat/start'), 'ไม่มีบริบทของคนอื่นหลุดไปที่ Thaiprompt');

        Sanctum::actingAs($owner);
        $deep = Reading::create(['user_id' => $owner->id, 'session_token' => 'deep', 'type' => 'deep', 'result' => 'ดวงเชิงลึก']);
        $this->postJson(route('api.v1.chat.conversations.start'), ['reading_id' => $deep->id])
            ->assertStatus(422)->assertJsonPath('reason_code', 'reading_not_tarot');

        $reading->update(['status' => Reading::STATUS_WORKING]);
        $this->postJson(route('api.v1.chat.conversations.start'), ['reading_id' => $reading->id])
            ->assertStatus(422)->assertJsonPath('reason_code', 'reading_not_ready');

        $this->postJson(route('api.v1.chat.conversations.start'), ['reading_id' => 'abc'])->assertStatus(422);
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_old_apps_without_reading_id_behave_exactly_as_before(): void
    {
        Sanctum::actingAs($this->member());

        $res = $this->postJson(route('api.v1.chat.conversations.start'))
            ->assertCreated()
            ->assertJsonPath('data.conversation.reading_id', null)
            ->assertJsonPath('data.conversation.messages.0.content', 'สวัสดีค่ะลูก')
            ->assertJsonPath('data.conversation.title', 'สนทนากับแม่หมอ');
        $this->assertFalse(isset($this->sent('/server/chat/start')->sole()['context']));

        $this->postJson(route('api.v1.chat.conversations.send', $res->json('data.conversation.id')), ['message' => 'ช่วยดูดวงความรักให้หน่อยค่ะ'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'offer');
        $this->assertCount(0, $this->sent('/server/chat/send'), 'ห้องทั่วไปยังยื่นแพ็กเกจโดยไม่ถาม AI เหมือนเดิม');
    }
}
