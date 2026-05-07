<?php

namespace App\Filament\Resources\TarotCardResource\Pages;

use App\Filament\Resources\TarotCardResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTarotCards extends ListRecords
{
    protected static string $resource = TarotCardResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
