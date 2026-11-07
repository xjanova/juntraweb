<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 🌙 เช็กอินรายวัน (วันละแถว ตามวันที่เวลาไทย) — ดู App\Support\DailyLuck */
class DailyCheckin extends Model
{
    protected $fillable = ['user_id', 'day', 'streak'];

    protected $casts = ['day' => 'date', 'streak' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
