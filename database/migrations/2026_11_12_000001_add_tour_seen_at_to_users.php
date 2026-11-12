<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🌸 (2026-11-12) เวลาที่สมาชิกเลือกแล้วว่าจะ "ดูดวงฟรีเลย" หรือ "ให้บุษบาพาทัวร์" (App\Support\BusabaTour)
 *
 * ว่าง = ยังไม่เคยเลือก → หน้าแรกขึ้นหน้าเลือกสองปุ่ม (ถ้ายังไม่เคยดูดวงเลยสักครั้ง)
 * มีค่า = ไม่ขึ้นอีก ทุกเครื่อง (cookie อย่างเดียวจำได้แค่เครื่องเดียว)
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'tour_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('tour_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'tour_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tour_seen_at');
        });
    }
};
