<?php

namespace App\Filament\Resources\SmsCheckerDeviceResource\Pages;

use App\Filament\Resources\SmsCheckerDeviceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSmsCheckerDevices extends ListRecords
{
    protected static string $resource = SmsCheckerDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('เพิ่มอุปกรณ์'),
        ];
    }
}
