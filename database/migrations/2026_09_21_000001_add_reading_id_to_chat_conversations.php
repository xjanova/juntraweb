<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💬 (2026-09-21) ห้องแชทที่คุยต่อจากคำพยากรณ์ — จำไว้ในฐานข้อมูลว่าห้องนี้เป็นของไพ่ชุดไหน
 *
 * เดิมลิงก์ "ห้องนี้คุยเรื่องไพ่ชุดไหน" อยู่ใน session ของเบราว์เซอร์อย่างเดียว (หมดอายุเมื่อไม่ได้ใช้ ~2 ชม.)
 * ลูกค้ากลับมาถามต่อวันหลัง แม่หมอจึงไม่เห็นไพ่ของเขาแล้ว · แอพมือถือไม่มีทางผูกห้องกับไพ่เลย
 * ตอนนี้บริบท (ไพ่ทุกใบ + คำพยากรณ์ฉบับเต็ม) สร้างใหม่จากแถวนี้ทุกครั้งที่ต้องเปิดห้องที่ Thaiprompt ใหม่
 *
 * nullOnDelete: ลบคำพยากรณ์ทิ้ง ห้องแชทยังอยู่เป็นประวัติ (แค่ไม่ผูกไพ่แล้ว)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chat_conversations', 'reading_id')) {
            return;
        }
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->foreignId('reading_id')->nullable()->after('user_id')->index()
                ->constrained('readings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_conversations', 'reading_id')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reading_id');
            });
        }
    }
};
