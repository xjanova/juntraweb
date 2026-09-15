<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    /** offer_topic: แม่หมอยื่นแพ็กเกจเปิดไพ่ในข้อความนี้ (love|career|money|health|general) — null = ข้อความธรรมดา */
    protected $fillable = ['chat_conversation_id', 'role', 'content', 'offer_topic'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }
}
