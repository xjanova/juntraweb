<?php

namespace App\Console\Commands;

use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\WalletTransaction;
use App\Services\Push\PushNotifier;
use Illuminate\Console\Command;

/**
 * 🧾 เตือนบิลเติมเงินที่ยังไม่โอน (ยอดค้าง) ผ่าน web push — ครั้งเดียวต่อบิล
 *
 * เลือกบิลที่:
 *   - topup + pending + ยังไม่แนบสลิป (แนบแล้ว = จ่ายแล้วรอแอดมิน ห้ามทวง)
 *   - สร้างมาแล้ว ≥ 30 นาที (ให้เวลาโอนก่อน) แต่ไม่เกิน 24 ชม. (บิลเก่ากว่านั้นไม่ทวงแล้ว)
 *   - ยังไม่หมดอายุ และเหลือเวลาอีกอย่างน้อย 10 นาที (ทวงแล้วกดเข้าไปเจอบิลหมดอายุ = แย่กว่าไม่ทวง)
 *   - เจ้าของบิลเปิดแจ้งเตือนไว้อย่างน้อยหนึ่งเครื่อง
 * ช่วงเวลาเงียบ = ข้ามรอบนี้ไปทั้งรอบ (รอบหลังเวลาเงียบจะหยิบบิลที่ยังค้างขึ้นมาเอง)
 */
class PushRemindBillsCommand extends Command
{
    protected $signature = 'push:remind-bills';

    protected $description = 'Send one gentle web push per unpaid top-up bill (after 30 min, outside quiet hours)';

    public const WAIT_MINUTES = 30;

    public function handle(PushNotifier $notifier): int
    {
        if (! PushNotifier::ready() || ! PushNotifier::typeEnabled('bill') || PushNotifier::isQuiet()) {
            return self::SUCCESS;
        }

        $sent = 0;
        // บิลในหน้าต่าง 24 ชม. ของคนที่เปิดแจ้งเตือน มีไม่กี่ใบ — ดึงมาแล้วตัดใบที่ทวงไปแล้วในหน่วยความจำ
        // (ไม่ต่อสตริง 'topup:'||id ใน SQL เพราะ MySQL บน prod ตีความ || เป็น OR)
        WalletTransaction::query()
            ->where('type', 'topup')
            ->where('status', 'pending')
            ->whereNull('slip_path')
            ->whereBetween('created_at', [now()->subDay(), now()->subMinutes(self::WAIT_MINUTES)])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->addMinutes(10)))
            ->whereIn('user_id', PushSubscription::select('user_id'))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($notifier, &$sent) {
                $done = PushDelivery::where('type', 'bill')
                    ->whereIn('ref', $rows->map(fn ($tx) => 'topup:'.$tx->id))
                    ->pluck('ref')->flip();
                foreach ($rows as $tx) {
                    if (! isset($done['topup:'.$tx->id]) && $notifier->billReminder($tx) === 'sent') {
                        $sent++;
                    }
                }
            });

        $this->info("Bill reminders sent: $sent");

        return self::SUCCESS;
    }
}
