<?php

namespace App\Filament\Resources\SmsCheckerDeviceResource\Pages;

use App\Filament\Resources\SmsCheckerDeviceResource;
use App\Models\SmsCheckerDevice;
use Filament\Resources\Pages\CreateRecord;

class CreateSmsCheckerDevice extends CreateRecord
{
    protected static string $resource = SmsCheckerDeviceResource::class;

    /** Mint the device_id + api_key + secret_key server-side on create. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['device_id']  = 'SMSCHK-' . strtoupper(bin2hex(random_bytes(4)));
        $data['api_key']    = SmsCheckerDevice::generateApiKey();
        $data['secret_key'] = SmsCheckerDevice::generateSecretKey();
        $data['platform']   = 'android';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // Back to the list — the operator taps "QR ตั้งค่า" to connect the app.
        return $this->getResource()::getUrl('index');
    }
}
