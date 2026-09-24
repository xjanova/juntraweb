<?php

namespace Tests\Feature;

use App\Models\ContentReport;
use App\Models\Setting;
use App\Models\User;
use App\Services\GooglePlay\GooglePlayBilling;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * หลังบ้านของของใหม่ฝั่งแอพ: คิว "รายงานเนื้อหา AI" และส่วนตั้งค่าเติมเครดิตผ่าน Google Play
 * (หน้าพังจาก query/คอลัมน์ผิดจะโผล่เป็น 500 ที่นี่)
 */
class AdminGooglePlayAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_content_report_queue_renders_and_resolves(): void
    {
        $member = User::factory()->create();
        $report = ContentReport::create([
            'user_id' => $member->id, 'subject_type' => 'reading', 'subject_id' => 5,
            'reason' => 'offensive', 'snapshot' => 'ข้อความที่ถูกรายงาน', 'status' => 'open',
        ]);

        $this->actingAs($this->admin())->get('/admin/content-reports')->assertOk()->assertSee('ข้อความที่ถูกรายงาน');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(\App\Filament\Resources\ContentReportResource\Pages\ListContentReports::class)
            ->callTableAction('resolve', $report);
        $this->assertSame('resolved', $report->fresh()->status);
    }

    public function test_wallet_settings_saves_the_google_play_packs(): void
    {
        $this->actingAs($this->admin())->get('/admin/wallet-settings')->assertOk()->assertSee('Google Play');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(\App\Filament\Pages\WalletSettings::class)
            ->set('data.google_play_billing_enabled', false)
            ->set('data.google_play_products', ['juntra_credits_60' => '60', 'BAD ID!' => '10', 'juntra_zero' => '0'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0', Setting::get('google_play_billing_enabled'));
        $this->assertSame(['juntra_credits_60' => 60.0], app(GooglePlayBilling::class)->products(), 'แถวที่ผิดต้องถูกทิ้ง');
    }
}
