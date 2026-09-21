<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * บิลค่าคำทำนายหนึ่งใบที่ส่งไปให้ผังแม่หมอคำนวณค่าแนะนำ — สมุดส่งของ ไม่ใช่ที่คำนวณ
 *
 * @property string $status pending|sent|voided|skipped|failed
 */
class AffiliateBill extends Model
{
    public const STATUS_PENDING = 'pending';

    /** แม่หมอรับบิลแล้ว (แจกค่าแนะนำแล้ว) */
    public const STATUS_SENT = 'sent';

    /** ลูกค้าได้เงินคืน และแม่หมอดึงค่าแนะนำคืนแล้ว */
    public const STATUS_VOIDED = 'voided';

    /** คืนเงินก่อนถึงรอบส่ง — ไม่เคยมีค่าแนะนำ */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * ส่งไม่ผ่าน — มี next_attempt_at = อีกฝั่งล่ม ระบบลองใหม่เอง · ไม่มี = ถูกปฏิเสธ รอแอดมินกดส่งซ้ำ
     */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'wallet_transaction_id', 'user_id', 'amount', 'product', 'status', 'attempts',
        'next_attempt_at', 'last_error', 'bill_reference', 'commission_total', 'sent_at', 'voided_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'commission_total' => 'decimal:2',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    /** ส่งไม่ผ่านแบบที่ระบบไม่ลองเองแล้ว — ต้องให้แอดมินดู */
    public function scopeNeedsAdmin($query)
    {
        return $query->where('status', self::STATUS_FAILED)->whereNull('next_attempt_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /** ป้ายสถานะภาษาไทยสำหรับหลังบ้าน */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'รอส่ง',
            self::STATUS_SENT => 'ส่งแล้ว',
            self::STATUS_VOIDED => 'คืนเงิน · ดึงค่าแนะนำคืนแล้ว',
            self::STATUS_SKIPPED => 'คืนเงินก่อนส่ง',
            self::STATUS_FAILED => 'ส่งไม่ผ่าน',
            default => $status,
        };
    }
}
