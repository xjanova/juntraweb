<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    protected $fillable = [
        'user_id', 'birth_date', 'birth_time', 'birth_place',
        'zodiac_slug', 'chinese_zodiac_slug',
        'phone', 'line_id', 'avatar_path', 'subscribed',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'subscribed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zodiac(): ?Zodiac
    {
        return $this->zodiac_slug ? Zodiac::where('slug', $this->zodiac_slug)->first() : null;
    }
}
