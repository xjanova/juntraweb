<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ (2026-11-07) รีวิวหลังจบคำทำนาย — เจ้าของสั่ง "ให้ลูกค้ารีวิว หลังจบทำนาย"
 *
 * ใช้ตาราง testimonials เดิม (หน้าแรกดึง approved อยู่แล้ว + แอดมินมีหน้าจัดการแล้ว)
 *   user_id / reading_id = รีวิวนี้ลูกค้าเขียนเองจากคำทำนายไหน (null = แอดมินเขียนเองแบบเดิม)
 *   allow_public         = ลูกค้ายอมให้ขึ้นหน้าแรก (แอดมินยังต้องกด approved เอง)
 * 1 คำทำนาย = 1 รีวิว (unique reading_id) — แก้ไขได้ ไม่เพิ่มแถว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            if (! Schema::hasColumn('testimonials', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('testimonials', 'reading_id')) {
                $table->foreignId('reading_id')->nullable()->after('user_id')->constrained('readings')->cascadeOnDelete();
                $table->unique('reading_id', 'testimonials_reading_unique');
            }
            if (! Schema::hasColumn('testimonials', 'allow_public')) {
                $table->boolean('allow_public')->default(false)->after('approved');
            }
        });
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            if (Schema::hasColumn('testimonials', 'reading_id')) {
                $table->dropUnique('testimonials_reading_unique');
                $table->dropConstrainedForeignId('reading_id');
            }
            if (Schema::hasColumn('testimonials', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
            if (Schema::hasColumn('testimonials', 'allow_public')) {
                $table->dropColumn('allow_public');
            }
        });
    }
};
