<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * 🪐 (2026-11-07) วันเกิดในโปรไฟล์ของลูกค้า — ให้คำทำนายที่จ่ายเงินผูกดวงจากวันเกิดจริงได้เสมอ
 *
 * บอทแชทเพจผูกดวง (ดาวจริง 10 ดวง · ภพ · ดาวเจ้าชนะ) จากวันเกิดของลูกค้าทุกบิล
 * เว็บเดิมส่งวันเกิดเฉพาะเมื่อลูกค้ากรอกในฟอร์มซ้ำ — เว้นว่าง = คำทำนายที่ไม่มีผังดวง ทั้งที่โปรไฟล์มีวันเกิดอยู่แล้ว
 */
final class ProfileBirth
{
    /** วันเกิด Y-m-d ในโปรไฟล์ · null = ไม่มี หรือเป็นวันนี้/อนาคต (Thaiprompt ตอบ 422) */
    public static function date(?User $user): ?string
    {
        $date = $user?->profile?->birth_date;
        if (! $date instanceof CarbonInterface || ! $date->lt(now('Asia/Bangkok')->startOfDay())) {
            return null;
        }

        return $date->format('Y-m-d');
    }
}
