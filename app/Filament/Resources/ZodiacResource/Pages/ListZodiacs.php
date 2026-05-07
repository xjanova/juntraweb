<?php

namespace App\Filament\Resources\ZodiacResource\Pages;

use App\Filament\Resources\ZodiacResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListZodiacs extends ListRecords
{
    protected static string $resource = ZodiacResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
