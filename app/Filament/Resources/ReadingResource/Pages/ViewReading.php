<?php

namespace App\Filament\Resources\ReadingResource\Pages;

use App\Filament\Resources\ReadingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReading extends ViewRecord
{
    protected static string $resource = ReadingResource::class;

    /** ปุ่มจัดการบิลชุดเดียวกับในตาราง — เปิดหน้าที่ลูกค้าเห็น / คืนเงิน / ทำนายใหม่ */
    protected function getHeaderActions(): array
    {
        return ReadingResource::billActions(header: true);
    }
}
