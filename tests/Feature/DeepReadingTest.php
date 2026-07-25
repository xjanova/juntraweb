<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ดูดวงเชิงลึก 39฿ บนเว็บ — แพ็กเดียวกับที่บอท FB/LINE ขาย
 *
 * กติกาเรื่องเงินที่ล็อกไว้ (สำคัญที่สุดในไฟล์นี้):
 *   - ไม่ได้คำทำนาย = ต้องคืนเครดิตทุกกรณี ห้ามส่งข้อความปลอมแทน
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

    private function fakeUpstream(array $response = null, int $status = 200): void
    {
        Http::fake(['*/juntra/fortune/deep' => Http::response(
            $response ?? ['data' => [
                'reading'     => "**ภาพรวมปีนี้**\nดวงงานกำลังเปิดค่ะ",
                'ai_provider' => 'openai',
                'ai_model'    => 'gpt-5.4-mini',
            ]],
            $status,
        )]);
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

    public function test_successful_reading_charges_once_and_saves_the_result(): void
    {
        $user = $this->member();
        $this->fakeUpstream();

        $res = $this->actingAs($user)->post('/deep', [
            'birth_date' => '1990-08-12',
            'questions'  => ['ปีนี้การงานจะเป็นอย่างไร', '', ''],
            '_idem'      => (string) Str::uuid(),
        ]);

        $reading = Reading::where('type', 'deep')->firstOrFail();
        $res->assertRedirect(route('deep.show', $reading));

        $this->assertStringContainsString('ดวงงานกำลังเปิด', $reading->result);
        $this->assertSame(['ปีนี้การงานจะเป็นอย่างไร'], $reading->payload['questions']);
        $this->assertSame('1990-08-12', $reading->payload['birth_date']);
        $this->assertEqualsWithDelta(61.0, app(WalletService::class)->balance($user), 0.01);
    }

    /** ไม่ได้คำทำนาย = ไม่มีสินค้า → ต้องคืนเงินเต็ม ห้ามเก็บ Reading ปลอม */
    public function test_upstream_failure_refunds_the_customer(): void
    {
        $user = $this->member();
        $this->fakeUpstream([], 503);

        $this->actingAs($user)->post('/deep', [
            'questions' => ['ปีนี้เป็นอย่างไร'],
            '_idem'     => (string) Str::uuid(),
        ])->assertRedirect(route('deep.index'));

        $this->assertSame(0, Reading::where('type', 'deep')->count());
        $this->assertEqualsWithDelta(100.0, app(WalletService::class)->balance($user), 0.01,
            'ระบบล่มแล้วต้องคืนเครดิตให้ครบ');
    }

    public function test_insufficient_credit_goes_to_topup_without_charging(): void
    {
        $user = $this->member(credit: 10);
        $this->fakeUpstream();

        $this->actingAs($user)->post('/deep', [
            'questions' => ['ปีนี้เป็นอย่างไร'],
            '_idem'     => (string) Str::uuid(),
        ])->assertRedirect(route('wallet.topup'));

        $this->assertEqualsWithDelta(10.0, app(WalletService::class)->balance($user), 0.01);
        $this->assertSame(0, Reading::where('type', 'deep')->count());
    }

    /** กดซ้ำฟอร์มเดิม (โทเคนเดิม) ต้องตัดเงินรอบเดียว */
    public function test_double_submit_of_the_same_form_charges_once(): void
    {
        $user = $this->member();
        $this->fakeUpstream();
        $idem = (string) Str::uuid();

        $payload = ['questions' => ['ปีนี้เป็นอย่างไร'], '_idem' => $idem];

        $this->actingAs($user)->post('/deep', $payload);
        $this->actingAs($user)->post('/deep', $payload);

        $this->assertSame(1, Reading::where('type', 'deep')->count());
        $this->assertEqualsWithDelta(61.0, app(WalletService::class)->balance($user), 0.01);
    }

    public function test_requires_at_least_one_question(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->post('/deep', ['questions' => ['', '', ''], '_idem' => (string) Str::uuid()])
            ->assertSessionHasErrors('questions');

        $this->assertEqualsWithDelta(100.0, app(WalletService::class)->balance($user), 0.01);
    }

    public function test_only_the_owner_can_open_the_result(): void
    {
        $user = $this->member();
        $this->fakeUpstream();
        $this->actingAs($user)->post('/deep', [
            'questions' => ['ปีนี้เป็นอย่างไร'], '_idem' => (string) Str::uuid(),
        ]);

        $reading  = Reading::where('type', 'deep')->firstOrFail();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('deep.show', $reading))->assertForbidden();
        $this->actingAs($user)->get(route('deep.show', $reading))->assertOk();
    }
}
