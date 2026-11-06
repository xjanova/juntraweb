<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎁 (2026-11-06) ไพ่ฟรีวันละใบต่อคน — นับต่อเครื่องด้วย (คุกกี้ jt_dev) ไม่ใช่แค่ต่อบัญชี
 * สมัครบัญชีใหม่บนเครื่องเดิมแล้วรับฟรีซ้ำในวันเดียวกันไม่ได้ (FreeReadingPolicy::deviceUsedToday)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('free_reading_claims', 'device')) {
            return;
        }
        Schema::table('free_reading_claims', function (Blueprint $table) {
            $table->string('device', 32)->nullable()->after('ip');
            $table->index(['device', 'batch'], 'free_claim_device_batch_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('free_reading_claims', 'device')) {
            return;
        }
        Schema::table('free_reading_claims', function (Blueprint $table) {
            $table->dropIndex('free_claim_device_batch_idx');
            $table->dropColumn('device');
        });
    }
};
