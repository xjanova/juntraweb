<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    // 💬 (2026-09-21) reading_id = ห้องนี้คุยต่อจากคำพยากรณ์ไหน (null = ห้องคุยทั่วไป)
    protected $fillable = ['user_id', 'session_token', 'title', 'reading_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reading(): BelongsTo
    {
        return $this->belongsTo(Reading::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }
}
