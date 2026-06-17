<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'wallet_id', 'type', 'status', 'amount', 'balance_after',
        'description', 'reference_type', 'reference_id', 'method', 'slip_path',
        'slip_hash', 'bank_reference', 'slip_amount', 'reference_code', 'meta',
        'approved_by', 'approved_at', 'expires_at', 'idempotency_key',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'balance_after' => 'decimal:2',
        'slip_amount'   => 'decimal:2',
        'meta'          => 'array',
        'approved_at'   => 'datetime',
        'expires_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPositive(): bool
    {
        return bccomp((string) $this->amount, '0', 2) >= 0;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'topup'      => 'เติมเงิน',
            'debit'      => 'หักค่าบริการ',
            'refund'     => 'คืนเครดิต',
            'adjustment' => 'ปรับยอดโดยแอดมิน',
            default      => $this->type,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'   => 'รอตรวจสอบ',
            'success'   => 'สำเร็จ',
            'failed'    => 'ปฏิเสธ',
            'refunded'  => 'คืนเงินแล้ว',
            'cancelled' => 'ยกเลิกโดยผู้ใช้',
            default     => $this->status,
        };
    }

    /** A pending top-up whose expiry has passed and admin never acted on it. */
    public function isExpired(): bool
    {
        return $this->status === 'pending'
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
