<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รีวิวบนหน้าแรก — แอดมินเขียนเอง (user_id null) หรือลูกค้ารีวิวหลังจบคำทำนาย (2026-11-07)
 *
 * รีวิวของลูกค้าขึ้นหน้าแรกได้ก็ต่อเมื่อ allow_public (ลูกค้ายอม) และ approved (แอดมินกด) ทั้งคู่
 */
class Testimonial extends Model
{
    protected $fillable = ['name', 'service', 'rating', 'message', 'approved', 'order_index', 'user_id', 'reading_id', 'allow_public'];

    protected $casts = ['approved' => 'boolean', 'allow_public' => 'boolean', 'rating' => 'integer'];

    public function scopeApproved($query)
    {
        // รีวิวของลูกค้าที่ไม่ยอมให้ขึ้นหน้าแรก ต้องไม่หลุดออกไป แม้แอดมินเผลอกดอนุมัติ
        return $query->where('approved', true)
            ->where(fn ($q) => $q->whereNull('reading_id')->orWhere('allow_public', true));
    }

    /** รีวิวที่หน้าแรกโชว์ ตามลำดับที่แอดมินจัด — ชุดเดียวทั้งเว็บ (HomeController) และแอพ (GET /v1/testimonials) */
    public function scopeOnHomePage($query)
    {
        return $query->approved()->orderBy('order_index');
    }

    public function scopeFromCustomers($query)
    {
        return $query->whereNotNull('reading_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reading(): BelongsTo
    {
        return $this->belongsTo(Reading::class);
    }

    /** ชื่อที่โชว์บนหน้าแรก — คำแรกของชื่อ ตัดสั้น ไม่โชว์นามสกุล/อีเมล */
    public static function displayNameFor(?User $user): string
    {
        $first = trim((string) preg_split('/\s+/u', trim((string) ($user->name ?? '')))[0]);
        if ($first === '' || str_contains($first, '@')) {
            return 'ลูกค้าจันทรา';
        }

        return 'คุณ'.mb_substr($first, 0, 12);
    }
}
