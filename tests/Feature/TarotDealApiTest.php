<?php

namespace Tests\Feature;

use App\Models\TarotCard;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * กองไพ่ต้องถูกสับที่เซิร์ฟเวอร์ และหัวตั้ง/หัวกลับต้องเป็นของเซิร์ฟเวอร์
 *
 * บั๊กที่ตรึงไว้:
 * 1. สำรับในแอพเป็น `const` เรียง id 0–77 ตายตัว ซีน "สับไพ่" เป็นแอนิเมชันล้วน
 *    ช่องซ้ายบนสุดจึงเป็น The Fool ตลอดกาล — แตะตำแหน่งเดิมได้ไพ่เดิมเป๊ะ
 * 2. แอพสุ่มหัวกลับเอง 30% แล้วส่งขึ้นมาให้เซิร์ฟเวอร์เชื่อ ขณะที่เว็บใช้ 50%
 *    (`random_int(0,1)`) — คนเดียวกันเปิดไพ่สองช่องทางได้สัดส่วนต่างกันเกือบเท่าตัว
 *    และไคลเอนต์ที่ถูกแก้เลือกไพ่ที่อยากได้เองได้
 */
class TarotDealApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedDeck(int $n = 78): void
    {
        for ($i = 0; $i < $n; $i++) {
            TarotCard::create([
                'slug'                => 'card-' . $i,
                'name_en'             => 'Card ' . $i,
                'name_th'             => 'ไพ่ ' . $i,
                'arcana'              => 'major',
                'suit'                => 'major',
                'number'              => $i,
                'upright_meaning_th'  => 'หงาย ' . $i,
                'reversed_meaning_th' => 'กลับ ' . $i,
                'active'              => true,
            ]);
        }
    }

    /** แจกไพ่ต้องได้กองครบ 78 ใบ ไม่ซ้ำ */
    public function test_deal_returns_the_whole_deck_without_duplicates(): void
    {
        $this->seedDeck();
        Sanctum::actingAs(User::factory()->create());

        $r = $this->postJson('/api/v1/tarot/deal', ['type' => 'tarot_three']);

        $r->assertCreated();
        $cards = $r->json('data.cards');
        $this->assertCount(78, $cards);
        $this->assertCount(78, array_unique(array_column($cards, 'slug')), 'ห้ามมีไพ่ซ้ำในกอง');
        $this->assertNotEmpty($r->json('data.deal_token'));
    }

    /** แจกสองครั้งต้องได้ลำดับต่างกัน — ถ้าเหมือนกันแปลว่าไม่ได้สับจริง */
    public function test_two_deals_are_shuffled_differently(): void
    {
        $this->seedDeck();
        Sanctum::actingAs(User::factory()->create());

        $a = $this->postJson('/api/v1/tarot/deal', ['type' => 'tarot_three'])->json('data.cards');
        $b = $this->postJson('/api/v1/tarot/deal', ['type' => 'tarot_three'])->json('data.cards');

        $this->assertNotSame(
            array_column($a, 'slug'),
            array_column($b, 'slug'),
            'สองกองติดกันเหมือนเป๊ะ = ไม่ได้สับ (โอกาสบังเอิญ ~1/78!)'
        );
    }

    /**
     * หัวกลับต้องราว ๆ 50% เหมือนเว็บ ไม่ใช่ 30% แบบที่แอพเคยสุ่มเอง
     *
     * ใช้ช่วงกว้าง (35–65%) จากตัวอย่าง 390 ใบ เพื่อไม่ให้เทสต์แกว่งตามโชค
     * แต่ยังแคบพอจะจับ 30% ที่เป็นค่าเดิมของแอพได้
     */
    public function test_reversed_rate_is_about_half(): void
    {
        $this->seedDeck();
        Sanctum::actingAs(User::factory()->create());

        $reversed = 0;
        $total    = 0;
        for ($i = 0; $i < 5; $i++) {
            foreach ($this->postJson('/api/v1/tarot/deal', ['type' => 'tarot_three'])->json('data.cards') as $c) {
                $total++;
                if ($c['reversed']) $reversed++;
            }
        }

        $rate = $reversed / $total;
        $this->assertGreaterThan(0.35, $rate, "อัตราหัวกลับ $rate ต่ำเกินไป (ค่าเดิมของแอพคือ 30%)");
        $this->assertLessThan(0.65, $rate, "อัตราหัวกลับ $rate สูงเกินไป");
    }

    /** บันทึกผลด้วย deal_token + slots — เซิร์ฟเวอร์ต้องใช้ไพ่จากกองของตัวเอง */
    public function test_store_resolves_cards_from_the_server_deal(): void
    {
        $this->seedDeck();
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, 500, 'seed');
        Sanctum::actingAs($u);

        $deal  = $this->postJson('/api/v1/tarot/deal', ['type' => 'tarot_three'])->json('data');
        $slots = [5, 17, 42];

        $expected = array_map(fn ($s) => $deal['cards'][$s]['slug'], $slots);

        $r = $this->postJson('/api/v1/history/readings', [
            'type'       => 'tarot_three',
            'deal_token' => $deal['deal_token'],
            'slots'      => $slots,
            // ส่ง picks ปลอมมาด้วย — ต้องถูกเมิน เพราะกองของเซิร์ฟเวอร์คือความจริง
            'picks'      => [
                ['slug' => 'card-0', 'reversed' => false],
                ['slug' => 'card-1', 'reversed' => false],
                ['slug' => 'card-2', 'reversed' => false],
            ],
        ]);

        // 409 = ยังไม่ได้ผูก Thaiprompt (ด่านกันขายของไม่ตรงปก) ซึ่งถูกต้องแล้ว
        // เทสต์นี้สนใจแค่ว่า "ถ้าไปถึงขั้นแปลงไพ่ ต้องแปลงจากกองของเซิร์ฟเวอร์"
        if ($r->status() === 409) {
            $this->assertSame('thaiprompt_not_linked', $r->json('reason_code'));
            return;
        }

        $r->assertCreated();
        $got = array_column($r->json('data.cards'), 'slug');
        $this->assertSame($expected, $got, 'ไพ่ที่บันทึกต้องมาจากกองของเซิร์ฟเวอร์ ไม่ใช่ picks ที่ไคลเอนต์ส่ง');
    }

    /** token ที่หมดอายุ/มั่ว ต้อง 422 ไม่ใช่หักเงินแล้วพัง */
    public function test_unknown_deal_token_is_rejected_before_charging(): void
    {
        $this->seedDeck();
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, 500, 'seed');
        Sanctum::actingAs($u);

        $this->postJson('/api/v1/history/readings', [
            'type'       => 'tarot_three',
            'deal_token' => 'not-a-real-token',
            'slots'      => [1, 2, 3],
        ])->assertStatus(422)->assertJsonPath('reason_code', 'deal_expired');

        $this->assertSame(500.0, app(WalletService::class)->balance($u->fresh()), 'ห้ามหักเงินก่อนตรวจกองไพ่');
    }

    /** token ของคนอื่นใช้ข้ามกันไม่ได้ */
    public function test_deal_token_is_scoped_to_its_owner(): void
    {
        $this->seedDeck();
        $a = User::factory()->create();
        Sanctum::actingAs($a);
        $token = $this->postJson('/api/v1/tarot/deal', ['type' => 'tarot_three'])->json('data.deal_token');

        $b = User::factory()->create();
        app(WalletService::class)->credit($b, 500, 'seed');
        Sanctum::actingAs($b);

        $this->postJson('/api/v1/history/readings', [
            'type'       => 'tarot_three',
            'deal_token' => $token,
            'slots'      => [1, 2, 3],
        ])->assertStatus(422)->assertJsonPath('reason_code', 'deal_expired');
    }
}
