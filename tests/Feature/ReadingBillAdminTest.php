<?php

namespace Tests\Feature;

use App\Filament\Resources\ReadingResource;
use App\Jobs\InterpretTarotReading;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Models\User;
use App\Services\Readings\ReadingBillActions;
use App\Services\Wallet\WalletService;
use App\Support\ReadingBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🧾 หลังบ้าน "บิลดูดวง" (เจ้าของ 2026-09-15: บิลเว็บแยกเก็บจาก Thaiprompt แต่จัดการได้เหมือนกัน)
 */
class ReadingBillAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function bill(User $u, float $price = 39, array $extra = []): Reading
    {
        $tx = app(WalletService::class)->debit($u, $price, 'เปิดไพ่: ไพ่ความรัก', ['reference_type' => 'reading']);
        $card = TarotCard::create(['slug' => 'lovers-'.Str::random(4), 'name_en' => 'The Lovers', 'name_th' => 'คนรัก', 'arcana' => 'major', 'suit' => 'major',
            'number' => 6, 'keywords_th' => 'รัก', 'upright_meaning_th' => 'ความรัก', 'reversed_meaning_th' => 'ไม่ลงรอย', 'active' => true]);
        $r = Reading::create(array_merge([
            'user_id' => $u->id, 'session_token' => (string) Str::uuid(), 'type' => 'tarot_love', 'question' => 'เขาจะกลับมาไหม',
            'payload' => ['cost' => $price, 'wallet_tx_id' => $tx->id, 'birth_date' => '1990-06-27'],
            'result' => "## 🎯 ฟันธง\nผล: ใช่", 'ai_provider' => 'openai', 'ai_model' => 'gpt-5.6-luna',
        ], $extra));
        $r->tarotCards()->create(['tarot_card_id' => $card->id, 'position' => 1, 'position_label' => 'ตัวคุณ', 'reversed' => true]);

        return $r;
    }

    private function member(): User
    {
        $u = User::factory()->create(['name' => 'สมใจ ใจดี', 'email' => 'somjai@example.com']);
        app(WalletService::class)->credit($u, 200, 'seed');

        return $u;
    }

    public function test_the_bill_list_and_detail_pages_render_with_bill_numbers_and_statuses(): void
    {
        $r = $this->bill($this->member());
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/readings')->assertOk()
            ->assertSee(ReadingBill::number($r))->assertSee('สำเร็จ')->assertSee('รายได้ดูดวงวันนี้');
        $this->actingAs($admin)->get('/admin/readings/'.$r->id)->assertOk()
            ->assertSee(ReadingBill::number($r))->assertSee('ไพ่ความรัก / เนื้อคู่')->assertSee('1990-06-27')
            ->assertSee('กลับหัว')->assertSee('เปิดหน้าที่ลูกค้าเห็น');
    }

    public function test_bill_numbers_round_trip_for_search(): void
    {
        $r = $this->bill($this->member());

        $this->assertSame($r->id, ReadingBill::idFromNumber(ReadingBill::number($r)));
        $this->assertSame(42, ReadingBill::idFromNumber('42'));
        $this->assertNull(ReadingBill::idFromNumber('สมใจ'));
    }

    public function test_status_filters_match_the_badges(): void
    {
        $u = $this->member();
        $paid = $this->bill($u);
        $reading = $this->bill($u, 19, ['status' => Reading::STATUS_WORKING, 'result' => null]);
        $failed = $this->bill($u, 19, ['status' => Reading::STATUS_FAILED, 'result' => null]);
        $free = $this->bill($u, 9, ['payload' => ['cost' => 0]]);

        foreach (['paid' => $paid, 'reading' => $reading, 'failed' => $failed, 'free' => $free] as $status => $r) {
            $this->assertSame($status, ReadingBill::status($r->fresh()));
            $ids = ReadingResource::applyStatus(Reading::query(), $status)->pluck('id')->all();
            $this->assertSame([$r->id], $ids, "filter {$status}");
        }
    }

    public function test_an_admin_refund_returns_the_money_once_and_keeps_the_reading(): void
    {
        $u = $this->member();
        $r = $this->bill($u);
        $actions = app(ReadingBillActions::class);

        $this->assertTrue($actions->refund($r, 'ลูกค้าแจ้งว่าไม่ตรง', $this->admin()));
        $this->assertFalse($actions->refund($r->fresh(), 'กดซ้ำ', null), 'second click must not refund again');

        $r->refresh();
        $this->assertSame('refunded', ReadingBill::status($r));
        $this->assertSame(200.0, (float) app(WalletService::class)->balance($u->fresh()));
        $this->assertNotNull($r->result, 'the customer keeps the reading');
        $this->assertSame(['refunded'], ReadingResource::applyStatus(Reading::query(), 'refunded')->get()->map(fn ($x) => ReadingBill::status($x))->all());
    }

    public function test_retry_re_reads_the_same_cards_without_charging(): void
    {
        Bus::fake();
        $u = $this->member();
        $r = $this->bill($u);

        $this->assertTrue(app(ReadingBillActions::class)->retry($r));
        $this->assertSame(Reading::STATUS_PENDING, $r->fresh()->status);
        Bus::assertDispatchedAfterResponse(InterpretTarotReading::class);
        $this->assertFalse(app(ReadingBillActions::class)->retry($r->fresh()), 'already reading');
        $this->assertSame(161.0, (float) app(WalletService::class)->balance($u->fresh()), 'no new charge');
    }

    public function test_a_retried_refunded_bill_stays_marked_as_refunded(): void
    {
        Bus::fake();
        $u = $this->member();
        $r = $this->bill($u, 39, ['status' => Reading::STATUS_FAILED, 'result' => null]);
        app(WalletService::class)->refund(ReadingBill::debit($r), 'ระบบคืนอัตโนมัติ');

        app(ReadingBillActions::class)->retry($r->fresh());
        Reading::whereKey($r->id)->update(['status' => null, 'result' => 'คำทำนายใหม่']);

        $this->assertSame('refunded', ReadingBill::status($r->fresh()), 'a goodwill re-read is not revenue');
    }

    public function test_members_cannot_open_the_bill_pages(): void
    {
        $this->actingAs($this->member())->get('/admin/readings')->assertForbidden();
    }
}
