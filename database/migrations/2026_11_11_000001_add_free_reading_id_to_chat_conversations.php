<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎁 (2026-11-11) ห้องที่เริ่มจากไพ่ฟรี — จำว่าเป็นห้องของไพ่ฟรีใบไหน (App\Support\FreeAskClock)
 *
 * เจ้าของสั่ง "การถามฟรี เวลาควรไม่เกิน 2 นาที" — ห้องนี้ไม่ผูก reading_id โดยตั้งใจ
 * (ห้องผูกไพ่ซ่อนแพ็กเกจ) จึงต้องมีคอลัมน์ของตัวเอง ใช้ qa_started_at / qa_ended_at เดิมเป็นนาฬิกา
 * เดิมจำห้องไว้ใน cache อย่างเดียว — cache ถูกล้างเมื่อไรห้องก็กลายเป็นห้องคุยทั่วไป (ถามฟรีไม่จำกัด)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_conversations', 'free_reading_id')) {
                $table->unsignedBigInteger('free_reading_id')->nullable()->after('reading_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            if (Schema::hasColumn('chat_conversations', 'free_reading_id')) {
                $table->dropIndex(['free_reading_id']);
                $table->dropColumn('free_reading_id');
            }
        });
    }
};
