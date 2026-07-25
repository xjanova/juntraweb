<?php

namespace Tests\Feature;

use App\Models\FreeReadingClaim;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\User;
use App\Support\FreeReadingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🎁 ดูดวงฟรี 1 ใบ — ล็อกกติกาที่พลาดแล้วเสียเงิน/เสียลูกค้าไว้:
 *
 *  1. ฟรีได้ครั้งเดียวต่อคนต่อรอบ — กดซ้ำต้องพาไปดูผลเดิม ไม่ใช่แจกใบใหม่
 *     (บทเรียนจากโควตาแชท: เช็คด้วย exists() แล้วค่อย insert = race แจกซ้ำได้)
 *  2. AI ล้ม = **คืนสิทธิ์** ไม่ใช่เผาทิ้ง — ลูกค้าจากบอทมีโอกาสเดียว
 *  3. ห้ามตกไป chat pipeline เวลาอัปสตรีมพัง (เส้นนั้นเปิดห้องแชทใหม่
 *     = ไปกินโควตาคุยฟรีของลูกค้าเองทั้งที่เขาแค่กดปุ่มดูดวงฟรี)
 *  4. ไม่หักวอลเลต และต้องเป็น type ที่หน้าผลลัพธ์เดิมเปิดได้ (ไม่ 404)
 *  5. เลื่อนรอบแจก (batch) = ทุกคนได้ฟรีใหม่ทันทีโดยไม่ต้องแตะข้อมูลลูกค้า
 */
class FreeTarotReadingTest extends TestCase
{
    use RefreshDatabase;

    /** สลับให้อัปสตรีมล่มกลางเทสต์ได้ (Http::fake เรียกซ้ำจะ "merge" ตัวเก่ายังชนะ) */
    private bool $upstreamDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeUpstream();
        $this->seedDeck();

        Setting::put('free_reading_enabled', '1', 'free_reading');
        Setting::put('free_reading_daily_cap', '0', 'free_reading');
        Setting::put('free_reading_max_chars', '500', 'free_reading');
        Setting::put('free_reading_batch', 'v1', 'free_reading');
        Cache::flush();
    }

    /** stub เดียวจบ — สลับสถานะด้วย $this->upstreamDown */
    private function fakeUpstream(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/fortune/tarot/free')) {
                return $this->upstreamDown
                    ? Http::response(['message' => 'ระบบคำทำนายไม่พร้อมชั่วคราว'], 503)
                    : Http::response(['data' => [
                        'interpretation' => 'ไพ่ใบนี้บอกว่าช่วงนี้ของเธอกำลังเปลี่ยนผ่าน ฟันธงว่าจะดีขึ้นภายในเดือนนี้',
                        'next_questions' => ['เรื่องงานจะลงตัวเมื่อไร', 'คนที่เข้ามาคือใคร'],
                        'ai_provider' => 'openai',
                        'ai_model' => 'gpt-test',
                    ]], 200);
            }

            return Http::response([], 200);
        });
    }

    private function seedDeck(): void
    {
        $i = 0;
        foreach (['the-fool' => 'The Fool', 'the-magician' => 'The Magician'] as $slug => $en) {
            TarotCard::create([
                'slug' => $slug,
                'name_en' => $en,
                'name_th' => 'ไพ่ทดสอบ',
                'arcana' => 'major',
                'suit' => 'major',
                'number' => $i++,
                'keywords_th' => 'เริ่มต้น, เปลี่ยนผ่าน',
                'upright_meaning_th' => 'ความหมายตั้งตรง',
                'reversed_meaning_th' => 'ความหมายกลับหัว',
                'active' => true,
            ]);
        }
    }

    private function member(): User
    {
        return User::factory()->create([
            'thaiprompt_token' => 'tok-'.Str::random(8),
        ]);
    }

    public function test_ได้คำทำนายฟรีและไม่หักวอลเลต(): void
    {
        $user = $this->member();

        $res = $this->actingAs($user)->post(route('tarot.free.cast'));

        $reading = Reading::first();
        $this->assertNotNull($reading, 'ต้องมี reading ถูกสร้าง');
        $res->assertRedirect(route('tarot.show', $reading));

        $this->assertSame('tarot_single', $reading->type);
        $this->assertTrue((bool) data_get($reading->payload, 'free'), 'payload ต้องมีธง free');
        $this->assertSame(0, (int) data_get($reading->payload, 'cost'));
        $this->assertNull(data_get($reading->payload, 'wallet_tx_id'));
        $this->assertNotEmpty($reading->result);
        $this->assertCount(1, $reading->tarotCards);
        $this->assertSame(['เรื่องงานจะลงตัวเมื่อไร', 'คนที่เข้ามาคือใคร'], data_get($reading->payload, 'next_questions'));

        // ไม่แตะวอลเลต
        $this->assertDatabaseCount('wallet_transactions', 0);

        // ต้องไม่ไปเปิดห้องแชท (จะกินโควตาคุยฟรีของลูกค้า)
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/mae-mor/'));
    }

    public function test_ฟรีได้ครั้งเดียวต่อคน_กดซ้ำพาไปดูผลเดิม(): void
    {
        $user = $this->member();

        $this->actingAs($user)->post(route('tarot.free.cast'));
        $first = Reading::first();

        $second = $this->actingAs($user)->post(route('tarot.free.cast'));

        $this->assertSame(1, Reading::count(), 'ห้ามแจกไพ่ฟรีใบที่สอง');
        $this->assertSame(1, FreeReadingClaim::count());
        $second->assertRedirect(route('tarot.show', $first->id));
    }

    public function test_เข้าหน้าฟรีซ้ำหลังใช้สิทธิ์แล้ว_เด้งไปผลเดิม(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post(route('tarot.free.cast'));
        $reading = Reading::first();

        $this->actingAs($user)->get(route('tarot.free'))
            ->assertRedirect(route('tarot.show', $reading->id));
    }

    public function test_a_i_ล้มต้องคืนสิทธิ์ฟรีและไม่ทิ้ง_reading_ค้าง(): void
    {
        $this->upstreamDown = true;
        $user = $this->member();

        $res = $this->actingAs($user)->post(route('tarot.free.cast'));

        $res->assertRedirect(route('tarot.free'));
        $res->assertSessionHas('free_retry', true);   // หน้าเว็บต้องไม่ auto-ยิงซ้ำ = กันลูป
        $this->assertSame(0, Reading::count(), 'reading ที่ไม่มีคำทำนายต้องถูกลบ');

        // แถว claim ยังอยู่ (ให้เพดานต้นทุนต่อวันนับได้) แต่ต้องไม่กันสิทธิ์ลูกค้า
        $claim = FreeReadingClaim::first();
        $this->assertNotNull($claim);
        $this->assertNotNull($claim->failed_at, 'ต้องมาร์คว่าล้มเหลว ไม่ใช่ลบทิ้ง');
        $this->assertFalse(FreeReadingPolicy::alreadyUsed($user->fresh()), 'ต้องคืนสิทธิ์ให้ลองใหม่');

        // ลองใหม่แล้วต้องได้จริง (ใช้แถวเดิมซ้ำ ไม่ชน unique)
        $this->upstreamDown = false;
        $this->actingAs($user)->post(route('tarot.free.cast'));
        $this->assertSame(1, Reading::count());
        $this->assertSame(1, FreeReadingClaim::count());
        $this->assertNull(FreeReadingClaim::first()->failed_at);
    }

    public function test_เพดานต่อวันนับครั้งที่ล้มด้วย_เพราะจ่ายค่า_a_i_ไปแล้ว(): void
    {
        Setting::put('free_reading_daily_cap', '1', 'free_reading');
        Cache::flush();

        // คนแรกล้ม — สิทธิ์คืนให้เขา แต่สลอตต้นทุนของวันถูกใช้ไปแล้ว
        $this->upstreamDown = true;
        $this->actingAs($this->member())->post(route('tarot.free.cast'));
        $this->assertSame(1, FreeReadingPolicy::usedToday());

        // คนที่สองต้องโดนเพดานเบรก
        $this->upstreamDown = false;
        $this->actingAs($this->member())->post(route('tarot.free.cast'));
        $this->assertSame(0, Reading::count(), 'เพดานเต็มแล้วต้องไม่เปิดไพ่ให้ใคร');
    }

    public function test_เลื่อนรอบแจกไม่รีเซ็ตเพดานต่อวัน(): void
    {
        Setting::put('free_reading_daily_cap', '1', 'free_reading');
        Cache::flush();

        $this->actingAs($this->member())->post(route('tarot.free.cast'));
        $this->assertSame(1, Reading::count());

        Setting::put('free_reading_batch', 'v2', 'free_reading');
        Cache::flush();

        $this->actingAs($this->member())->post(route('tarot.free.cast'));
        $this->assertSame(1, Reading::count(), 'เลื่อนรอบแจกต้องไม่ล้างเพดานต้นทุนของวัน');
    }

    public function test_token_หมดอายุ_ไม่ยิงอัปสตรีมและไม่กินสิทธิ์(): void
    {
        $user = User::factory()->create(['thaiprompt_token' => null]);

        $res = $this->actingAs($user)->post(route('tarot.free.cast'));

        $res->assertRedirect(route('tarot.free'));
        $this->assertSame(0, Reading::count());
        $this->assertSame(0, FreeReadingClaim::count(), 'ยังไม่ได้จองสลอต = ไม่เสียเพดานของวัน');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/fortune/tarot/free'));
    }

    public function test_เพดานต่อวันเต็มแล้วบล็อก(): void
    {
        Setting::put('free_reading_daily_cap', '1', 'free_reading');
        Cache::flush();

        $this->actingAs($this->member())->post(route('tarot.free.cast'));
        $this->assertSame(1, Reading::count());

        $res = $this->actingAs($this->member())->post(route('tarot.free.cast'));

        $res->assertRedirect(route('tarot.free'));
        $this->assertSame(1, Reading::count(), 'เกินเพดานต่อวันต้องไม่เปิดไพ่เพิ่ม');
    }

    public function test_เลื่อนรอบแจกแล้วทุกคนได้ฟรีใหม่(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post(route('tarot.free.cast'));
        $this->assertSame(1, Reading::count());

        Setting::put('free_reading_batch', 'v2', 'free_reading');
        Cache::flush();

        $this->actingAs($user)->post(route('tarot.free.cast'));

        $this->assertSame(2, Reading::count(), 'รอบใหม่ต้องได้สิทธิ์ใหม่');
        $this->assertSame(2, FreeReadingClaim::count());
    }

    public function test_ปิดระบบแล้วไม่แจก(): void
    {
        Setting::put('free_reading_enabled', '0', 'free_reading');
        Cache::flush();

        $res = $this->actingAs($this->member())->post(route('tarot.free.cast'));

        $res->assertRedirect(route('tarot.free'));
        $this->assertSame(0, Reading::count());
    }

    public function test_แถวเคลมที่ค้างเกินเวลาไม่ยึดสิทธิ์ลูกค้า(): void
    {
        $user = $this->member();

        // จำลองโปรเซสตายกลางคัน: จองสิทธิ์ไว้แต่ไม่มีผล และเก่าเกิน 10 นาที
        FreeReadingClaim::create([
            'user_id' => $user->id,
            'batch' => 'v1',
        ])->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->actingAs($user)->post(route('tarot.free.cast'));

        $this->assertSame(1, Reading::count(), 'แถวค้างต้องไม่บล็อกลูกค้าถาวร');
        $this->assertSame(1, FreeReadingClaim::count(), 'ต้องใช้แถวเดิมซ้ำ ไม่ชน unique');
        $this->assertNotNull(FreeReadingClaim::first()->reading_id);
    }
}
