<?php

namespace App\Console\Commands;

use App\Models\SmsCheckerDevice;
use Illuminate\Console\Command;

/**
 * Mint a new SMS Checker device (api_key + secret_key) and print the QR config
 * the Android app scans to connect. The secret is shown ONCE here.
 */
class SmsCheckerCreateDevice extends Command
{
    protected $signature = 'smschecker:create-device {name? : friendly device name}';

    protected $description = 'Create an SMS Checker device and print its setup config';

    public function handle(): int
    {
        $device = SmsCheckerDevice::create([
            'device_id'     => 'SMSCHK-' . strtoupper(bin2hex(random_bytes(4))),
            'device_name'   => $this->argument('name') ?: 'SMS Checker',
            'api_key'       => SmsCheckerDevice::generateApiKey(),
            'secret_key'    => SmsCheckerDevice::generateSecretKey(),
            'platform'      => 'android',
            'status'        => 'active',
            'approval_mode' => config('smschecker.default_approval_mode', 'auto'),
        ]);

        $config = [
            'type'       => 'smschecker_config',
            'version'    => 1,
            'url'        => rtrim((string) config('app.url'), '/'),
            'apiKey'     => $device->api_key,
            'secretKey'  => $device->secret_key,
            'deviceName' => $device->device_name,
        ];

        $this->info('SMS Checker device created:');
        $this->line('  device_id  : ' . $device->device_id);
        $this->line('  api_key    : ' . $device->api_key);
        $this->line('  secret_key : ' . $device->secret_key);
        $this->newLine();
        $this->comment('Scan this in the app (or open /admin → SMS Checker → QR):');
        $this->line(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
