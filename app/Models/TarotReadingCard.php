<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TarotReadingCard extends Model
{
    protected $fillable = [
        'reading_id', 'tarot_card_id', 'position', 'position_label', 'reversed',
    ];

    protected $casts = ['reversed' => 'boolean'];

    public function reading(): BelongsTo
    {
        return $this->belongsTo(Reading::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(TarotCard::class, 'tarot_card_id');
    }
}
