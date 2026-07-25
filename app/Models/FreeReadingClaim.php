<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * การเคลมสิทธิ์ "ดูดวงฟรี 1 ใบ" ของผู้ใช้หนึ่งคนในรอบแจกหนึ่งรอบ
 *
 * @property int $id
 * @property int $user_id
 * @property string $batch รอบการแจก (Setting free_reading_batch)
 * @property int|null $reading_id ผลคำทำนาย (null = กำลังทำ/ล้มเหลว)
 * @property string|null $ip
 */
class FreeReadingClaim extends Model
{
    protected $fillable = [
        'user_id',
        'batch',
        'reading_id',
        'failed_at',
        'ip',
    ];

    protected $casts = [
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reading(): BelongsTo
    {
        return $this->belongsTo(Reading::class);
    }
}
