<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * โค้ดผู้แนะนำที่ "ค้างรอผูก" ของผู้ใช้แต่ละคน
 *
 * เดิมโค้ดชวนเพื่อนเดินทางผ่าน **คุกกี้เบราว์เซอร์** (`juntra_ref` ตั้งโดย
 * ReferralController) แล้วถูกเคลมตอนทำ SSO เท่านั้น คนที่กดลิงก์เชิญบนเบราว์เซอร์
 * แล้วไปสมัครในแอพ (เส้นทางปกติมาก เพราะเว็บเชียร์ให้โหลดแอพ) จึงไม่เคยถูกผูก
 * เข้าสายงานเลย — คุกกี้อยู่คนละที่กับแอพ ผู้แนะนำเสียคอมมิชชั่นทั้งสาย
 * โดยไม่มีใครรู้
 *
 * คอลัมน์นี้ให้ที่เก็บโค้ดฝั่งเซิร์ฟเวอร์ ไม่ผูกกับเบราว์เซอร์อีกต่อไป
 * เคลียร์ทิ้งเมื่อเคลมสำเร็จหรือถูกอัปสตรีมปฏิเสธอย่างชัดเจน
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'pending_referral_code')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_referral_code', 64)->nullable()->after('signup_via');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'pending_referral_code')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pending_referral_code');
        });
    }
};
