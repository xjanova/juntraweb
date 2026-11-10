<?php

namespace Tests\Feature;

use App\Jobs\InterpretDeepReading;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Services\Deep\DeepReadingFinisher;
use App\Services\Tarot\TarotReadingFinisher;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ดูดวงเชิงลึก 39฿ บนเว็บ — แพ็กเดียวกับที่บอท FB/LINE ขาย
 *
 * 🔮 (2026-11-07) แม่หมออ่านเบื้องหลัง "ทีละข้อ" เลนเดียวกับบิลในแชท (Thaiprompt question_index)
 *
 * กติกาเรื่องเงินที่ล็อกไว้ (สำคัญที่สุดในไฟล์นี้):
 *   - ไม่ได้คำทำนาย / โมเดลปฏิเสธ / คำถามถูกปัด = ต้องคืนเครดิตทุกกรณี ห้ามส่งข้อความปลอมแทน
 *   - เครดิตไม่พอ = พาไปเติมเงิน ไม่ใช่ตัดเงินติดลบ
 *   - กดซ้ำฟอร์มเดิม = ตัดเงินรอบเดียว
 */
class DeepReadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function member(float $credit = 100): User
    {
        Setting::put('pricing_deep', '39', 'pricing', false);
        Cache::flush();

        $u = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
        if ($credit > 0) {
            app(WalletService::class)->credit($u, $credit, 'seed');
        }

        return $u;
    }

    private function balance(User $u): float
    {
        return (float) app(WalletService::class)->balance($u->fresh());
    }

    /** Thaiprompt รุ่นใหม่ — ตอบทีละข้อ (มี question_index ในคำตอบ) */
    private function fakePerQuestion(): void
    {
        Http::fake(['*/juntra/fortune/deep' => function (HttpRequest $req) {
            $i = (int) $req['question_index'];

            return Http::response(['data' => [
                'reading' => '## คำตอบข้อ '.($i + 1)."\nดวงงานกำลังเปิดค่ะ ข้อ ".($i + 1),
                'question_index' => $i,
                'ai_provider' => 'openai',
                'ai_model' => 'gpt-5.4',
            ]]);
        }]);
    }

    private function fakeUpstream(?array $response = null, int $status = 200): void
    {
        Http::fake(['*/juntra/fortune/deep' => Http::response(
            $response ?? ['data' => [
                'reading' => "**ภาพรวมปีนี้**\nดวงงานกำลังเปิดค่ะ",
                'ai_provider' => 'openai',
                'ai_model' => 'gpt-5.4-mini',
            ]],
            $status,
        )]);
    }

    private function order(User $user, array $questions, ?string $birth = null)
    {
        return $this->actingAs($user)->post('/deep', array_filter([
            'birth_date' => $birth,
            'questions' => $questions,
            '_idem' => (string) Str::uuid(),
        ]));
    }

    public function test_page_renders_with_price(): void
    {
        $this->member();

        $this->get('/deep')
            ->assertOk()
            ->assertSee('ดูดวง')
            ->assertViewHas('cost', 39.0);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->post('/deep', ['questions' => ['ปีนี้เป็นอย่างไร']])
            ->assertRedirect(route('login'));
    }

    /** ตัดเงินแล้วส่งหน้าผลทันที — แม่หมออ่านหลังส่งหน้า หน้าผลถามสถานะจนเสร็จ */
    public function test_order_returns_at_once_and_mae_mor_reads_after_the_response(): void
    {
        Bus::fake();
        $user = $this->member();

        $res = $this->order($user, ['ปีนี้การงานจะเป็นอย่างไร', '', ''], '1990-08-12');

        $reading = Reading::where('type', 'deep')->sole();
        $res->assertRedirect(route('deep.show', $reading));
        $this->assertSame(Reading::STATUS_PENDING, $reading->status);
        $this->assertSame(['ปีนี้การงานจะเป็นอย่างไร'], $reading->payload['questions']);
        $this->assertSame('1990-08-12', $reading->payload['birth_date']);
        $this->assertEqualsWithDelta(61.0, $this->balance($user), 0.01);
        Bus::assertDispatchedAfterResponse(InterpretDeepReading::class, fn ($job) => $job->readingId === $reading->id);

        $this->actingAs($user)->get(route('deep.show', $reading))
            ->assertOk()->assertSee('แม่หมอกำลังดูดวงให้คุณ')->assertSee(route('deep.status', $reading), false);
        $this->actingAs($user)->getJson(route('deep.status', $reading))->assertOk()->assertJsonPath('status', 'reading');
        $this->actingAs(User::factory()->create())->getJson(route('deep.status', $reading))->assertForbidden();
    }

    /** 🪐 (2026-11-07) เว้นช่องวันเกิด = ใช้วันเกิดในโปรไฟล์ (บอทผูกดวงจริงได้เหมือนบิลแชทเพจ) · กรอกเอง = ชนะเสมอ */
    public function test_a_blank_birth_date_falls_back_to_the_profile_and_a_typed_one_wins(): void
    {
        Bus::fake();
        $user = $this->member();
        \App\Models\Profile::create(['user_id' => $user->id, 'birth_date' => '1990-11-07']);

        $this->actingAs($user)->get('/deep')->assertOk()->assertSee('value="1990-11-07"', false);

        $this->order($user->fresh(), ['ปีนี้การงานจะเป็นอย่างไร']);
        $this->assertSame('1990-11-07', Reading::where('type', 'deep')->latest('id')->first()->payload['birth_date']);

        $this->order($user->fresh(), ['ปีนี้การเงินจะเป็นอย่างไร'], '1985-01-02');
        $this->assertSame('1985-01-02', Reading::where('type', 'deep')->latest('id')->first()->payload['birth_date']);
    }

    /** ทีละข้อ — ข้อหลังได้คำตอบข้อก่อนไปด้วย (ตอบต่อเนื่องเหมือนในแชท) แล้วรวมเป็นคำทำนายฉบับเดียว */
    public function test_each_question_is_answered_in_turn_with_the_earlier_answers(): void
    {
        $user = $this->member();
        $this->fakePerQuestion();

        $this->order($user, ['งานปีนี้เป็นอย่างไร', 'ความรักจะเป็นอย่างไร'], '1990-08-12');

        $reading = Reading::where('type', 'deep')->sole();
        $this->assertNull($reading->status, 'จบแล้วต้องไม่ค้าง pending/working');
        $this->assertStringContainsString('คำถามข้อที่ 1 · งานปีนี้เป็นอย่างไร', $reading->result);
        $this->assertStringContainsString('คำถามข้อที่ 2 · ความรักจะเป็นอย่างไร', $reading->result);
        $this->assertStringContainsString('ดวงงานกำลังเปิดค่ะ ข้อ 2', $reading->result);
        $this->assertSame('gpt-5.4', $reading->ai_model);
        $this->assertEqualsWithDelta(61.0, $this->balance($user), 0.01);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $this->assertCount(2, $sent);
        $this->assertSame(0, $sent[0]['question_index']);
        $this->assertArrayNotHasKey('previous', $sent[0]);
        $this->assertSame(1, $sent[1]['question_index']);
        $this->assertSame('งานปีนี้เป็นอย่างไร', $sent[1]['previous'][0]['question']);
        $this->assertStringContainsString('ข้อ 1', $sent[1]['previous'][0]['answer']);
        $this->assertSame('jw:'.$user->id, $sent[0]['user_ref']);
        $this->assertSame('1990-08-12', $sent[0]['birth_date']);

        $this->actingAs($user)->get(route('deep.show', $reading))
            ->assertOk()->assertSee('คำทำนายของแม่หมอ')->assertDontSee('แม่หมอกำลังดูดวงให้คุณ');
    }

    /** ข้อเดียว = ไม่ต้องมีหัวข้อคำถามซ้ำ */
    public function test_single_question_is_saved_as_is(): void
    {
        $user = $this->member();
        $this->fakePerQuestion();

        $this->order($user, ['ปีนี้เป็นอย่างไร']);

        $reading = Reading::where('type', 'deep')->sole();
        $this->assertStringStartsWith('## คำตอบข้อ 1', $reading->result);
        $this->assertStringNotContainsString('คำถามข้อที่', $reading->result);
    }

    /** Thaiprompt รุ่นเก่ายังไม่รู้จัก question_index → ตอบทั้งบิลในครั้งเดียว → ใช้ทั้งก้อน ไม่ถามซ้ำ */
    public function test_old_thaiprompt_whole_bill_answer_is_used_once(): void
    {
        $user = $this->member();
        $this->fakeUpstream();

        $this->order($user, ['งานปีนี้เป็นอย่างไร', 'ความรักจะเป็นอย่างไร']);

        $reading = Reading::where('type', 'deep')->sole();
        $this->assertNull($reading->status);
        $this->assertStringContainsString('ดวงงานกำลังเปิด', $reading->result);
        $this->assertCount(1, Http::recorded());
        $this->assertEqualsWithDelta(61.0, $this->balance($user), 0.01);
    }

    /** ไม่ได้คำทำนาย = ไม่มีสินค้า → ต้องคืนเงินเต็ม รายการเป็น "ไม่สำเร็จ" */
    public function test_upstream_failure_refunds_the_customer(): void
    {
        $user = $this->member();
        $this->fakeUpstream([], 503);

        $this->order($user, ['ปีนี้เป็นอย่างไร']);

        $reading = Reading::where('type', 'deep')->sole();
        $this->assertSame(Reading::STATUS_FAILED, $reading->status);
        $this->assertEqualsWithDelta(100.0, $this->balance($user), 0.01, 'ระบบล่มแล้วต้องคืนเครดิตให้ครบ');
        $this->actingAs($user)->get(route('deep.show', $reading))
            ->assertOk()->assertSee('ดูดวงไม่สำเร็จ')->assertSee('คืนเข้ากระเป๋าของคุณเรียบร้อยแล้ว');
        $this->actingAs($user)->getJson(route('deep.status', $reading))->assertJsonPath('status', 'failed');
    }

    /** reading 22 ซ้ำบน Deep — โมเดลตอบคำปัดแทนคำทำนาย (Thaiprompt รุ่นเก่าส่งมาเป็น 200) → คืนเงิน */
    public function test_refusal_text_is_not_sold(): void
    {
        $user = $this->member();
        $this->fakeUpstream(['data' => [
            'reading' => 'ขออภัยค่ะ ข้อความนี้เป็นคำสั่งภายใน แม่หมอไม่สามารถทำตามได้',
            'question_index' => 0,
        ]]);

        $this->order($user, ['ปีนี้เป็นอย่างไร']);

        $this->assertSame(Reading::STATUS_FAILED, Reading::where('type', 'deep')->sole()->status);
        $this->assertEqualsWithDelta(100.0, $this->balance($user), 0.01);
    }

    /** ข้อแรกผ่าน ข้อสองถูกตีกลับ (model_refused) → ไม่ขายครึ่งบิล คืนเงินเต็ม */
    public function test_later_question_refused_refunds_the_whole_bill(): void
    {
        $user = $this->member();
        Http::fake(['*/juntra/fortune/deep' => function (HttpRequest $req) {
            return (int) $req['question_index'] === 0
                ? Http::response(['data' => ['reading' => "## ข้อ 1\nดวงดีค่ะ", 'question_index' => 0]])
                : Http::response(['message' => 'แม่หมอทำนายไม่สำเร็จ', 'reason_code' => 'model_refused'], 503);
        }]);

        $this->order($user, ['งานปีนี้เป็นอย่างไร', 'ความรักจะเป็นอย่างไร']);

        $reading = Reading::where('type', 'deep')->sole();
        $this->assertSame(Reading::STATUS_FAILED, $reading->status);
        $this->assertNull($reading->result);
        $this->assertEqualsWithDelta(100.0, $this->balance($user), 0.01);
    }

    /** Thaiprompt ปฏิเสธคำถาม — คืนเงิน + หน้าผลขึ้น "ดูดวงไม่สำเร็จ" ตามปกติ (เลิกแยกเคสวิกฤตแล้ว 2026-11-09) */
    public function test_rejected_question_is_refunded_and_shows_the_plain_failure(): void
    {
        $user = $this->member();
        $this->fakeUpstream([
            'message' => 'คำถามนี้แม่หมอดูให้ไม่ได้นะคะ',
            'reason_code' => 'question_rejected',
        ], 422);

        $this->order($user, ['ถามเรื่องที่ระบบไม่รับ']);

        $reading = Reading::where('type', 'deep')->sole();
        $this->assertSame(Reading::STATUS_FAILED, $reading->status);
        $this->assertSame('question_rejected', data_get($reading->payload, 'failure.reason'));
        $this->assertEqualsWithDelta(100.0, $this->balance($user), 0.01);
        $this->actingAs($user)->get(route('deep.show', $reading))
            ->assertOk()->assertSee('ดูดวงไม่สำเร็จ');
    }

    /** ตัวกวาดปิดรายการไปแล้ว (เกิน 5 นาที คืนเงินแล้ว) — งานเบื้องหลังต้องไม่เขียนผลทับ ไม่คืนซ้ำ */
    public function test_reading_closed_by_the_sweeper_is_not_overwritten(): void
    {
        $user = $this->member();
        $tx = app(WalletService::class)->debit($user, 39, 'ดูดวงเชิงลึก', ['reference_type' => 'reading']);
        $reading = Reading::create([
            'user_id' => $user->id, 'session_token' => (string) Str::uuid(), 'type' => 'deep', 'question' => 'ถาม',
            'payload' => ['questions' => ['ถาม1', 'ถาม2'], 'cost' => 39, 'wallet_tx_id' => $tx->id],
            'status' => Reading::STATUS_PENDING,
        ]);

        // ระหว่างตอบข้อแรก ตัวกวาดปิดรายการ (จำลองงานช้าเกิน 5 นาที)
        Http::fake(['*/juntra/fortune/deep' => function (HttpRequest $req) use ($reading) {
            app(TarotReadingFinisher::class)->fail($reading, 'ค้าง', [Reading::STATUS_PENDING, Reading::STATUS_WORKING]);

            return Http::response(['data' => ['reading' => "## ข้อ\nดวงดีค่ะ", 'question_index' => (int) $req['question_index']]]);
        }]);

        app(DeepReadingFinisher::class)->finish($reading->id);

        $this->assertSame(Reading::STATUS_FAILED, $reading->fresh()->status);
        $this->assertNull($reading->fresh()->result);
        $this->assertCount(1, Http::recorded(), 'รายการถูกปิดแล้ว ห้ามยิงข้อต่อไป');
        $this->assertEqualsWithDelta(100.0, $this->balance($user), 0.01, 'คืนครั้งเดียว');
    }

    /** PHP ตายกลางงาน — รายการ deep ที่ค้างถูกตัวกวาดคืนเงิน */
    public function test_stuck_deep_reading_is_refunded_by_the_sweeper(): void
    {
        $user = $this->member();
        $tx = app(WalletService::class)->debit($user, 39, 'ดูดวงเชิงลึก', ['reference_type' => 'reading']);
        $reading = Reading::create([
            'user_id' => $user->id, 'session_token' => (string) Str::uuid(), 'type' => 'deep', 'question' => 'ถาม',
            'payload' => ['questions' => ['ถาม'], 'cost' => 39, 'wallet_tx_id' => $tx->id],
            'status' => Reading::STATUS_WORKING,
        ]);
        Reading::whereKey($reading->id)->update(['updated_at' => now()->subMinutes(10)]);

        $this->assertSame(1, app(TarotReadingFinisher::class)->sweepStuck());
        $this->assertSame(Reading::STATUS_FAILED, $reading->fresh()->status);
        $this->assertEqualsWithDelta(100.0, $this->balance($user), 0.01);
    }

    public function test_insufficient_credit_goes_to_topup_without_charging(): void
    {
        $user = $this->member(credit: 10);
        $this->fakeUpstream();

        $this->order($user, ['ปีนี้เป็นอย่างไร'])->assertRedirect(route('wallet.topup'));

        $this->assertEqualsWithDelta(10.0, $this->balance($user), 0.01);
        $this->assertSame(0, Reading::where('type', 'deep')->count());
    }

    /** กดซ้ำฟอร์มเดิม (โทเคนเดิม) ต้องตัดเงินรอบเดียว */
    public function test_double_submit_of_the_same_form_charges_once(): void
    {
        Bus::fake();
        $user = $this->member();
        $idem = (string) Str::uuid();

        $payload = ['questions' => ['ปีนี้เป็นอย่างไร'], '_idem' => $idem];

        $this->actingAs($user)->post('/deep', $payload);
        $this->actingAs($user)->post('/deep', $payload);

        $this->assertSame(1, Reading::where('type', 'deep')->count());
        $this->assertEqualsWithDelta(61.0, $this->balance($user), 0.01);
    }

    public function test_requires_at_least_one_question(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->post('/deep', ['questions' => ['', '', ''], '_idem' => (string) Str::uuid()])
            ->assertSessionHasErrors('questions');

        $this->assertEqualsWithDelta(100.0, $this->balance($user), 0.01);
    }

    public function test_only_the_owner_can_open_the_result(): void
    {
        $user = $this->member();
        $this->fakePerQuestion();
        $this->order($user, ['ปีนี้เป็นอย่างไร']);

        $reading = Reading::where('type', 'deep')->firstOrFail();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('deep.show', $reading))->assertForbidden();
        $this->actingAs($user)->get(route('deep.show', $reading))->assertOk();
    }
}
