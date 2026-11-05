<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ✨🔒🧾 (2026-11-05) ห้องพยากรณ์: ปุ่มที่ AI คิดให้ · ซ่อนปุ่มเปิดไพ่ระหว่างทำนาย/บิลค้าง · "พอแล้ว"
 *
 * เจ้าของสั่ง:
 *   - "ให้ขึ้นคำถาม ทางเลือก ที่เอไอทำขึ้นเพื่อให้ผู้ใช้ได้กด ... เพิ่มจากของเดิม และถ้าทำนายอยู่ปุ่มดูดวงไม่ควรโผล่มา"
 *   - "ป้ายเปิดไพ่ในห้องต้องไม่ขึ้นให้เลือกถ้ายังค้างบิลอยู่ ควรแสดงเลขบิลด้วยว่าบิลไหน ได้รับเงินหรือยัง"
 *   - "ปุ่ม พอแล้ว ด้วย หมายถึงเลิกก่อนเวลา"
 */
class ChatRoomHoldTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    /** คำตอบของ /server/chat/send ที่ใช้ในเทสต์นี้ */
    private array $upstream = ['reply' => 'รับฟังอยู่ค่ะ', 'kind' => 'reply'];

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
        config(['chat_rooms.persona_upstream' => true]);
        Cache::flush();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => Http::response(['data' => ['session_id' => 'sess-1'] + $this->upstream]),
                default => Http::response([], 503),
            };
        });
    }

    private function member(): User
    {
        return User::factory()->create(['thaiprompt_token' => null]);
    }

    /** payload ที่ส่งไป /server/chat/send */
    private function sendCalls(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/server/chat/send'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()->all();
    }

    private function startPick($test, string $room = 'busaba'): void
    {
        $test->withSession(['tarot_pick' => ['spread' => 'quick', 'question' => null, 'room' => $room, 'started_at' => now()->getTimestamp()]]);
    }

    public function test_ai_follow_up_questions_are_asked_for_cleaned_returned_and_kept_for_reload(): void
    {
        $this->upstream = ['reply' => 'งานใหม่มีแววดีค่ะ', 'kind' => 'reply', 'suggestions' => [
            'งานใหม่จะได้เงินเพิ่มไหม',
            'ดูรายละเอียดที่ https://evil.test',   // ลิงก์ — ตัดทิ้ง
            'จ่าย 99 บาทเลยไหม',                    // ราคา — ตัดทิ้ง
            'งานใหม่จะได้เงินเพิ่มไหม',              // ซ้ำ — ตัดทิ้ง
            42,                                      // ไม่ใช่ข้อความ — ตัดทิ้ง
            'ควรเริ่มเดือนไหนดี',
        ]];
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'งานใหม่จะผ่านไหม', 'room' => 'busaba'])->assertOk();

        $this->assertSame(['งานใหม่จะได้เงินเพิ่มไหม', 'ควรเริ่มเดือนไหนดี'], $res->json('ai_suggestions'));
        $this->assertNotEmpty($res->json('suggestions'), 'ปุ่มลัดเดิมยังอยู่ — ปุ่มจาก AI เป็นของเพิ่ม');
        $this->assertSame(1, (int) ($this->sendCalls()[0]['suggest'] ?? 0), 'ต้องขอให้ผู้พยากรณ์คิดปุ่มมาด้วย');

        // รีเฟรชหน้าแล้วปุ่มชุดล่าสุดยังอยู่
        $this->actingAs($user)->get('/chat?room=busaba')->assertOk()
            ->assertViewHas('aiSuggestions', ['งานใหม่จะได้เงินเพิ่มไหม', 'ควรเริ่มเดือนไหนดี']);
    }

    public function test_an_upstream_without_suggestions_falls_back_to_the_static_chips_only(): void
    {
        $user = $this->member();
        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'สวัสดีค่ะ', 'room' => 'busaba'])->assertOk();

        $this->assertSame([], $res->json('ai_suggestions'));
        $this->assertNotEmpty($res->json('suggestions'));
    }

    public function test_while_picking_cards_no_new_reading_can_be_offered(): void
    {
        $user = $this->member();
        $this->startPick($this);

        $page = $this->actingAs($user)->get('/chat?room=busaba')->assertOk();
        $page->assertViewHas('tableOffers', []);
        $page->assertViewHas('roomLock', fn ($l) => $l['reason'] === 'pick' && $l['can_end'] === true && $l['ends_at'] > now()->getTimestamp());
        $page->assertSee('อยู่ในพิธีเปิดไพ่', false);
        $labels = collect($page->viewData('suggestions'))->pluck('label')->all();
        $this->assertNotContains('ดูดวงเชิงลึก', $labels);

        // ขอดูดวงใหม่ระหว่างพิธี → คำตอบนุ่ม ๆ ไม่มีการ์ด ไม่ถาม AI ไม่คิดเงิน
        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'ดูดวงความรักให้หน่อย', 'room' => 'busaba'])->assertOk();
        $this->assertSame([], $res->json('offers'));
        $this->assertStringContainsString('พิธีเปิดไพ่', $res->json('reply'));
        $this->assertSame(0.0, (float) $res->json('cost'));
    }

    public function test_enough_ends_the_card_ritual_early_without_a_charge(): void
    {
        $user = $this->member();
        $this->startPick($this);

        $this->actingAs($user)->post('/tarot/abandon')
            ->assertRedirect(route('chat.index', ['room' => 'busaba']))
            ->assertSessionMissing('tarot_pick');

        $this->actingAs($user)->get('/chat?room=busaba')->assertOk()
            ->assertViewHas('roomLock', null)
            ->assertViewHas('tableOffers', fn ($o) => count($o) > 0);
        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->where('type', 'debit')->count());
    }

    public function test_a_pending_bill_replaces_the_table_offers_and_shows_its_number(): void
    {
        $user = $this->member();
        $bill = $this->actingAs($user)->postJson(route('chat.topup.store'), ['amount' => 100])->assertOk();
        $ref = $bill->json('reference_code');
        $this->assertNotEmpty($ref);

        $page = $this->actingAs($user)->get('/chat?room=busaba')->assertOk();
        $page->assertViewHas('tableOffers', []);
        $page->assertViewHas('pendingBill', fn ($b) => $b['reference_code'] === $ref && $b['id'] === $bill->json('id'));
        $page->assertSee('บิลเลขที่ ' . $ref, false);

        // เปิด QR บิลเดิมได้อีกครั้ง (หลังรีเฟรช) — บิลเดิม ยอดเดิม ไม่ออกบิลใหม่
        $resume = $this->actingAs($user)->getJson(route('chat.topup.resume', ['tx' => $bill->json('id')]))->assertOk();
        $this->assertSame($bill->json('payable'), $resume->json('payable'));
        $this->assertSame($ref, $resume->json('reference_code'));
        $this->assertStringStartsWith('data:image/svg', (string) $resume->json('qr'));
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'topup')->count());

        // สถานะบิลบอกเลขบิลด้วย
        $this->actingAs($user)->getJson(route('chat.topup.status', ['tx' => $bill->json('id')]))
            ->assertOk()->assertJson(['reference_code' => $ref, 'paid' => false]);

        // ขอดูดวงระหว่างบิลค้าง → บอกเลขบิล ไม่ยื่นการ์ด
        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'เปิดไพ่ให้หน่อย', 'room' => 'busaba'])->assertOk();
        $this->assertSame([], $res->json('offers'));
        $this->assertStringContainsString($ref, $res->json('reply'));
    }

    public function test_someone_elses_bill_cannot_be_resumed_and_a_paid_bill_is_gone(): void
    {
        $owner = $this->member();
        $bill = $this->actingAs($owner)->postJson(route('chat.topup.store'), ['amount' => 50])->assertOk();

        $this->actingAs($this->member())->getJson(route('chat.topup.resume', ['tx' => $bill->json('id')]))->assertForbidden();

        WalletTransaction::findOrFail($bill->json('id'))->update(['status' => 'success']);
        $this->actingAs($owner)->getJson(route('chat.topup.resume', ['tx' => $bill->json('id')]))
            ->assertStatus(410)->assertJson(['paid' => true]);
        $this->actingAs($owner)->get('/chat?room=busaba')->assertOk()->assertViewHas('pendingBill', null);
    }
}
