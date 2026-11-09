<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    /**
     * offer_topic: แม่หมอยื่นแพ็กเกจเปิดไพ่ในข้อความนี้ (love|career|money|health|general) — null = ข้อความธรรมดา
     *
     * 🧑‍💼 (2026-11-09) admin_id/admin_as = แอดมินพิมพ์ข้อความนี้ (role เป็น assistant เสมอ)
     *   admin_as: oracle = ลูกค้าเห็นเป็นผู้พยากรณ์พูด · admin = ลูกค้าเห็นป้าย "แอดมิน" · null = ไม่ใช่แอดมิน
     *   ข้อความของลูกค้า (role user): held = ส่งตอนแอดมินคุมห้อง บอทไม่ได้ตอบ (ไม่นับโควตาคุยฟรี)
     */
    protected $fillable = ['chat_conversation_id', 'role', 'content', 'offer_topic', 'admin_id', 'admin_as'];

    public const AS_ORACLE = 'oracle';

    public const AS_ADMIN = 'admin';

    public const HELD = 'held';

    /** ข้อความที่คนจริง (แอดมิน) พิมพ์ — ไม่ใช่บอท */
    public function fromAdmin(): bool
    {
        return $this->role === 'assistant' && $this->admin_as !== null;
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }
}
