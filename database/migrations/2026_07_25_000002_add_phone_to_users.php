<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เบอร์โทรบน users — ใช้เป็น "ตัวตน" ของลูกค้าที่สมัครโดยไม่มีอีเมล
 *
 * ลูกค้าหลักของเราเป็นผู้สูงอายุ หลายคนไม่มีอีเมลหรือจำไม่ได้ (เจ้าของกำหนด
 * 2026-07-25) หน้าสมัครจึงรับเบอร์อย่างเดียวได้ แล้วสร้างอีเมลภายในให้จากเบอร์
 *
 * unique เพราะถ้าเบอร์ซ้ำได้ สองคนจะกลายเป็นคนเดียวกันในสายตาระบบ
 * (NULL ซ้ำได้ตามปกติของ SQL — คนที่สมัครด้วยอีเมลไม่ต้องมีเบอร์)
 *
 * profiles.phone ที่มีอยู่เดิมเป็นคนละเรื่อง — นั่นคือเบอร์ติดต่อในโปรไฟล์
 * ที่ผู้ใช้กรอกทีหลัง ไม่ใช่กุญแจเข้าระบบ
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'phone')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'phone')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn('phone');
        });
    }
};
