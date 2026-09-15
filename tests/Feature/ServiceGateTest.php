<?php

namespace Tests\Feature;

use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ปิดขายชั่วคราว (2026-09-15) — เลขศาสตร์/ฤกษ์ยามให้ผลไม่ตรงตำรา เจ้าของสั่งปิดจนกว่าจะแก้เสร็จ
 * ปิดแล้วต้องไม่คำนวณ ไม่หักเงิน ทั้งเว็บและแอพ และหน้าเว็บต้องบอกเหตุผล (ไม่ใช่ฟอร์มที่กดแล้วเงียบ)
 */
class ServiceGateTest extends TestCase
{
    use RefreshDatabase;

    private function close(string $svc): void
    {
        Setting::put("service_{$svc}_closed", '1', 'service');
        Setting::put('pricing_numerology', '9', 'pricing');
        Setting::put('pricing_auspicious', '19', 'pricing');
        Cache::flush();
    }

    private function member(): User
    {
        $u = User::factory()->create();
        app(WalletService::class)->credit($u, 100, 'seed');

        return $u;
    }

    public function test_closed_numerology_neither_calculates_nor_charges_on_web_or_app(): void
    {
        $this->close('numerology');
        $user = $this->member();

        $this->actingAs($user)->get(route('numerology.index'))
            ->assertOk()->assertSee('ปิดปรับปรุงชั่วคราว')->assertDontSee(route('numerology.calculate'));

        $this->actingAs($user)->post(route('numerology.calculate'), ['name' => 'สมชาย ใจดี', 'birth_date' => '1990-01-01'])
            ->assertRedirect(route('numerology.index'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'ยังไม่มีการหักเครดิต'));

        Sanctum::actingAs($user);
        $this->postJson(route('api.v1.fortune.numerology'), ['name' => 'สมชาย ใจดี', 'birth_date' => '1990-01-01'])
            ->assertStatus(503)->assertJsonPath('reason_code', 'service_closed');

        $this->assertSame(0, Reading::count());
        $this->assertSame(100.0, (float) app(WalletService::class)->balance($user->fresh()));
    }

    public function test_closed_auspicious_hides_the_form_and_the_scored_preview_but_keeps_the_verified_yam_table(): void
    {
        $this->close('auspicious');
        $user = $this->member();

        $this->actingAs($user)->get(route('auspicious.index'))
            ->assertOk()
            ->assertSee('ปิดปรับปรุงชั่วคราว')
            ->assertDontSee(route('auspicious.find'))
            ->assertDontSee('ฤกษ์ 14 วันข้างหน้า');

        $this->actingAs($user)->post(route('auspicious.find'), ['occasion' => 'แต่งงาน', 'occasion_type' => 'wedding'])
            ->assertRedirect(route('auspicious.index'));

        Sanctum::actingAs($user);
        $this->postJson(route('api.v1.fortune.auspicious'), ['occasion' => 'แต่งงาน'])
            ->assertStatus(503)->assertJsonPath('reason_code', 'service_closed');

        $this->assertSame(0, Reading::count());
        $this->assertSame(100.0, (float) app(WalletService::class)->balance($user->fresh()));
    }

    public function test_services_are_open_unless_an_admin_closes_them(): void
    {
        $this->actingAs($this->member())->get(route('numerology.index'))
            ->assertOk()->assertDontSee('ปิดปรับปรุงชั่วคราว');
    }
}
