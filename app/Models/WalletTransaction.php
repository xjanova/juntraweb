<?php

namespace App\Models;

use App\Observers\TopupReservationObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// คืนยอดเศษสตางค์ที่จองกับ Thaiprompt เมื่อรายการเติมเงินพ้น pending (ดู observer)
#[ObservedBy(TopupReservationObserver::class)]
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

    /** การย้อนรายการนี้โดยแอดมิน (สมุดตรวจสอบ) — ย้อนได้ครั้งเดียว */
    public function adminRollbacks(): HasMany
    {
        return $this->hasMany(WalletAdminAdjustment::class, 'original_transaction_id')->where('action', 'rollback');
    }

    /** แถวคืนเงินที่อ้างถึงรายการหักนี้ (ระบบคืน / แอดมินคืน / แอดมินย้อน) */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'reference_id')
            ->where('reference_type', 'wallet_transaction')
            ->where('type', 'refund')
            ->whereNotNull('balance_after');
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
        // 🧾 (2026-11-07) บิล QR ที่ไม่มีใครโอนจนหมดเวลา เดิมขึ้นว่า "ปฏิเสธ" — ลูกตกใจว่าโอนแล้วโดนตีกลับ
        if ($this->wasExpired() || ($this->isExpired() && ! $this->slip_path)) {
            return 'หมดอายุ';
        }

        return match ($this->status) {
            'pending'   => 'รอตรวจสอบ',
            'success'   => 'สำเร็จ',
            'failed'    => 'ปฏิเสธ',
            'refunded'  => 'คืนเงินแล้ว',
            'cancelled' => 'ยกเลิกโดยผู้ใช้',
            default     => $this->status,
        };
    }

    /**
     * เติมเงินที่ปิดเพราะหมดเวลา ไม่ใช่เพราะแอดมิน/แอป SMS ปฏิเสธ
     *   ตัวกวาด (topups:cleanup-expired) ใส่ meta.expired · รายการเก่าก่อนมี flag ดูจาก "ไม่มีผู้ตัดสิน"
     *   (การปฏิเสธทุกทางใส่ approved_at เสมอ — WalletService::rejectTopup / rejectFromSmsChecker)
     */
    public function wasExpired(): bool
    {
        return $this->type === 'topup'
            && $this->status === 'failed'
            && (data_get($this->meta, 'expired') === true
                || ($this->approved_at === null && ! data_get($this->meta, 'reject_reason')));
    }

    /**
     * 🧾 (2026-11-11) รายการที่เปิดจากหลักฐานที่ลูกค้าส่งในแชทเพจโดยไม่มีบิลค้าง
     * ลูกค้า "จ่ายมาแล้ว" — ห้ามโชว์ QR/ทวงให้จ่าย (เคสจริง: ลูกค้าเห็น QR แล้วจ่ายซ้ำ)
     * ยอดเป็นยอดดิบจากสลิป ไม่ได้จองสตางค์เฉพาะ → SMS จับคู่ได้เฉพาะแบบผูกเวลา (findChatSlipTopup)
     */
    public const METHOD_CHAT_SLIP = 'chat_slip';

    /** บิลที่ลูกค้ายังต้องจ่าย (ใช้กับป้าย/แจ้งเตือน/QR) — ไม่รวมรายการที่ลูกค้าส่งหลักฐานมาแล้ว */
    public function awaitsCustomerPayment(): bool
    {
        return $this->type === 'topup'
            && $this->status === 'pending'
            && $this->method !== self::METHOD_CHAT_SLIP;
    }

    /** A pending top-up whose expiry has passed and admin never acted on it. */
    public function isExpired(): bool
    {
        return $this->status === 'pending'
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
