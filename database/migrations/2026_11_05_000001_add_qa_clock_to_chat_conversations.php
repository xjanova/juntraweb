<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⏳ (2026-11-05) นาฬิกาถามต่อของห้องที่คุยต่อจากไพ่ (chat_conversations.reading_id)
 *
 * เจ้าของสั่ง "นาฬิกาเริ่มนับถอยหลังตามระบบจริงที่เราจับเวลา" — กติกาเดียวกับบอท FB/LINE (Pro Session)
 *   qa_started_at = คำถามแรกของลูกค้าในห้องนี้ (เริ่มนับ)
 *   qa_ended_at   = ลูกค้ากด "พอแล้ว" (จบก่อนเวลา) · null = ยังไม่กด
 * ห้องคุยทั่วไป (reading_id null) ไม่ใช้สองคอลัมน์นี้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_conversations', 'qa_started_at')) {
                $table->timestamp('qa_started_at')->nullable()->after('persona');
            }
            if (! Schema::hasColumn('chat_conversations', 'qa_ended_at')) {
                $table->timestamp('qa_ended_at')->nullable()->after('qa_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            foreach (['qa_ended_at', 'qa_started_at'] as $col) {
                if (Schema::hasColumn('chat_conversations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
