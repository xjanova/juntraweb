<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bank SMS forwarded by a device, plus the result of matching it to a
 * pending wallet top-up. `nonce` is unique to block replays.
 */
class SmsPaymentNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id', 'bank', 'type', 'amount', 'account_number',
        'sender_or_receiver', 'reference_number', 'nonce', 'sms_timestamp',
        'status', 'matched_transaction_id', 'raw_payload', 'ip_address',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'sms_timestamp' => 'datetime',
        'raw_payload'   => 'array',
    ];

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'matched_transaction_id');
    }
}
