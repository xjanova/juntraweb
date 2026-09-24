<?php

namespace Tests\Feature;

use App\Jobs\InterpretTarotReading;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 📱 (2026-09-24) แอพอ่านไพ่แบบเดียวกับเว็บ: ตัดเงิน → ตอบ 202 ทันที → แม่หมออ่านหลังส่งคำตอบ → แอพถาม /status
 *
 * ตรึงไว้:
 *  - mode: async ตัดเงินครั้งเดียว ตอบ 202 + status pending และส่งงานอ่านเบื้องหลัง (โปรไฟล์ของแพ็กเกจ)
 *  - Idempotency-Key เดิม = รายการเดิมเสมอ แม้ส่งซ้ำหลังล็อก 90 วิหมดอายุ (เดิมถูกตัดเงินรอบสอง)
 *  - แพ็กเกจ web_only (คุณไสย) ซื้อได้เฉพาะแอพที่ถามสถานะได้ — APK รุ่นเก่ายังโดน 422
 *  - รายละเอียดคำทำนายมี status + sections (การ์ด/ตาราง) + ภาพประกอบแพ็กเกจ
 */
class MobileAsyncTarotTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://main.thaiprompt.online';

    private string $reply = "## 🎯 ฟันธง\nผล: ใช่ค่ะ\nไพ่หนุนค่ะลูก\n\n## 🃏 ใบที่ 1 · คำตอบของไพ่\nดวงอาทิตย์ส่องทาง\n\n## 🧭 คำแนะนำ\n- ลงมือเลย";

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Cache::flush();

        Http::fake(function (Request $req) {
            if (str_ends_with($req->url(), '/oauth/token')) {
                return Http::response(['access_token' => 'srv', 'expires_in' => 3600]);
            }
            if (str_ends_with($req->url(), '/server/fortune/tarot/interpret')) {
                return Http::response(['data' => ['interpretation' => $this->reply, 'ai_provider' => 'openai', 'ai_model' => 'gpt-5.6-luna']]);
            }

            return Http::response([], 500);
        });

        foreach (range(0, 11) as $i) {
            TarotCard::create([
                'slug' => "card-{$i}", 'name_en' => "Card {$i}", 'name_th' => "ไพ่ {$i}", 'arcana' => 'major', 'suit' => 'major',
                'number' => $i, 'keywords_th' => 'ทดสอบ', 'upright_meaning_th' => 'ตั้งตรง', 'reversed_meaning_th' => 'กลับหัว', 'active' => true,
            ]);
        }
    }

    private function member(float $credit = 500): User
    {
        $u = User::factory()->create(['thaiprompt_token' => null]);
        app(WalletService::class)->credit($u, $credit, 'seed');

        return $u;
    }

    private function balance(User $u): float
    {
        return (float) app(WalletService::class)->balance($u);
    }

    private function buy(string $type, array $picks, array $extra = [], ?string $key = null)
    {
        return $this->withHeaders(array_filter(['Idempotency-Key' => $key]))
            ->postJson('/api/v1/history/readings', array_merge([
                'type'  => $type,
                'mode'  => 'async',
                'picks' => array_map(fn ($s) => ['slug' => $s, 'reversed' => false], $picks),
            ], $extra));
    }

    public function test_async_purchase_answers_202_pending_and_reads_after_the_response(): void
    {
        Bus::fake();
        $u = $this->member();
        Sanctum::actingAs($u);

        $r = $this->buy('tarot_single', ['card-1'], ['question' => 'งานใหม่ดีไหม']);

        $r->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.type', 'tarot_single')
            ->assertJsonPath('data.result', null)
            ->assertJsonPath('data.package.key', 'single')
            ->assertJsonPath('cost', 9);
        $this->assertSame(491.0, $this->balance($u), 'ตัดเงินครั้งเดียวตอนรับคำขอ');
        Bus::assertDispatchedAfterResponse(InterpretTarotReading::class);

        $reading = Reading::sole();
        $this->assertSame(Reading::STATUS_PENDING, $reading->status);
        $tx = WalletTransaction::where('type', 'debit')->sole();
        $this->assertSame($reading->id, (int) $tx->reference_id, 'แถวตัดเงินผูกกับรายการตั้งแต่ตอนสร้าง (คืนเงินได้ถ้าอ่านไม่สำเร็จ)');

        $this->getJson("/api/v1/history/readings/{$reading->id}/status")
            ->assertOk()->assertJsonPath('data.status', 'pending')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_background_reading_finishes_with_sections_the_app_can_render(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u);

        // ไม่ fake Bus: งานอ่านเบื้องหลังรันตอน terminate ของคำขอทดสอบ
        $id = $this->buy('tarot_single', ['card-2'])->assertStatus(202)->json('data.id');

        $this->getJson("/api/v1/history/readings/{$id}/status")->assertJsonPath('data.status', 'done');
        $detail = $this->getJson("/api/v1/history/readings/{$id}")->assertOk();
        $detail->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.sections.ok', true)
            ->assertJsonPath('data.sections.items.0.type', 'verdict')
            ->assertJsonPath('data.sections.items.0.result', 'ใช่ค่ะ')
            ->assertJsonPath('data.package.name_th', 'ไพ่ใบเดียว');
        $this->assertStringContainsString('ดวงอาทิตย์ส่องทาง', (string) $detail->json('data.result'));
        $this->assertSame(491.0, $this->balance($u));
    }

    public function test_same_idempotency_key_returns_the_same_reading_even_after_the_lock_expired(): void
    {
        Bus::fake();
        $u = $this->member();
        Sanctum::actingAs($u);

        $first = $this->buy('tarot_three', ['card-1', 'card-2', 'card-3'], [], 'tarot-abc-1')->assertStatus(202);

        // ล็อกกันยิงซ้อน 90 วิหมดอายุแล้ว — เดิมคำขอซ้ำตรงนี้ถูกตัดเงินรอบสอง
        $this->travel(5)->minutes();
        $again = $this->buy('tarot_three', ['card-1', 'card-2', 'card-3'], [], 'tarot-abc-1');

        $again->assertStatus(202)->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, Reading::count(), 'ห้ามสร้างรายการที่สอง');
        $this->assertSame(1, WalletTransaction::where('type', 'debit')->count(), 'ห้ามตัดเงินรอบสอง');
        $this->assertSame(481.0, $this->balance($u));

        // คีย์ใหม่ = การซื้อครั้งใหม่จริง
        $this->buy('tarot_three', ['card-4', 'card-5', 'card-6'], [], 'tarot-abc-2')->assertStatus(202);
        $this->assertSame(2, Reading::count());
    }

    public function test_a_refunded_attempt_replays_as_failed_instead_of_charging_again(): void
    {
        Bus::fake();
        $u = $this->member();
        Sanctum::actingAs($u);

        $id = $this->buy('tarot_single', ['card-1'], [], 'tarot-fail-1')->json('data.id');
        // แม่หมออ่านไม่สำเร็จ → คืนเงิน
        app(\App\Services\Tarot\TarotReadingFinisher::class)->fail(Reading::find($id), 'test', [Reading::STATUS_PENDING]);
        $this->assertSame(500.0, $this->balance($u));

        $this->travel(5)->minutes();
        $this->buy('tarot_single', ['card-1'], [], 'tarot-fail-1')
            ->assertStatus(503)->assertJsonPath('reason_code', 'reading_failed');
        $this->assertSame(500.0, $this->balance($u), 'คำขอซ้ำของรายการที่คืนเงินแล้ว ต้องไม่ตัดเงินใหม่');
        $this->getJson("/api/v1/history/readings/{$id}/status")->assertJsonPath('data.status', 'failed');
    }

    public function test_web_only_package_needs_the_async_mode(): void
    {
        Bus::fake();
        Setting::put('tarot_kunsai_visible', '1', 'pricing');
        Sanctum::actingAs($this->member());
        $ten = array_map(fn ($i) => "card-{$i}", range(0, 9));

        // APK รุ่นเก่า (รอผลในคำขอเดียว) ยังซื้อคุณไสยไม่ได้
        $this->postJson('/api/v1/history/readings', [
            'type'  => 'tarot_kunsai',
            'picks' => array_map(fn ($s) => ['slug' => $s], $ten),
        ])->assertStatus(422);

        $this->buy('tarot_kunsai', $ten, ['birth_date' => '1990-05-01'])->assertStatus(202)
            ->assertJsonPath('data.package.key', 'kunsai');
        $this->assertSame('1990-05-01', Reading::sole()->payload['birth_date'] ?? null);
    }

    public function test_hidden_package_cannot_be_bought_even_async(): void
    {
        Sanctum::actingAs($this->member());
        $ten = array_map(fn ($i) => "card-{$i}", range(0, 9));

        $this->buy('tarot_kunsai', $ten)->assertStatus(422);
        $this->assertSame(0, Reading::count());
    }

    public function test_duplicate_slots_are_rejected_before_any_charge(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u);
        $deal = $this->postJson('/api/v1/tarot/deal')->assertCreated()->json('data.deal_token');

        $this->postJson('/api/v1/history/readings', [
            'type' => 'tarot_three', 'mode' => 'async', 'deal_token' => $deal, 'slots' => [4, 4, 7],
        ])->assertStatus(422)->assertJsonValidationErrors(['slots.1']);
        $this->assertSame(500.0, $this->balance($u));
    }

    public function test_someone_else_cannot_poll_my_reading(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->member());
        $id = $this->buy('tarot_single', ['card-1'])->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/history/readings/{$id}/status")->assertForbidden();
    }

    public function test_history_list_shows_status_and_title(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->member());
        $this->buy('tarot_single', ['card-1']);

        $this->getJson('/api/v1/history/readings')->assertOk()
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.title', 'ไพ่ใบเดียว');
    }
}
