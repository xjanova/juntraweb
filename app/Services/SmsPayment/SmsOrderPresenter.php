<?php

namespace App\Services\SmsPayment;

use App\Models\SmsPaymentNotification;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;

/**
 * แปลงรายการเติมเงิน (wallet_transactions type=topup) → รูปแบบ RemoteOrderApproval
 * ที่แอพ SMS Checker อ่าน (PaymentApiService.kt:224-248, toLocalEntity ใน OrderRepository.kt)
 *
 * ต้องตรงกับที่ Thaiprompt ส่ง (transformToOrderApproval) ทุกคีย์ — แอพเก็บบิลของทุกเว็บ
 * ไว้ในตารางเดียว ต่างกันแค่ serverId ข้อสังเกตที่ห้ามพลาด:
 *   - approved_by ต้องเป็น null เสมอ: แอพถือว่าค่าที่ไม่ใช่ null คือ "แอดมินกดเองในแอพ"
 *   - order_details_json.amount ต้องเป็นตัวเลข (Double) ไม่ใช่ string — แอพจับคู่แบบ exact
 *   - notification.amount เป็น string ทศนิยม 2 ตำแหน่งเสมอ (เช่น "100.37")
 *   - server_name / website_name = ชื่อที่ทำให้แอพรู้ว่าเงินก้อนนี้เป็นของเว็บไหน
 */
class SmsOrderPresenter
{
    public const PRODUCT_NAME = 'เติมเครดิตวอลเลต';

    public static function siteName(): string
    {
        $label = trim((string) config('smschecker.site_label', ''));

        return $label !== '' ? $label : 'จันทรา.online';
    }

    /**
     * @param  SmsPaymentNotification|null  $notification  SMS ที่จับคู่กับรายการนี้ (ถ้ารู้แล้ว)
     * @param  bool  $lookup  false = ผู้เรียกโหลดมาให้แล้ว (null แปลว่าไม่มีจริง ไม่ต้องคิวรีซ้ำ)
     */
    public function present(WalletTransaction $tx, ?SmsPaymentNotification $notification = null, bool $lookup = true): array
    {
        if ($notification === null && $lookup && $tx->exists) {
            $notification = SmsPaymentNotification::where('matched_transaction_id', $tx->id)
                ->orderByDesc('id')->first();
        }

        $meta     = (array) $tx->meta;
        $payable  = abs((float) $tx->amount);
        $customer = $tx->user?->name ?: ('ผู้ใช้ #' . $tx->user_id);
        $status   = $this->approvalStatus($tx);
        $site     = self::siteName();

        [$cancelReason, $cancelLabel] = match ($status) {
            'cancelled' => ['user_cancelled', 'ยกเลิกโดยลูกค้า'],
            'expired'   => ['auto_expired', 'ยกเลิกโดยระบบ (หมดเวลาชำระ)'],
            default     => [null, null],
        };

        $base = isset($meta['base_amount']) ? (float) $meta['base_amount'] : $payable;

        return [
            'id'                        => (int) $tx->id,
            'notification_id'           => $notification?->id,
            'matched_transaction_id'    => (int) $tx->id,
            'device_id'                 => $notification?->device_id,
            'approval_status'           => $status,
            'cancellation_reason'       => $cancelReason,
            'cancellation_reason_label' => $cancelLabel,
            'confidence'                => $notification ? 'high' : 'medium',
            'approved_by'               => null,
            'approved_at'               => in_array($status, ['auto_approved', 'manually_approved'], true)
                ? $tx->approved_at?->toIso8601String() : null,
            'rejected_at'               => $status === 'rejected'
                ? ($tx->approved_at ?? $tx->updated_at)?->toIso8601String() : null,
            'rejection_reason'          => $status === 'rejected' ? ($meta['reject_reason'] ?? null) : null,
            'order_details_json'        => [
                'order_number'    => $tx->reference_code,
                'product_name'    => self::PRODUCT_NAME,
                'product_details' => $customer . ' · ฐาน ' . number_format($base, 2) . ' บาท',
                'quantity'        => 1,
                'website_name'    => $site,
                'customer_name'   => $customer,
                'amount'          => $payable,
                'base_amount'     => $base,
                'platform'        => null,
                'can_void'        => false,
            ],
            'server_name'               => $site,
            'synced_version'            => $tx->updated_at ? (int) ($tx->updated_at->getTimestamp() * 1000) : 0,
            'created_at'                => $tx->created_at?->toIso8601String(),
            'updated_at'                => $tx->updated_at?->toIso8601String(),
            'notification'              => $notification
                ? [
                    'id'                 => (int) $notification->id,
                    'bank'               => $notification->bank,
                    'type'               => $notification->type,
                    'amount'             => sprintf('%.2f', (float) $notification->amount),
                    'sms_timestamp'      => $notification->sms_timestamp?->toIso8601String(),
                    'sender_or_receiver' => $notification->sender_or_receiver,
                ]
                : [
                    // ยังไม่มี SMS — ส่งโครงเดียวกับ Thaiprompt (สร้างจากตัวรายการ)
                    'id'                 => (int) $tx->id,
                    'bank'               => 'PROMPTPAY',
                    'type'               => 'credit',
                    'amount'             => sprintf('%.2f', $payable),
                    'sms_timestamp'      => $tx->created_at?->toIso8601String(),
                    'sender_or_receiver' => $customer,
                ],
        ];
    }

    /**
     * หลายรายการพร้อมกัน — โหลด SMS ที่จับคู่แล้วในคิวรีเดียว (กัน N+1 บน endpoint ที่แอพ poll)
     *
     * @param  iterable<WalletTransaction>  $txs
     * @return array<int, array>
     */
    public function presentMany(iterable $txs): array
    {
        $txs = $txs instanceof Collection ? $txs : collect($txs);
        if ($txs->isEmpty()) {
            return [];
        }

        $notifications = SmsPaymentNotification::whereIn('matched_transaction_id', $txs->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->unique('matched_transaction_id')
            ->keyBy('matched_transaction_id');

        return $txs->map(fn (WalletTransaction $tx) => $this->present($tx, $notifications->get($tx->id), false))
            ->values()->all();
    }

    /** สถานะรายการ → approval_status ที่แอพรู้จัก (ApprovalStatus.kt) */
    private function approvalStatus(WalletTransaction $tx): string
    {
        $meta = (array) $tx->meta;

        return match ($tx->status) {
            'pending'   => $tx->isExpired() ? 'expired' : 'pending_review',
            'success'   => ($tx->approved_by !== null || ($meta['confirmed_via'] ?? null) === 'smschecker_app')
                ? 'manually_approved' : 'auto_approved',
            'failed'    => ! empty($meta['expired']) ? 'expired' : 'rejected',
            'cancelled' => 'cancelled',
            'refunded'  => 'rejected',
            default     => 'pending_review',
        };
    }
}
