<?php

namespace App\Filament\Resources\WalletAdminAdjustmentResource\Pages;

use App\Filament\Resources\WalletAdminAdjustmentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWalletAdminAdjustment extends ViewRecord
{
    protected static string $resource = WalletAdminAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
