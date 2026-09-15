<?php

namespace App\Filament\Resources\ReadingResource\Pages;

use App\Filament\Resources\ReadingResource;
use App\Filament\Resources\ReadingResource\Widgets\ReadingBillStats;
use App\Models\Reading;
use App\Support\ReadingBill;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReadings extends ListRecords
{
    protected static string $resource = ReadingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ReadingBillStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            // ส่งออกตามตัวกรองที่เลือกอยู่ (เหมือนปุ่มส่งออกรายได้ของ Thaiprompt) — เปิดใน Excel ได้ (UTF-8 BOM)
            Actions\Action::make('export')->label('ส่งออก CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(function () {
                    $rows = $this->getFilteredSortedTableQuery()->with('user')->limit(10000)->get();
                    $name = 'bills-'.now('Asia/Bangkok')->format('Ymd-His').'.csv';

                    return response()->streamDownload(function () use ($rows) {
                        $out = fopen('php://output', 'w');
                        fwrite($out, "\xEF\xBB\xBF");
                        fputcsv($out, ['เลขบิล', 'วันที่', 'ลูกค้า', 'อีเมล/เบอร์', 'แพ็กเกจ', 'ยอด', 'สถานะ', 'ช่องทาง', 'โมเดล AI', 'คำถาม']);
                        foreach ($rows as $r) {
                            /** @var Reading $r */
                            fputcsv($out, [
                                ReadingBill::number($r),
                                optional($r->created_at)->timezone('Asia/Bangkok')->format('Y-m-d H:i'),
                                $r->user?->name,
                                $r->user?->email ?: $r->user?->phone,
                                ReadingBill::package($r),
                                number_format(ReadingBill::amount($r), 2, '.', ''),
                                ReadingBill::statusLabel($r),
                                ReadingBill::channel($r),
                                $r->ai_model,
                                $r->question,
                            ]);
                        }
                        fclose($out);
                    }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }
}
