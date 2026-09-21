<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รหัสสมาชิกผังแม่หมอของลูกค้า (member_code ฝั่ง Thaiprompt) — จำไว้ทำลิงก์เชิญ /r/{code}
 *
 * ตัวจริงอยู่ที่ Thaiprompt เสมอ ค่านี้เป็นแค่สำเนา: ว่าง = ยังไม่เคยเข้าผัง
 * (เว็บจะขอให้ Thaiprompt สร้างสมาชิกให้ตอนเปิดหน้าสายงาน/ตอนส่งบิลแรก)
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'maemor_member_code')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('maemor_member_code', 32)->nullable()->after('pending_referral_code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'maemor_member_code')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('maemor_member_code');
        });
    }
};
