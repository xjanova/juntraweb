<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🌙 (2026-11-07) เช็กอินรายวัน — กดวันละครั้ง เปิดดวงเสี่ยงทายประจำวัน + นับวันต่อเนื่อง
 *
 * day = วันที่ตามเวลาไทย (Asia/Bangkok) · unique(user_id, day) กันกดซ้ำ/กดพร้อมกันสองแท็บ
 * streak = จำนวนวันติดกันจนถึงวันนั้น (เก็บไว้เลย ไม่ต้องย้อนนับทุกครั้งที่เปิดหน้า)
 * ไม่มีรางวัลเป็นเงิน — เครดิตฟรีจะไปปนบิลค่าคอม MLM (ต้องให้เจ้าของตัดสินก่อน)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_checkins')) {
            return;
        }

        Schema::create('daily_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('streak')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'day'], 'daily_checkins_user_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_checkins');
    }
};
