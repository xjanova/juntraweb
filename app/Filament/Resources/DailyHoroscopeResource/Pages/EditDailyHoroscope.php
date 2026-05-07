<?php

namespace App\Filament\Resources\DailyHoroscopeResource\Pages;

use App\Filament\Resources\DailyHoroscopeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDailyHoroscope extends EditRecord
{
    protected static string $resource = DailyHoroscopeResource::class;
    protected function getHeaderActions(): array { return [Actions\DeleteAction::make()]; }
}
