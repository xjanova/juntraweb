<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ข้อความของแม่หมอที่ยื่นแพ็กเกจเปิดไพ่ (kind=offer) — จำหมวดไว้ เพื่อให้การ์ดยังอยู่หลังรีเฟรช
 * และในประวัติแชท (ราคาคำนวณใหม่ตอนแสดงผลเสมอ ไม่เก็บราคาไว้ในข้อความ)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chat_messages', 'offer_topic')) {
            return;
        }
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('offer_topic', 16)->nullable()->after('content');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'offer_topic')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('offer_topic');
            });
        }
    }
};
