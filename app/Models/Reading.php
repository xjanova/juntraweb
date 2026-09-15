<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reading extends Model
{
    /** แม่หมอกำลังอ่านไพ่อยู่เบื้องหลัง (App\Jobs\InterpretTarotReading) */
    public const STATUS_PENDING = 'pending';

    public const STATUS_WORKING = 'working';

    /** อ่านไม่สำเร็จ — เครดิตถูกคืนแล้ว (หน้าผลบอกลูกค้า, ไม่แสดงในประวัติ) */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'session_token', 'type', 'question',
        'payload', 'result', 'status', 'ai_provider', 'ai_model', 'shared_public',
    ];

    /** แม่หมอยังอ่านไม่เสร็จ */
    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_WORKING], true);
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /** รายการที่ลูกค้าเห็นในประวัติ — ไม่รวมรายการที่ไม่สำเร็จ (คืนเงินแล้ว ไม่มีอะไรให้อ่าน) */
    public function scopeVisibleInHistory($query)
    {
        return $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', self::STATUS_FAILED));
    }

    protected $casts = [
        'payload' => 'array',
        'shared_public' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tarotCards(): HasMany
    {
        return $this->hasMany(TarotReadingCard::class)->orderBy('position');
    }
}
