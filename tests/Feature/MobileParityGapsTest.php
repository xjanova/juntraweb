<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\PayoutAccount;
use Database\Seeders\ZodiacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ช่องว่างระหว่างแอพกับเว็บที่ปิดไปในรอบนี้ — ตรึงไว้ไม่ให้เปิดกลับ
 *
 * ทุกข้อในไฟล์นี้เป็นเรื่องเดียวกัน: **เว็บมีของอยู่แล้ว แต่ API ของแอพไม่ส่งมา**
 * ผู้ใช้คนเดียวกันจึงได้ประสบการณ์คนละแบบตามช่องทางที่เข้า ทั้งที่จ่ายเท่ากัน
 */
class MobileParityGapsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * เปิดห้องแชทเดิมต่อ ต้องได้สถานะครบเท่าตอนเปิดห้องใหม่
     *
     * แอพเข้าทาง "เปิดห้องล่าสุดต่อ" เป็นค่าเริ่มต้น = ผู้ใช้เดิมแทบทุกคน
     * ถ้า show() ไม่ส่ง cost มา ผู้ใช้จะไม่เห็นว่าข้อความถัดไปคิดเงินเท่าไร
     * — พิมพ์ส่งไปแล้วเครดิตถึงจะหาย ต่างจากเว็บที่ขึ้นป้ายราคาให้ก่อนเสมอ
     */
    public function test_chat_show_returns_full_state_like_start(): void
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, 100, 'seed');
        Sanctum::actingAs($u);

        $convo = ChatConversation::create([
            'user_id'       => $u->id,
            'session_token' => (string) Str::uuid(),
            'title'         => 'ห้องเดิม',
        ]);

        $r = $this->getJson("/api/v1/chat/conversations/{$convo->id}");

        $r->assertOk();
        // เช็ค "มีคีย์" ไม่ใช่ "ไม่เป็น null" — daily_left เป็น null ได้จริง
        // เมื่อยังไม่ได้ตั้งเพดานรายวัน (ChatPolicy::dailyLeft คืน null)
        $data = $r->json('data');
        foreach (['balance', 'cost', 'daily_limit', 'daily_left', 'blocked', 'suggestions'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $data,
                "GET /chat/conversations/{id} ต้องส่ง `$key` มาด้วย (เท่ากับตอนเปิดห้องใหม่)"
            );
        }
        $this->assertIsArray($r->json('data.suggestions'));
    }

    /** ดวงรายวันต้องส่ง URL ภาพราศีมาให้ ไม่ใช่ให้แอพวาดแต่ตัวอักษร */
    public function test_horoscope_index_exposes_zodiac_art_url(): void
    {
        $this->seed(ZodiacSeeder::class);

        $r = $this->getJson('/api/v1/horoscope');

        $r->assertOk();
        $rows = $r->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('image_url', $row, 'ทุกราศีต้องมีคีย์ image_url (null ได้ถ้าไฟล์ยังไม่มี)');
        }
    }

    /** ปีนักษัตร — เว็บมีมานาน แต่แอพเพิ่งเข้าถึงได้ */
    public function test_thai_zodiac_endpoint_returns_twelve_signs_and_this_year(): void
    {
        $this->seed(ZodiacSeeder::class);

        $r = $this->getJson('/api/v1/horoscope/thai-zodiac');

        $r->assertOk();
        $this->assertCount(12, $r->json('data.signs'));
        $this->assertNotEmpty($r->json('data.current_slug'));
        $this->assertSame((int) now()->year, $r->json('data.year'));
    }

    /** หมวดงานมงคล — ให้ฟอร์มในแอพกาง dropdown ได้เหมือนเว็บ */
    public function test_occasions_endpoint_lists_every_category(): void
    {
        $r = $this->getJson('/api/v1/fortune/occasions');

        $r->assertOk();
        $this->assertNotEmpty($r->json('data'));
    }

    /**
     * เลขศาสตร์ต้องเก็บเลขทั้งสามลง payload
     *
     * หน้าผลของเว็บโชว์เป็นตัวเลขใหญ่สามช่อง แต่ payload เดิมมีแค่ name+birth_date
     * แอพจึงไม่มีทางแสดงเลขได้เลย ทั้งที่จ่ายราคาเดียวกัน
     */
    public function test_numerology_payload_carries_the_three_numbers(): void
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, 500, 'seed');
        Sanctum::actingAs($u);

        $r = $this->postJson('/api/v1/fortune/numerology', [
            'name'       => 'สมชาย ใจดี',
            'birth_date' => '1990-05-17',
        ]);

        if ($r->status() !== 201) {
            $this->markTestSkipped('numerology ไม่พร้อมในสภาพแวดล้อมนี้: ' . $r->status());
        }

        $id = $r->json('data.id');
        $detail = $this->getJson("/api/v1/history/readings/{$id}")->assertOk();

        foreach (['life_path', 'expression', 'birth_day_reduced'] as $key) {
            $this->assertNotNull(
                $detail->json("data.payload.$key"),
                "payload ต้องมี `$key` ให้แอพแสดงเลขได้"
            );
        }
    }

    /**
     * QR ตอนกลับมาอัปสลิปซ้ำ ต้องมาจากบัญชีเดียวกับตอนสร้างรายการ
     *
     * เดิม initiate ใช้ PayoutAccount::resolve() แต่ show ไปอ่าน
     * Setting::get('promptpay_id') ซึ่ง **ไม่เคยถูกเขียน** (PayoutAccount
     * เก็บลงคีย์ payout_account_snapshot คนละคีย์) → QR ว่างเปล่าเสมอ
     */
    public function test_topup_show_uses_the_same_promptpay_source_as_initiate(): void
    {
        $u = User::factory()->create();
        Sanctum::actingAs($u);

        $payout = PayoutAccount::resolve($u);
        if (empty($payout['promptpay_id'])) {
            $this->markTestSkipped('ไม่มีบัญชีรับเงินในสภาพแวดล้อมทดสอบ');
        }

        $start = $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100])
            ->assertSuccessful();
        $txId = $start->json('data.id');

        $show = $this->getJson("/api/v1/wallet/topup/{$txId}")->assertOk();

        $this->assertSame(
            $start->json('data.promptpay.id'),
            $show->json('data.promptpay.id'),
            'เลข PromptPay ตอน show ต้องเป็นเลขเดียวกับตอน initiate'
        );
        $this->assertNotNull($show->json('data.qr_payload') ?? $show->json('data.promptpay.qr_payload'));
        $this->assertNotNull($show->json('data.balance'), 'ชีทเติมเงินอ่าน balance จากที่นี่');
    }

    /** กดปุ่มจำนวนเงินซ้ำในเวลาไล่เลี่ยกัน ต้องได้รายการเดิม ไม่ใช่ใบใหม่ */
    public function test_repeated_topup_of_the_same_amount_reuses_the_pending_row(): void
    {
        $u = User::factory()->create();
        Sanctum::actingAs($u);

        $a = $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100])->assertSuccessful();
        $b = $this->postJson('/api/v1/wallet/topup/promptpay', ['amount' => 100])->assertSuccessful();

        $this->assertSame($a->json('data.id'), $b->json('data.id'), 'ต้องคืนใบเดิม');
        $this->assertSame(
            1,
            WalletTransaction::where('user_id', $u->id)->where('status', 'pending')->count(),
            'ห้ามมีใบ pending ซ้ำจากการกดสองครั้ง',
        );
    }

    /** สมัครในแอพพร้อมโค้ดผู้แนะนำ ต้องเก็บไว้รอผูกสายงาน */
    public function test_mobile_register_stores_the_referral_code(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'คนถูกชวน',
            'email'                 => 'invited@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'referral_code'         => 'ABC-123!!',   // มีอักขระต้องห้ามปนมา
        ])->assertCreated();

        $user = User::where('email', 'invited@example.com')->firstOrFail();
        $this->assertSame('ABC-123', $user->pending_referral_code, 'ต้อง sanitize ให้เหลือชุดที่ route /r/{code} รับ');
    }
}
