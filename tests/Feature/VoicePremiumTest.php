<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FortuneBot\FortuneAiService;
use App\Services\Tarot\TarotReadingFinisher;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use App\Support\ReadingQaClock;
use App\Support\ReadingTheater;
use App\Support\VoicePremium;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * ✨ (2026-11-05) พรีเมียม "เสียงผู้พยากรณ์" (+฿100 ตอนจ่ายค่าเปิดไพ่)
 *
 * ล็อกเรื่องเงิน: ไม่พร้อม = ไม่ขาย · ตัดเป็นสองรายการ · กดซ้ำไม่ตัดซ้ำ · ยอดไม่พอไม่ตัดอะไรเลย
 * · คำทำนายล้ม = คืนทั้งสอง · ไม่ได้เสียงเลย = คืนพรีเมียมครั้งเดียว
 */
class VoicePremiumTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    /** คำตอบของ /voice/health (null = ติดต่อไม่ได้) */
    private ?array $health = ['tts' => ['available' => true, 'chars_left' => 50000]];

    /** /voice/speak ได้ไฟล์ไหม */
    private bool $speakOk = true;

    /** URL ไฟล์เสียงที่ Thaiprompt ตอบกลับ (prod ตั้งแต่ 2026-11 = คลัง R2) */
    private string $clipUrl = '/storage/voice/clip-7.mp3';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Setting::put('pricing_chat_message_enabled', '0', 'pricing', false);
        Cache::flush();

        $mp3 = (string) file_get_contents(base_path('tests/fixtures/voice-3s.mp3'));
        Http::fake(function (Request $req) use ($mp3) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/voice/health') => $this->health === null
                    ? Http::response([], 503)
                    : Http::response(['data' => $this->health]),
                str_ends_with($url, '/voice/narration') => Http::response(['data' => ['chapters' => [
                    ['anchor' => 'card:1', 'kind' => 'card', 'tone' => 'good', 'energy' => 0.7, 'say' => 'ไพ่ใบแรกคือดวงอาทิตย์ หมายถึงความสำเร็จ'],
                    ['anchor' => 'verdict', 'say' => 'สรุปว่าเรื่องนี้ไปได้ดีจ้ะ'],
                ]]]),
                str_ends_with($url, '/voice/speak') => $this->speakOk
                    ? Http::response(['data' => ['status' => 'completed', 'clip_id' => 7, 'url' => $this->clipUrl]])
                    : Http::response([], 503),
                str_contains($url, '/storage/voice/'), str_contains($url, '/fortune-voice/') => Http::response($mp3, 200, ['Content-Type' => 'audio/mpeg']),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => ['session_id' => 'sess-1', 'reply' => 'ไพ่บอกว่าไปต่อได้จ้ะ', 'kind' => 'reply']]),
                default => Http::response([], 503),
            };
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/reading-voice'));
        parent::tearDown();
    }

    private function member(float $credit = 500): User
    {
        $u = User::factory()->create(['thaiprompt_token' => null, 'facebook_user_id' => null, 'line_user_id' => null]);
        if ($credit > 0) {
            app(WalletService::class)->credit($u, $credit, 'seed');
        }

        return $u;
    }

    private function balance(User $u): float
    {
        return (float) app(WalletService::class)->balance($u->fresh());
    }

    private function card(): TarotCard
    {
        return TarotCard::create([
            'slug' => 'the-sun-' . Str::random(4), 'name_en' => 'The Sun', 'name_th' => 'ดวงอาทิตย์', 'arcana' => 'major', 'suit' => 'major',
            'number' => 19, 'keywords_th' => 'สำเร็จ', 'upright_meaning_th' => 'ความสำเร็จ', 'reversed_meaning_th' => 'ล่าช้า', 'active' => true,
        ]);
    }

    private function singlePrice(): float
    {
        return Pricing::for('tarot_single');
    }

    /** เปิดไพ่ 1 ใบผ่านหน้าเว็บจริง */
    private function cast(User $u, bool $premium, string $idem)
    {
        return $this->actingAs($u)->post(route('tarot.cast'), [
            'spread' => 'single', 'premium' => $premium ? '1' : '0', '_idem' => $idem,
        ]);
    }

    /** คำทำนายที่อ่านเสร็จแล้วพร้อมพรีเมียมที่จ่ายแล้ว */
    private function premiumReading(User $u, string $type = 'tarot_single'): Reading
    {
        $tx = app(WalletService::class)->debit($u, 100, 'พรีเมียมเสียงผู้พยากรณ์: ทดสอบ', [
            'reference_type' => 'voice_premium', 'idempotency_key' => VoicePremium::idempotencyKey($u->id, (string) Str::uuid()),
        ]);
        $reading = Reading::create([
            'user_id' => $u->id, 'session_token' => (string) Str::uuid(), 'type' => $type, 'question' => 'งานจะไปต่อไหม',
            'payload' => ['positions' => ['คำตอบของไพ่'], 'cost' => 9, 'room' => 'chantra',
                'premium' => ['status' => 'paid', 'price' => 100, 'tx_id' => $tx->id, 'at' => now()->toIso8601String()]],
            'result' => 'ไพ่ชุดนี้บอกว่างานไปได้ดี',
        ]);
        $reading->tarotCards()->create(['tarot_card_id' => $this->card()->id, 'position' => 1, 'position_label' => 'คำตอบของไพ่', 'reversed' => false]);

        return $reading;
    }

    private function premiumRefunds(User $u): int
    {
        $ids = WalletTransaction::where('user_id', $u->id)->where('reference_type', 'voice_premium')->pluck('id');

        return WalletTransaction::where('type', 'refund')->where('reference_type', 'wallet_transaction')->whereIn('reference_id', $ids)->count();
    }

    /* ───────────── ขาย / ไม่ขาย ───────────── */

    public function test_premium_is_offered_only_when_voice_is_ready_with_enough_quota(): void
    {
        $this->card();
        $u = $this->member();

        $this->actingAs($u)->post('/tarot/begin', ['spread' => 'single']);
        $this->get('/tarot/pick')->assertOk()->assertSee('data-ct-premium', false)
            ->assertSee('ฟังเสียง', false)->assertSee('20 นาที', false);

        foreach ([null, ['tts' => ['available' => false, 'chars_left' => 50000]], ['tts' => ['available' => true, 'chars_left' => 100]]] as $h) {
            $this->health = $h;
            Cache::flush();
            $this->actingAs($u)->post('/tarot/begin', ['spread' => 'single']);
            $this->get('/tarot/pick')->assertOk()->assertDontSee('data-ct-premium', false);
            $this->assertFalse(VoicePremium::offerable());
        }
    }

    public function test_premium_is_not_sold_when_voice_went_down_before_the_cast(): void
    {
        Bus::fake();
        $this->card();
        $u = $this->member();
        $this->health = null;

        $this->cast($u, true, (string) Str::uuid())->assertRedirect();

        $this->assertSame(500 - $this->singlePrice(), $this->balance($u), 'ตัดแค่ค่าไพ่');
        $this->assertNull(data_get(Reading::sole()->payload, 'premium'));
    }

    /* ───────────── ตัดเงิน ───────────── */

    public function test_premium_cast_is_two_debits_and_the_same_form_never_charges_twice(): void
    {
        Bus::fake();
        $this->card();
        $u = $this->member();
        $idem = (string) Str::uuid();

        $this->cast($u, true, $idem)->assertRedirect();
        $reading = Reading::sole();

        $debits = WalletTransaction::where('user_id', $u->id)->where('type', 'debit')->get();
        $this->assertCount(2, $debits);
        $premiumTx = $debits->firstWhere('reference_type', 'voice_premium');
        $this->assertSame(-100.0, (float) $premiumTx->amount);
        $this->assertSame($reading->id, (int) $premiumTx->reference_id);
        $this->assertSame('paid', data_get($reading->payload, 'premium.status'));
        $this->assertSame($premiumTx->id, data_get($reading->payload, 'premium.tx_id'));
        $this->assertSame(500 - $this->singlePrice() - 100, $this->balance($u));

        // กดซ้ำทันที → ด่านกันกดซ้ำ
        $this->cast($u, true, $idem)->assertRedirect();
        // ฟอร์มเดิมหลังล็อกหมดอายุ → พรีเมียมใบเดิมไม่ถูกผูกกับใบใหม่ และไม่ตัด +฿100 ซ้ำ
        $this->travel(5)->minutes();
        $this->cast($u, true, $idem)->assertRedirect();

        $this->assertSame(1, WalletTransaction::where('user_id', $u->id)->where('reference_type', 'voice_premium')->count());
        $this->assertSame($reading->id, (int) $premiumTx->fresh()->reference_id, 'รายการพรีเมียมยังชี้ใบเดิม');
        foreach (Reading::whereKeyNot($reading->id)->get() as $other) {
            $this->assertNull(data_get($other->payload, 'premium'), 'ใบที่ส่งฟอร์มซ้ำไม่ได้พรีเมียมฟรี');
        }
    }

    public function test_not_enough_for_premium_charges_nothing(): void
    {
        Bus::fake();
        $this->card();
        $u = $this->member($this->singlePrice() + 50);

        $this->cast($u, true, (string) Str::uuid())->assertRedirect(route('wallet.index'));

        $this->assertSame(0, WalletTransaction::where('user_id', $u->id)->where('type', 'debit')->count());
        $this->assertSame(0, Reading::count());
        $this->assertSame($this->singlePrice() + 50, $this->balance($u));
    }

    public function test_a_failed_reading_refunds_both_the_cards_and_the_premium(): void
    {
        Bus::fake();
        $this->card();
        $u = $this->member();
        $this->cast($u, true, (string) Str::uuid())->assertRedirect();
        $reading = Reading::sole();

        $ai = Mockery::mock(FortuneAiService::class);
        $ai->shouldReceive('interpretTarot')->once()->andThrow(new \RuntimeException('boom'));
        $this->app->instance(FortuneAiService::class, $ai);
        app(TarotReadingFinisher::class)->interpret($reading->id);
        $this->travel(10)->minutes();
        app(TarotReadingFinisher::class)->sweepStuck();

        $this->assertSame(500.0, $this->balance($u), 'คืนครบทั้งค่าไพ่และพรีเมียม ครั้งเดียว');
        $this->assertSame(1, $this->premiumRefunds($u));
        $this->assertSame('refunded', data_get($reading->fresh()->payload, 'premium.status'));
    }

    /* ───────────── เสียงเล่าคำทำนาย ───────────── */

    public function test_the_builder_writes_a_ready_voice_the_theater_plays(): void
    {
        $u = $this->member();
        $reading = $this->premiumReading($u);

        $last = null;
        for ($i = 0; $i < 8; $i++) {
            $last = $this->actingAs($u)->postJson(route('tarot.voice.next', $reading))->assertOk()->json();
            if ($last['status'] !== 'building') {
                break;
            }
        }
        $this->assertSame('ready', $last['status']);
        $this->assertFalse($last['refunded']);

        $voice = data_get($reading->fresh()->payload, 'voice');
        $this->assertSame('/tarot/result/' . $reading->id . '/voice.mp3', $voice['url']);
        $this->assertCount(2, $voice['chapters']);
        $prevEnd = 0.0;
        foreach ($voice['chapters'] as $c) {
            $this->assertGreaterThanOrEqual($prevEnd, $c['start']);
            $this->assertGreaterThan($c['start'], $c['end']);
            $prevEnd = $c['end'];
        }
        $this->assertEqualsWithDelta(6.06, $voice['duration'], 0.1);
        $this->assertNull(data_get($reading->fresh()->payload, 'voice_build'));

        $theater = ReadingTheater::build($reading->fresh()->load('tarotCards.card'));
        $this->assertNotNull($theater['voice']);
        $this->assertSame($voice['url'], $theater['voice']['url']);
        $this->assertSame(['card', 'summary'], array_column($theater['voice']['chapters'], 'kind'));

        // ไฟล์รวมเปิดได้เฉพาะเจ้าของ
        $this->actingAs($u)->get(route('tarot.voice.file', $reading))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->actingAs($this->member())->get(route('tarot.voice.file', $reading))->assertForbidden();
        $this->actingAs($this->member())->postJson(route('tarot.voice.next', $reading))->assertForbidden();
        $this->assertSame(500.0 - 100, $this->balance($u), 'ได้เสียงแล้ว ไม่คืนเงิน');
    }

    /** สร้างเสียงจนเสร็จ แล้วคืนสถานะล่าสุด */
    private function buildVoice(User $u, Reading $reading): array
    {
        $last = [];
        for ($i = 0; $i < 12; $i++) {
            $last = $this->actingAs($u)->postJson(route('tarot.voice.next', $reading))->assertOk()->json();
            if ($last['status'] !== 'building') {
                break;
            }
        }

        return $last;
    }

    public function test_voice_files_on_thaiprompts_r2_store_are_accepted(): void
    {
        // prod 2026-11-07: /voice/clips ตอบ URL ของ R2 — เดิมรับแค่โดเมน Thaiprompt ทุกคลิปจึงถูกทิ้ง แล้วคืนเงินทุกรายการ
        config(['reading_voice.file_hosts' => ['pub-test.r2.dev']]);
        $this->clipUrl = 'https://pub-test.r2.dev/fortune-voice/characters/maemor/abc.mp3';
        $u = $this->member();
        $reading = $this->premiumReading($u);

        $last = $this->buildVoice($u, $reading);

        $this->assertSame('ready', $last['status']);
        $this->assertFalse($last['refunded']);
        $this->assertSame(500.0 - 100, $this->balance($u));
    }

    public function test_voice_files_from_an_unlisted_host_or_plain_http_are_never_downloaded(): void
    {
        config(['reading_voice.file_hosts' => ['pub-test.r2.dev']]);
        $client = app(\App\Services\Thaiprompt\JuntraServerClient::class);

        $this->assertNull($client->voiceFileUrl('https://evil.example/fortune-voice/a.mp3'));
        $this->assertNull($client->voiceFileUrl('http://pub-test.r2.dev/fortune-voice/a.mp3'));
        $this->assertNull($client->voiceFileUrl('https://pub-test.r2.dev.evil.example/a.mp3'));
        $this->assertSame('https://pub-test.r2.dev/a.mp3', $client->voiceFileUrl('https://pub-test.r2.dev/a.mp3'));
        $this->assertSame(self::TP . '/storage/voice/a.mp3', $client->voiceFileUrl('/storage/voice/a.mp3'));
    }

    public function test_no_audio_at_all_refunds_the_premium_exactly_once(): void
    {
        $u = $this->member();
        $reading = $this->premiumReading($u);
        $this->speakOk = false;

        for ($i = 0; $i < 12; $i++) {
            $last = $this->actingAs($u)->postJson(route('tarot.voice.next', $reading))->assertOk()->json();
        }

        $this->assertSame('none', $last['status']);
        $this->assertTrue($last['refunded']);
        $this->assertSame(500.0, $this->balance($u));
        $this->assertSame(1, $this->premiumRefunds($u));
        $this->assertFalse(VoicePremium::refund($reading->fresh(), 'อีกรอบ'), 'คืนซ้ำไม่ได้');
        $this->assertNull(ReadingTheater::build($reading->fresh()->load('tarotCards.card'))['voice']);
        $this->assertFalse(is_dir(storage_path('app/reading-voice/' . $reading->id)));
    }

    /* ───────────── ห้องถามต่อ ───────────── */

    private function enter(User $u, Reading $reading): ChatConversation
    {
        $this->actingAs($u)->post(route('chat.from-reading', $reading))->assertRedirect();

        return ChatConversation::where('reading_id', $reading->id)->firstOrFail();
    }

    public function test_premium_gets_a_twenty_minute_follow_up(): void
    {
        $u = $this->member();
        $reading = $this->premiumReading($u, 'tarot_three');
        $plain = Reading::create(['user_id' => $u->id, 'session_token' => 'plain', 'type' => 'tarot_three', 'payload' => ['cost' => 19], 'result' => 'ok']);

        $this->assertSame(20, ReadingQaClock::minutesFor($reading));
        $this->assertSame(7, ReadingQaClock::minutesFor($plain));

        $room = $this->enter($u, $reading);
        $this->actingAs($u)->get('/chat?room=' . $room->persona)->assertOk()
            ->assertViewHas('qa', fn ($q) => $q['minutes'] === 20 && $q['premium'] === true);

        VoicePremium::refund($reading, 'ทดสอบ');
        $this->assertSame(7, ReadingQaClock::minutesFor($reading->fresh()), 'คืนพรีเมียมแล้วกลับเวลาปกติ');
    }

    public function test_chat_replies_are_spoken_only_in_an_open_premium_window_up_to_the_cap(): void
    {
        config(['reading_voice.chat_char_cap' => 30]);   // คำตอบ 1 ข้อ ~19 ตัว → พูดได้ข้อเดียว
        $u = $this->member();
        $reading = $this->premiumReading($u, 'tarot_three');
        $room = $this->enter($u, $reading);

        $first = $this->actingAs($u)->postJson('/chat/send', ['message' => 'ข้อแรกค่ะ', 'room' => $room->persona])->assertOk();
        $this->assertSame(self::TP . '/storage/voice/clip-7.mp3', $first->json('voice_url'));
        $this->assertSame(mb_strlen('ไพ่บอกว่าไปต่อได้จ้ะ'), data_get($reading->fresh()->payload, 'premium.chat_chars'));

        $second = $this->actingAs($u)->postJson('/chat/send', ['message' => 'ข้อสองค่ะ', 'room' => $room->persona])->assertOk();
        $this->assertNull($second->json('voice_url'), 'เกินเพดานตัวอักษร = ตอบเป็นตัวอักษรอย่างเดียว');

        // หมดเวลา 20 นาที → ส่งไม่ได้แล้ว (ไม่มีเสียงหลุดออกไป)
        $this->travel(21)->minutes();
        $this->actingAs($u)->postJson('/chat/send', ['message' => 'ข้อสามค่ะ', 'room' => $room->persona])->assertStatus(403);

        $speaks = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/voice/speak'))->count();
        $this->assertSame(1, $speaks);
    }

    /** 🎙️ (2026-11-07, owner) "ตั้งเพดาน เสียงพากย์ พรีเมี่ยมไว้ ไม่อั้น" */
    public function test_premium_voice_is_unlimited_by_default(): void
    {
        $u = $this->member();
        $reading = $this->premiumReading($u, 'tarot_three');

        // บทเล่า: ขอ Thaiprompt แบบไม่อั้น (budget_chars = 0)
        $this->assertSame('ready', $this->buildVoice($u, $reading)['status']);
        $narration = collect(Http::recorded())->first(fn ($p) => str_ends_with($p[0]->url(), '/voice/narration'));
        $this->assertSame(0, $narration[0]->data()['budget_chars']);

        // ห้องถามต่อ: พูดทุกคำตอบ ไม่มีเพดานรวม
        $room = $this->enter($u, $reading);
        foreach (['ข้อแรกค่ะ', 'ข้อสองค่ะ', 'ข้อสามค่ะ'] as $q) {
            $this->assertNotNull($this->actingAs($u)->postJson('/chat/send', ['message' => $q, 'room' => $room->persona])->assertOk()->json('voice_url'), $q);
        }
        $this->assertSame(3 * mb_strlen('ไพ่บอกว่าไปต่อได้จ้ะ'), data_get($reading->fresh()->payload, 'premium.chat_chars'));
    }

    public function test_a_plain_reading_never_gets_voice_in_chat(): void
    {
        $u = $this->member();
        $plain = Reading::create(['user_id' => $u->id, 'session_token' => 'plain', 'type' => 'tarot_three', 'question' => 'q', 'payload' => ['cost' => 19], 'result' => 'ไพ่บอกว่าไปได้']);
        $room = $this->enter($u, $plain);

        $res = $this->actingAs($u)->postJson('/chat/send', ['message' => 'ข้อแรกค่ะ', 'room' => $room->persona])->assertOk();
        $this->assertNull($res->json('voice_url'));
        $this->assertSame(0, collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/voice/speak'))->count());
    }
}
