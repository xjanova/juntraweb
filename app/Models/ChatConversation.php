<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    // 💬 (2026-09-21) reading_id = ห้องนี้คุยต่อจากคำพยากรณ์ไหน (null = ห้องคุยทั่วไป)
    // 🔮 (2026-11-03) persona = ห้องของผู้พยากรณ์คนไหน (App\Support\ChatRooms) — ห้องเก่าทั้งหมด = maemor
    // ⏳ (2026-11-05) qa_started_at / qa_ended_at = นาฬิกาถามต่อของห้องที่คุยต่อจากไพ่ (App\Support\ReadingQaClock)
    // 🎁 (2026-11-11) free_reading_id = ห้องที่เริ่มจากไพ่ฟรีใบไหน — นาฬิกาถามฟรีใช้ qa_* ชุดเดิม (App\Support\FreeAskClock)
    // 🧑‍💼 (2026-11-09) bot_paused_at/by = แอดมินคุมห้องอยู่ · admin_read_at = แอดมินอ่านถึงไหน (App\Support\ChatTakeover)
    protected $fillable = ['user_id', 'session_token', 'title', 'reading_id', 'free_reading_id', 'persona', 'qa_started_at', 'qa_ended_at',
        'bot_paused_at', 'bot_paused_by', 'admin_read_at'];

    protected $attributes = ['persona' => 'maemor'];

    protected $casts = [
        'qa_started_at' => 'datetime', 'qa_ended_at' => 'datetime',
        'bot_paused_at' => 'datetime', 'admin_read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reading(): BelongsTo
    {
        return $this->belongsTo(Reading::class);
    }

    /** แอดมินที่คุมห้องอยู่ (null = บอทตอบเอง หรือบัญชีแอดมินถูกลบไปแล้ว) */
    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bot_paused_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }
}
