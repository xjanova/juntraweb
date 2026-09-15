<?php

namespace Tests\Feature;

use App\Jobs\InterpretTarotReading;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Models\User;
use App\Services\FortuneBot\FortuneAiService;
use App\Services\Tarot\TarotReadingFinisher;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * 🔮 (2026-09-15) แม่หมออ่านไพ่เบื้องหลัง — แพ็กเกจยาวบนเลนทำนายใช้ 36-55 วิ ชนเพดาน ~60 วิของคำขอหน้าเว็บ
 *
 * ล็อกเรื่องเงิน: ตัดเงินครั้งเดียว · ไม่สำเร็จ = คืนครั้งเดียว (งานเบื้องหลังกับตัวกวาดแข่งกันไม่ได้คืนซ้ำ)
 * · รายการที่ค้างเพราะ PHP ตายกลางงานถูกคืนเงินโดยตัวกวาด
 */
class TarotBackgroundReadingTest extends TestCase
{
    use RefreshDatabase;

    private function paidPendingReading(User $user, float $price = 99): Reading
    {
        $wallet = app(WalletService::class);
        $tx = $wallet->debit($user, $price, 'เปิดไพ่: ทดสอบ', ['reference_type' => 'reading']);
        $card = TarotCard::create([
            'slug' => 'the-sun-'.Str::random(4), 'name_en' => 'The Sun', 'name_th' => 'ดวงอาทิตย์', 'arcana' => 'major', 'suit' => 'major',
            'number' => 19, 'keywords_th' => 'สำเร็จ', 'upright_meaning_th' => 'ความสำเร็จ', 'reversed_meaning_th' => 'ล่าช้า', 'active' => true,
        ]);
        $reading = Reading::create([
            'user_id' => $user->id, 'session_token' => (string) Str::uuid(), 'type' => 'tarot_single',
            'payload' => ['positions' => ['คำตอบของไพ่'], 'cost' => $price, 'wallet_tx_id' => $tx->id],
            'status' => Reading::STATUS_PENDING,
        ]);
        $reading->tarotCards()->create(['tarot_card_id' => $card->id, 'position' => 1, 'position_label' => 'คำตอบของไพ่', 'reversed' => false]);

        return $reading;
    }

    private function member(): User
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, 200, 'seed');

        return $u;
    }

    private function balance(User $u): float
    {
        return (float) app(WalletService::class)->balance($u->fresh());
    }

    private function fakeAi(?array $result, int $times = 1): void
    {
        $ai = Mockery::mock(FortuneAiService::class);
        $exp = $ai->shouldReceive('interpretTarot')->times($times);
        $result === null ? $exp->andThrow(new \RuntimeException('boom')) : $exp->andReturn($result);
        $this->app->instance(FortuneAiService::class, $ai);
    }

    public function test_the_cast_returns_at_once_and_mae_mor_reads_after_the_response(): void
    {
        Bus::fake();
        // ทางไป Thaiprompt ต้องพร้อมก่อน ไม่งั้นระบบหยุดตั้งแต่ก่อนตัดเงิน (isAvailableFor)
        \App\Models\Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        \App\Models\Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        \Illuminate\Support\Facades\Cache::flush();
        $u = $this->member();
        TarotCard::create(['slug' => 'fool', 'name_en' => 'The Fool', 'name_th' => 'คนโง่', 'arcana' => 'major', 'suit' => 'major', 'number' => 0,
            'keywords_th' => 'เริ่ม', 'upright_meaning_th' => 'เริ่มใหม่', 'reversed_meaning_th' => 'ประมาท', 'active' => true]);

        $this->actingAs($u)->post(route('tarot.cast'), ['spread' => 'single'])->assertRedirect();

        $reading = Reading::sole();
        $this->assertSame(Reading::STATUS_PENDING, $reading->status);
        Bus::assertDispatchedAfterResponse(InterpretTarotReading::class, fn ($job) => $job->readingId === $reading->id);

        // While แม่หมอ reads: the page shows the drawn cards and a waiting state that polls the status.
        $this->actingAs($u)->get(route('tarot.show', $reading))
            ->assertOk()->assertSee('แม่หมอกำลังอ่านไพ่ของลูก')->assertSee(route('tarot.status', $reading), false)
            ->assertDontSee('ถามแม่หมอต่อจากไพ่ชุดนี้');
        $this->actingAs($u)->getJson(route('tarot.status', $reading))->assertOk()->assertJsonPath('status', 'reading');
        $this->actingAs(User::factory()->create())->getJson(route('tarot.status', $reading))->assertForbidden();
    }

    public function test_a_finished_reading_is_saved_and_charged_once(): void
    {
        $u = $this->member();
        $reading = $this->paidPendingReading($u);
        $this->fakeAi(['text' => "## 🎯 ฟันธง\nผล: ใช่\nดีค่ะ", 'provider' => 'openai', 'model' => 'gpt-5.6-luna', 'source' => 'thaiprompt']);

        app(TarotReadingFinisher::class)->interpret($reading->id);
        app(TarotReadingFinisher::class)->interpret($reading->id); // a second run is a no-op

        $reading->refresh();
        $this->assertNull($reading->status);
        $this->assertStringContainsString('ผล: ใช่', $reading->result);
        $this->assertSame(101.0, $this->balance($u));
        $this->actingAs($u)->getJson(route('tarot.status', $reading))->assertJsonPath('status', 'done');
    }

    public function test_card_meaning_text_instead_of_a_reading_is_refunded(): void
    {
        $u = $this->member();
        $reading = $this->paidPendingReading($u);
        $this->fakeAi(['text' => 'ความหมายไพ่จากตำรา', 'provider' => 'gemini', 'model' => 'x', 'source' => 'local']);

        app(TarotReadingFinisher::class)->interpret($reading->id);

        $this->assertTrue($reading->fresh()->isFailed());
        $this->assertSame(200.0, $this->balance($u));
    }

    public function test_a_crash_is_refunded_and_the_sweeper_cannot_refund_it_again(): void
    {
        $u = $this->member();
        $reading = $this->paidPendingReading($u);
        $this->fakeAi(null);

        app(TarotReadingFinisher::class)->interpret($reading->id);
        $this->travel(10)->minutes();
        $this->assertSame(0, app(TarotReadingFinisher::class)->sweepStuck());

        $this->assertSame(200.0, $this->balance($u), 'refunded exactly once');
    }

    public function test_the_sweeper_refunds_a_reading_whose_background_work_died(): void
    {
        $u = $this->member();
        $reading = $this->paidPendingReading($u);
        Reading::whereKey($reading->id)->update(['status' => Reading::STATUS_WORKING]); // PHP died mid-read

        $this->assertSame(0, app(TarotReadingFinisher::class)->sweepStuck(), 'too early — may still be reading');
        $this->travel(6)->minutes();
        $this->artisan('readings:sweep-stuck')->assertSuccessful();

        $this->assertTrue($reading->fresh()->isFailed());
        $this->assertSame(200.0, $this->balance($u));

        // A late finish after the sweeper decided must not overwrite the refunded row.
        $this->fakeAi(['text' => 'สายไป', 'provider' => 'openai', 'model' => 'x', 'source' => 'thaiprompt'], 0);
        app(TarotReadingFinisher::class)->interpret($reading->id);
        $this->assertTrue($reading->fresh()->isFailed());
    }
}
