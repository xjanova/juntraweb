<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สถานะของคำทำนายที่แม่หมอกำลังอ่านอยู่เบื้องหลัง (2026-09-15)
 *
 * แพ็กเกจยาว (Celtic ~36 วิ · 12 เดือน ~48 วิ · คุณไสย ~55 วิ บนเลนทำนาย GPT-5.6) ชนเพดาน ~55-60 วิ
 * ของคำขอหน้าเว็บ → ตัดเงินแล้วพาไปหน้าผลทันที ให้แม่หมออ่านต่อหลังส่งหน้า (dispatchAfterResponse)
 *
 * null = เสร็จแล้ว/รายการเก่า · pending = รอเริ่ม · working = กำลังอ่าน · failed = ไม่สำเร็จ (คืนเงินแล้ว)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('readings', 'status')) {
            return;
        }
        Schema::table('readings', function (Blueprint $table) {
            $table->string('status', 16)->nullable()->after('result');
            $table->index(['status', 'updated_at'], 'readings_status_updated_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('readings', 'status')) {
            Schema::table('readings', function (Blueprint $table) {
                $table->dropIndex('readings_status_updated_idx');
                $table->dropColumn('status');
            });
        }
    }
};
