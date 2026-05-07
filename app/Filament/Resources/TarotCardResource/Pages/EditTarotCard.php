<?php

namespace App\Filament\Resources\TarotCardResource\Pages;

use App\Filament\Resources\TarotCardResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTarotCard extends EditRecord
{
    protected static string $resource = TarotCardResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
