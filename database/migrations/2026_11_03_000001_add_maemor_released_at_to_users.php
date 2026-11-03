<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🌙 (2026-11-03 FB web-only) เวลาที่ผังแม่หมอ (Thaiprompt) รับรู้ว่าบัญชีนี้ถูกลบและปลดการผูกแล้ว
 *
 * ว่าง + บัญชีถูกลบ (AccountDeletion) = ยังไม่ได้ปลด → affiliate:sync-bills ส่ง
 *   POST affiliate/accounts/{user_ref}/release ซ้ำจนสำเร็จ
 * ไม่ปลด = ลูกค้าที่สมัครใหม่แล้วผูก Thaiprompt บัญชีเดิมจะติด 409 thaiprompt_taken ตลอดไป
 */
return new class extends Migration {
    public function up(): void
    {
        // เพิ่มคอลัมน์ — เช็คทีละคอลัมน์ (ห้าม hasTable + return)
        if (Schema::hasColumn('users', 'maemor_released_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('maemor_released_at')->nullable();
        });

        // บัญชีที่ลบไปก่อนมีระบบนี้ = ประทับว่าปลดแล้ว (ใช้เวลาที่ลบ) ⇒ cron ไม่ไล่ปลดย้อนหลังทั้งระบบ
        // ปลดเฉพาะบัญชีที่ลบหลัง deploy เท่านั้น — ผังแม่หมอของบัญชีเก่าคงเหมือนเดิมทุกประการ
        DB::table('users')
            ->whereNotNull('deleted_at')
            ->whereNull('maemor_released_at')
            ->update(['maemor_released_at' => DB::raw('deleted_at')]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'maemor_released_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('maemor_released_at');
        });
    }
};
