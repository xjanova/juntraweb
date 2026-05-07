<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyHoroscope extends Model
{
    protected $fillable = [
        'zodiac_id', 'date', 'summary', 'love', 'career', 'money', 'health',
        'lucky_number', 'lucky_color', 'lucky_card', 'ai_generated',
    ];

    protected $casts = [
        'date' => 'date',
        'ai_generated' => 'boolean',
    ];

    public function zodiac(): BelongsTo
    {
        return $this->belongsTo(Zodiac::class);
    }
}
