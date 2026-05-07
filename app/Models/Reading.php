<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reading extends Model
{
    protected $fillable = [
        'user_id', 'session_token', 'type', 'question',
        'payload', 'result', 'ai_provider', 'ai_model', 'shared_public',
    ];

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
