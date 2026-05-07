<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zodiac extends Model
{
    protected $fillable = [
        'slug', 'name_en', 'name_th', 'glyph', 'element',
        'ruler', 'date_range', 'order_index', 'traits_th',
    ];

    public function dailyHoroscopes(): HasMany
    {
        return $this->hasMany(DailyHoroscope::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
