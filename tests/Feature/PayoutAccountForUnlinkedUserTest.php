<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\PayoutAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ลูกค้าที่สมัครด้วยเบอร์/อีเมล (ไม่เคย SSO ผ่านแม่หมอ = ไม่มี thaiprompt_token)
 * ต้องเติมเงินได้
 *
 * 🔴 บั๊กที่กันไว้ (ยืนยันบน prod 2026-07-26): PayoutAccount::resolve() ยิง
 * upstream ด้วย token ของ "คนที่กำลังเปิดหน้าอยู่" คนที่ไม่มี token จึงได้ null
 * เสมอ และ promptpay_id ในเว็บก็ว่าง → /wallet/topup ขึ้น "แอดมินยังไม่ได้ตั้งค่า
 * PromptPay" = ลูกค้าจ่ายเงินไม่ได้เลย ตอนนั้นรอดอยู่เพราะสมาชิกเกือบทั้งหมด
 * มาจาก SSO แต่การสมัครด้วยเบอร์/อีเมลเพิ่งเปิด (21437f5) พอคนสมัครทางนั้นมากขึ้น
 * ก็จะเจอกันทุกคน
 *
 * เงื่อนไขที่ล็อกไว้: ห้ามแก้ด้วยการกลับไปตั้งเลขพร้อมเพย์แยกที่เว็บ เพราะจะหลุด
 * จากบัญชีที่ SlipOK/SMS ผูกไว้ (เหตุผลเต็มอยู่ใน App\Support\PayoutAccount)
 */
class PayoutAccountForUnlinkedUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // กันเทสต์ยิงเน็ตจริง — เส้นทางนี้เรียก upstream ทุกครั้งที่ cache หมดอายุ
        Http::preventStrayRequests();

        // เทสต์นี้เรนเดอร์หน้าจริง แต่ /public/build อยู่ใน .gitignore — อย่าให้
        // ผลเทสต์ขึ้นกับว่าเครื่องนั้นรัน `npm run build` มาแล้วหรือยัง
        $this->withoutVite();

        // สภาพจริงบนโปรดักชัน: เว็บไม่มีเลขพร้อมเพย์ของตัวเองแล้ว (เลิกตั้งซ้ำ
        // ตั้งแต่ 2026-07-25) ถ้าไม่ล้างค่าตรงนี้ เทสต์จะผ่านด้วย fallback ท้องถิ่น
        // ทั้งที่ทางหลักยังพัง
        Setting::put('promptpay_id', '', 'pricing', false);
        Setting::put('promptpay_name', '', 'pricing', false);
        config(['pricing.promptpay_id' => '', 'pricing.promptpay_name' => '']);
        Cache::flush();
    }

    private function fakeMaemorAccount(): void
    {
        Http::fake(['*/juntra/payment/account' => Http::response(['data' => [
            'promptpay_id' => '0811111111',
            'account_name' => 'แม่หมอจันทรา',
            'bank_name'    => 'กสิกรไทย',
        ]], 200)]);
    }

    /** มีคนผูกบัญชีแม่หมอไว้แล้ว → คนที่ไม่ได้ผูกต้องได้ QR ของบัญชีเดียวกัน */
    public function test_unlinked_user_gets_the_maemor_promptpay_on_the_topup_page(): void
    {
        $this->fakeMaemorAccount();
        User::factory()->create([
            'role'             => 'admin',
            'thaiprompt_token' => 'tok-'.Str::random(6),
        ]);

        $unlinked = User::factory()->create(['thaiprompt_token' => null]);
        $this->assertFalse($unlinked->isThaipromptUsable());

        $this->actingAs($unlinked)
            ->get('/wallet/topup')
            ->assertOk()
            ->assertSee('0811111111')
            ->assertSee('แม่หมอจันทรา')
            ->assertDontSee('แอดมินยังไม่ได้ตั้งค่า PromptPay');
    }

    /** ค่าที่ดึงมาได้ต้องถูกเก็บไว้ใช้ต่อ ไม่ใช่หายไปกับ cache */
    public function test_snapshot_keeps_topup_working_after_the_last_linked_account_is_gone(): void
    {
        $this->fakeMaemorAccount();
        $linked = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);

        // ครั้งแรก: ดึงจาก upstream ได้ แล้วเก็บสำเนาไว้
        $this->assertSame('maemor', PayoutAccount::resolve($linked)['source']);

        // token ตายหมดทั้งระบบ + cache หมดอายุ = ไม่มีใครถาม upstream ได้อีก
        $linked->forceFill(['thaiprompt_token' => null])->saveQuietly();
        Cache::flush();

        $unlinked = User::factory()->create(['thaiprompt_token' => null]);
        $payout = PayoutAccount::resolve($unlinked);

        $this->assertSame('snapshot', $payout['source']);
        $this->assertSame('0811111111', $payout['promptpay_id']);

        $this->actingAs($unlinked)
            ->get('/wallet/topup')
            ->assertOk()
            ->assertSee('0811111111')
            ->assertDontSee('แอดมินยังไม่ได้ตั้งค่า PromptPay');
    }

    /** สลับบัญชีในหลังบ้านบอท → สำเนาต้องตามไปด้วย ไม่ค้างใบเก่า */
    public function test_snapshot_follows_the_account_the_admin_switches_to(): void
    {
        // ครั้งแรกได้บัญชีเดิม ครั้งที่สองคือหลังแอดมินติ๊กบัญชีใหม่ในหลังบ้านบอท
        Http::fake(['*/juntra/payment/account' => Http::sequence()
            ->push(['data' => ['promptpay_id' => '0811111111', 'account_name' => 'แม่หมอจันทรา']], 200)
            ->push(['data' => ['promptpay_id' => '0822222222', 'account_name' => 'บัญชีใหม่']], 200)]);

        $linked = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
        PayoutAccount::resolve($linked);

        Cache::flush();
        PayoutAccount::resolve($linked);

        // upstream เงียบไปแล้ว — ที่เหลือต้องเป็นใบใหม่ ไม่ใช่ 0811111111
        $linked->forceFill(['thaiprompt_token' => null])->saveQuietly();
        Cache::flush();

        $payout = PayoutAccount::resolve(User::factory()->create());
        $this->assertSame('0822222222', $payout['promptpay_id']);
        $this->assertSame('บัญชีใหม่', $payout['name']);
    }

    /** ไม่มีทั้ง token, สำเนา และค่าท้องถิ่น = ต้องบอกลูกค้าตรง ๆ ไม่ใช่ QR ว่าง */
    public function test_still_reports_unset_when_nothing_is_configured_anywhere(): void
    {
        $this->assertNull(PayoutAccount::resolve(User::factory()->create()));
    }

    /**
     * upstream ล่ม: ห้ามยิงซ้ำทุก request ไม่งั้นหน้าเติมเงินค้างรอ timeout
     * ทุกครั้งที่เปิด (ตอนนี้กระทบ "ทุกคน" เพราะคนที่ไม่ได้ผูกก็ยืม token ไปถาม)
     */
    public function test_a_failing_upstream_is_only_asked_once_per_cache_window(): void
    {
        Http::fake(['*/juntra/payment/account' => Http::response([], 503)]);
        Setting::put('promptpay_id', '0899999999', 'pricing', false);
        Cache::flush();

        User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
        $unlinked = User::factory()->create(['thaiprompt_token' => null]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('0899999999', PayoutAccount::resolve($unlinked)['promptpay_id']);
        }

        Http::assertSentCount(1);
    }
}
