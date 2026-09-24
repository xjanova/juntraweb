<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** การซื้อแพ็กเครดิตหนึ่งครั้งผ่าน Google Play (ดู App\Services\GooglePlay\GooglePlayBilling) */
class GooglePlayPurchase extends Model
{
    public const STATUS_CREDITED = 'credited';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'user_id', 'product_id', 'token_hash', 'purchase_token', 'order_id', 'credits', 'status',
        'wallet_transaction_id', 'purchased_at', 'consumed_at', 'consume_attempts', 'last_error',
        'voided_at', 'void_reason', 'region_code',
    ];

    protected $hidden = ['purchase_token'];

    protected $casts = [
        'credits'      => 'decimal:2',
        'purchased_at' => 'datetime',
        'consumed_at'  => 'datetime',
        'voided_at'    => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
