<?php

namespace App\Filament\Resources\WalletAdminAdjustmentResource\Pages;

use App\Filament\Resources\WalletAdminAdjustmentResource;
use Filament\Resources\Pages\ListRecords;

class ListWalletAdminAdjustments extends ListRecords
{
    protected static string $resource = WalletAdminAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
