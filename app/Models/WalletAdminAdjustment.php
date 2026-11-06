<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * บันทึกตรวจสอบของรายการที่แอดมินแตะยอดวอลเลตโดยตรง (append-only)
 *
 * ⚠️ ห้ามแก้/ลบแถวในตารางนี้จากหน้าจอใด ๆ — เป็นหลักฐานทางบัญชี
 *
 * @property int $id
 * @property int|null $wallet_id null เมื่อวอลเลตถูกลบไปแล้ว (แถวตรวจสอบยังอยู่)
 * @property int $user_id เจ้าของวอลเลต
 * @property int|null $admin_id แอดมินที่ทำรายการ
 * @property string|null $admin_name ชื่อแอดมิน ณ ตอนทำรายการ
 * @property string $action credit|debit|refund|rollback|transfer_out|transfer_in
 * @property string $amount ยอดที่ขยับ (บวกเสมอ)
 * @property string $balance_before
 * @property string $balance_after
 * @property string $reason
 * @property int|null $wallet_transaction_id
 * @property int|null $original_transaction_id
 * @property string|null $idempotency_key
 */
class WalletAdminAdjustment extends Model
{
    /** ป้ายภาษาไทยของแต่ละประเภท */
    public const ACTION_LABELS = [
        'credit'       => 'เติมเครดิต',
        'debit'        => 'หักเครดิต',
        'refund'       => 'คืนเงิน',
        'rollback'     => 'ย้อนรายการ',
        'transfer_out' => 'โอนออก',
        'transfer_in'  => 'รับโอน',
    ];

    /** สีป้ายในหน้าแอดมิน */
    public const ACTION_COLORS = [
        'credit'       => 'success',
        'debit'        => 'danger',
        'refund'       => 'warning',
        'rollback'     => 'gray',
        'transfer_out' => 'info',
        'transfer_in'  => 'info',
    ];

    protected $fillable = [
        'wallet_id', 'user_id', 'admin_id', 'admin_name', 'action', 'amount',
        'balance_before', 'balance_after', 'reason', 'wallet_transaction_id',
        'original_transaction_id', 'idempotency_key', 'ip_address', 'user_agent', 'meta',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after'  => 'decimal:2',
        'meta'           => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id')->withTrashed();
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    /** รายการนี้ทำให้ยอดเพิ่มหรือไม่ (ดูจากยอดก่อน/หลังจริง ไม่เดาจากชื่อ action) */
    public function isCredit(): bool
    {
        return bccomp((string) $this->balance_after, (string) $this->balance_before, 2) > 0;
    }
}
