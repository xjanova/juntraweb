<?php

namespace App\Filament\Resources\AffiliateBillResource\Pages;

use App\Filament\Resources\AffiliateBillResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListAffiliateBills extends ListRecords
{
    protected static string $resource = AffiliateBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ไม่ต้องรอรอบนาที — ใช้หลังกดส่งซ้ำ หรือหลังแก้การตั้งค่า Thaiprompt
            Action::make('syncNow')
                ->label('ส่งตอนนี้')
                ->icon('heroicon-o-paper-airplane')
                ->action(function () {
                    // รันในคำขอหน้าเว็บ — ทีละน้อยพอ (รอบอัตโนมัติทุกนาทีเก็บที่เหลือต่อ)
                    Artisan::call('affiliate:sync-bills', ['--limit' => 20]);
                    Notification::make()->title('ส่งรอบนี้แล้ว')->body(trim(Artisan::output()))->success()->send();
                }),
        ];
    }
}
