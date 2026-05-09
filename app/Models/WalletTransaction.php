<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = [
        'user_id', 'wallet_id', 'type', 'status', 'amount', 'balance_after',
        'description', 'reference_type', 'reference_id', 'method', 'slip_path',
        'reference_code', 'meta', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'balance_after' => 'decimal:2',
        'meta'          => 'array',
        'approved_at'   => 'datetime',
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
            'pending'  => 'รอตรวจสอบ',
            'success'  => 'สำเร็จ',
            'failed'   => 'ปฏิเสธ',
            'refunded' => 'คืนเงินแล้ว',
            default    => $this->status,
        };
    }
}
