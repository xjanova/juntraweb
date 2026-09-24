<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** รายงานเนื้อหาที่ AI สร้าง (คำทำนาย/ข้อความแชท) จากลูกค้า — ดู Api\V1\ContentReportController */
class ContentReport extends Model
{
    public const REASONS = [
        'offensive'  => 'ไม่เหมาะสม / หยาบคาย',
        'harmful'    => 'อันตราย / ชี้นำให้ทำสิ่งเสี่ยง',
        'inaccurate' => 'ผิดพลาด / ไม่ตรงกับไพ่ที่เปิด',
        'other'      => 'อื่น ๆ',
    ];

    public const SUBJECTS = [
        'reading'      => 'คำทำนาย',
        'chat_message' => 'ข้อความแชท',
    ];

    protected $fillable = [
        'user_id', 'subject_type', 'subject_id', 'reason', 'note', 'snapshot',
        'status', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
