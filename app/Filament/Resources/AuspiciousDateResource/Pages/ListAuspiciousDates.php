<?php

namespace App\Filament\Resources\AuspiciousDateResource\Pages;

use App\Filament\Resources\AuspiciousDateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAuspiciousDates extends ListRecords
{
    protected static string $resource = AuspiciousDateResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
