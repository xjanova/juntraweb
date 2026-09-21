<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\Affiliate\MaeMorAdmin;
use Filament\Pages\Page;

/**
 * 🌙 อัตราค่าแนะนำของบิลจันทรา — ดูอย่างเดียว
 *
 * เจ้าของสั่ง (2026-09-21): % ของบิลจันทราตั้งที่หน้าคอมแม่หมอของ Thaiprompt ที่เดียว
 *   หลังบ้านจันทราแสดงค่าที่ใช้คำนวณอยู่จริงเท่านั้น ไม่มีปุ่มบันทึก
 */
class MaeMorSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'อัตราค่าแนะนำ';

    protected static ?string $title = 'อัตราค่าแนะนำบิลจันทรา (ผังแม่หมอ)';

    protected static ?string $navigationGroup = 'ผังแม่หมอ';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.maemor-settings';

    /** @var array<string, mixed> */
    public array $rates = [];

    public ?string $loadError = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    public function mount(MaeMorAdmin $admin): void
    {
        $res = $admin->settings();
        $this->rates = $res['ok'] ? (array) ($res['data']['data'] ?? []) : [];
        $this->loadError = $res['ok'] ? null : $res['message'];
    }

    /** หน้าคอมแม่หมอของ Thaiprompt — ที่เดียวที่ตั้งอัตราได้ */
    public function thaipromptSettingsUrl(): string
    {
        return rtrim((string) Setting::get('thaiprompt_base_url', 'https://main.thaiprompt.online'), '/')
            .'/admin/fortune/commissions/manage';
    }
}
