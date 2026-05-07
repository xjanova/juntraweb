<?php

namespace App\Filament\Resources\TarotCardResource\Pages;

use App\Filament\Resources\TarotCardResource;
use App\Services\TarotImporter;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTarotCards extends ListRecords
{
    protected static string $resource = TarotCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importThaiprompt')
                ->label('Import จาก Thaiprompt')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Import Major Arcana จาก Thaiprompt-Affiliate')
                ->modalDescription('คัดลอกรูป 22 ใบ (Major Arcana) จาก main.thaiprompt.online บนเซิร์ฟเวอร์เดียวกัน เข้ามาเป็นรูปไพ่ของแม่หมอจันทรา. รูปที่อัปโหลดเองจะถูกเขียนทับเฉพาะการ์ด 22 ใบนั้น — Minor Arcana ไม่กระทบ.')
                ->modalSubmitActionLabel('Import เลย')
                ->action(function () {
                    $report = app(TarotImporter::class)->importFromPath();

                    if ($report['imported'] > 0) {
                        Notification::make()
                            ->title('Import สำเร็จ')
                            ->body("นำเข้า {$report['imported']} ใบ — อัปเดต DB {$report['updated']} แถว"
                                . ($report['skipped_missing'] ? " · ข้าม {$report['skipped_missing']} ใบ (ไฟล์ไม่พบ)" : ''))
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Import ไม่สำเร็จ')
                            ->body($report['errors'][0] ?? 'ไม่มีไฟล์ใดถูกนำเข้า — ตรวจ source path ใน .env (TAROT_IMPORT_SOURCE)')
                            ->danger()
                            ->send();
                    }
                }),

            Actions\CreateAction::make(),
        ];
    }
}
