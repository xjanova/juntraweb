<?php

namespace App\Console\Commands;

use App\Services\Push\Vapid;
use App\Support\EnvWriter;
use Illuminate\Console\Command;

/**
 * 🔑 สร้างกุญแจ VAPID สำหรับ web push
 *
 *   php artisan webpush:vapid            แสดงคู่กุญแจใหม่ (คัดลอกไปใส่ .env เอง)
 *   php artisan webpush:vapid --write    เขียนลง .env ให้เลย — ไม่ทับกุญแจเดิม ถ้าไม่ใส่ --force
 *
 * เปลี่ยนกุญแจ = ทุกเครื่องที่เปิดแจ้งเตือนไว้ต้องกดเปิดใหม่ (subscription ผูกกับกุญแจสาธารณะ)
 */
class WebPushVapidCommand extends Command
{
    protected $signature = 'webpush:vapid {--write : write VAPID_* into .env} {--force : replace keys already in .env}';

    protected $description = 'Generate a VAPID key pair for web push notifications';

    public function handle(): int
    {
        if (Vapid::configured() && ! $this->option('force')) {
            $this->warn('มีกุญแจ VAPID ที่ใช้ได้อยู่แล้ว — เปลี่ยนแล้วทุกเครื่องต้องเปิดแจ้งเตือนใหม่ (ใส่ --force ถ้าตั้งใจ)');

            return self::FAILURE;
        }

        $keys = Vapid::generateKeys();

        if (! $this->option('write')) {
            $this->line('VAPID_PUBLIC_KEY='.$keys['public']);
            $this->line('VAPID_PRIVATE_KEY='.$keys['private']);
            $this->comment('ใส่สองบรรทัดนี้ใน .env แล้วรัน php artisan config:clear — ห้าม commit กุญแจลับ');

            return self::SUCCESS;
        }

        EnvWriter::set([
            'VAPID_PUBLIC_KEY' => $keys['public'],
            'VAPID_PRIVATE_KEY' => $keys['private'],
        ]);
        $this->info('เขียนกุญแจ VAPID ลง .env แล้ว (กุญแจสาธารณะ: '.$keys['public'].')');
        $this->comment('รัน php artisan config:clear (หรือ config:cache) ให้ค่าใหม่มีผล');

        return self::SUCCESS;
    }
}
