<?php

namespace App\Filament\Pages;

use App\Services\ThemeManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Themes extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';
    protected static ?string $navigationLabel = 'ธีมเว็บไซต์';
    protected static ?string $title = 'ธีมหน้าบ้าน';
    protected static ?string $navigationGroup = 'ระบบ';
    protected static ?int $navigationSort = 90;
    protected static string $view = 'filament.pages.themes';

    public string $active = '';

    public function mount(ThemeManager $themes): void
    {
        $this->active = $themes->active();
    }

    public function switchTheme(string $slug): void
    {
        $themes = app(ThemeManager::class);
        if ($themes->switch($slug)) {
            $this->active = $slug;
            Notification::make()
                ->title('สลับธีมแล้ว')
                ->body('ธีม "' . $themes->config()['name'] . '" ถูกใช้งานแล้ว — เปิดหน้าบ้านในแท็บใหม่เพื่อดูผล')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('ไม่พบธีมที่เลือก')
                ->danger()
                ->send();
        }
    }

    public function getThemes(): array
    {
        return config('themes.themes', []);
    }
}
