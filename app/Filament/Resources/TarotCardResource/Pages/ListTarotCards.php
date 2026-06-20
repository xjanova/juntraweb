<?php

namespace App\Filament\Resources\TarotCardResource\Pages;

use App\Filament\Resources\TarotCardResource;
use App\Services\TarotImporter;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTarotCards extends ListRecords
{
    protected static string $resource = TarotCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importLocal')
                ->label('Import 78 ใบ จากเครื่องนี้')
                ->icon('heroicon-o-folder-arrow-down')
                ->color('success')
                ->form([
                    TextInput::make('rootDir')
                        ->label('Path บนเครื่อง server')
                        ->default(config('tarot.local_import_path') ?: '')
                        ->placeholder('เช่น /home/admin/tarot-art หรือ E:/จันทราพยากรณ์')
                        ->required()
                        ->helperText('ภายในต้องมีโฟลเดอร์ Major/0.png … 21.png และ Cups/Wands/Swords/Pentacles/{ace,2,3,…,king}.png — ระบบจะอ่านอัตโนมัติทั้ง 78 ใบ. ตั้งค่า default ที่ TAROT_LOCAL_IMPORT_PATH ใน .env ของแต่ละ env ได้'),
                ])
                ->modalHeading('Import ภาพไพ่ทั้ง 78 ใบ จาก local path')
                ->modalDescription('ระบบจะสแกน Major/<number>.png และ <Suit>/<rank>.png แล้วคัดลอกเข้า public/images/tarot — รูปที่ admin upload เองจะถูกเขียนทับเฉพาะการ์ดที่หาไฟล์ต้นทางเจอเท่านั้น')
                ->modalSubmitActionLabel('Import')
                ->action(function (array $data) {
                    $report = app(TarotImporter::class)->importFromLocalPath($data['rootDir']);

                    if ($report['imported'] > 0) {
                        $major = $report['by_arcana']['major'] ?? 0;
                        $minor = $report['by_arcana']['minor'] ?? 0;
                        Notification::make()
                            ->title("Import สำเร็จ {$report['imported']} ใบ")
                            ->body("Major: {$major}/22 · Minor: {$minor}/56"
                                . ($report['skipped_missing'] ? " · ขาดไฟล์ {$report['skipped_missing']} ใบ" : ''))
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Import ไม่สำเร็จ')
                            ->body($report['errors'][0] ?? 'ไม่พบไฟล์ใดในโฟลเดอร์นี้ — ตรวจสอบ path อีกครั้ง')
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('importThaiprompt')
                ->label('Import จาก Thaiprompt')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Import ไพ่ทั้ง 78 ใบ จาก Thaiprompt-Affiliate')
                ->modalDescription('ดึงรายชื่อรูปปัจจุบันสด ๆ ผ่าน API ของ main.thaiprompt.online (จับคู่ด้วยชื่อไพ่) แล้วคัดลอกรูปทั้ง 78 ใบเข้ามาเป็นรูปไพ่ของแม่หมอจันทรา — ทนต่อการที่ Thaiprompt เปลี่ยนชื่อไฟล์. รูปที่อัปโหลดเองจะถูกเขียนทับเฉพาะใบที่ดึงสำเร็จ.')
                ->modalSubmitActionLabel('Import เลย')
                ->action(function () {
                    $report   = app(TarotImporter::class)->importFromPath();
                    $imported = $report['imported'];
                    $expected = $report['remote_total'] ?: $imported;
                    $errCount = count($report['errors']);

                    // First few error lines so the admin sees WHICH cards failed and why
                    // (parity with `php artisan tarot:import`) — no SSH to laravel.log needed.
                    $detail = collect($report['errors'])->take(5)->implode("\n");
                    if ($errCount > 5) {
                        $detail .= "\n… และอีก " . ($errCount - 5) . " รายการ";
                    }

                    if ($imported === 0) {
                        Notification::make()
                            ->title('Import ไม่สำเร็จ')
                            ->body($detail ?: 'ไม่มีไฟล์ใดถูกนำเข้า — ตรวจ URL ของ Thaiprompt (ตั้งค่า) และ endpoint /api/v1/juntra/tarot/cards')
                            ->danger()->persistent()->send();
                    } elseif ($errCount > 0 || $imported < $expected) {
                        // Imported some, but not a clean full run → warn, don't show green.
                        Notification::make()
                            ->title("Import บางส่วน: {$imported}/{$expected} ใบ")
                            ->body("อัปเดต DB {$report['updated']} แถว"
                                . ($report['skipped_missing'] ? " · ข้าม {$report['skipped_missing']} ใบ" : '')
                                . ($detail ? "\n" . $detail : ''))
                            ->warning()->persistent()->send();
                    } else {
                        Notification::make()
                            ->title("Import สำเร็จ {$imported}/{$expected} ใบ")
                            ->body("อัปเดต DB {$report['updated']} แถว")
                            ->success()->send();
                    }
                }),

            Actions\Action::make('importCardBack')
                ->label('Import หลังไพ่ จาก Thaiprompt')
                ->icon('heroicon-o-photo')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Import หลังไพ่ (Card Back) จาก Thaiprompt-Affiliate')
                ->modalDescription('คัดลอกรูปหลังไพ่ใหม่ที่สุดจาก Thaiprompt มาใช้เป็นหลังไพ่บนหน้าเว็บ — ตอน user เลือกไพ่จะเห็นรูปหลังนี้แทน SVG default.')
                ->modalSubmitActionLabel('Import หลังไพ่')
                ->action(function () {
                    $report = app(TarotImporter::class)->importCardBack();
                    if ($report['imported']) {
                        Notification::make()
                            ->title('Import หลังไพ่สำเร็จ')
                            ->body("บันทึกที่ {$report['path']} — รีเฟรชหน้า /tarot/pick เพื่อดูผล")
                            ->success()->send();
                    } else {
                        Notification::make()
                            ->title('Import ไม่สำเร็จ')
                            ->body($report['error'] ?? 'unknown error')
                            ->danger()->send();
                    }
                }),

            Actions\CreateAction::make(),
        ];
    }
}
