<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎁 (2026-07-26) ตารางเคลม "ดูดวงฟรี 1 ใบ"
 *
 * ทำไมต้องมีตารางแยก แทนที่จะนับจาก readings ตรง ๆ:
 *
 * 1. **กันแจกซ้ำระดับฐานข้อมูล** — การเช็คด้วย exists() แล้วค่อย insert มีช่องว่าง
 *    ให้สอง request ที่ยิงพร้อมกันผ่านได้ทั้งคู่ (เคยเกิดจริงกับโควตาแชทจนคุยฟรี
 *    ไม่จำกัด) ที่นี่ใช้ UNIQUE(user_id, batch) เป็นด่านสุดท้ายที่ race ไม่ผ่าน
 *
 * 2. **"ฟรีใหม่ทุกคน" ทำได้ด้วยการเปลี่ยนค่าเดียว** — เจ้าของสั่งแจกรอบใหม่เมื่อไร
 *    ก็เลื่อน Setting `free_reading_batch` (v1 → v2) ทุกคนได้สิทธิ์ใหม่ทันที
 *    โดยไม่ต้องลบ/แก้ข้อมูลลูกค้า และยังเก็บประวัติรอบเก่าไว้ตรวจได้
 *
 * 3. **นับเพดานต่อวันได้ตรง index** — ไม่ต้องกวาดตาราง readings ทั้งก้อนที่ปน
 *    ดวงที่จ่ายเงินอยู่ด้วย
 *
 * reading_id เป็น nullable โดยตั้งใจ: แถวถูกจองไว้ก่อนยิง AI (กัน race) แล้วค่อย
 * ผูก reading ตอนสำเร็จ — ถ้า AI ล้ม แถวจะถูกลบทิ้งเพื่อ "คืนสิทธิ์" ให้ลูกค้า
 * และถ้าโปรเซสตายกลางคัน แถวค้างที่ reading_id = null จะถูกถือว่าหมดอายุใน 10 นาที
 * (ดู App\Support\FreeReadingPolicy)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('free_reading_claims')) {
            return;
        }

        Schema::create('free_reading_claims', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // รอบการแจกสิทธิ์ — เลื่อนค่าใน Setting เพื่อแจกใหม่ทั้งระบบ
            $table->string('batch', 32)->default('v1');

            // ผูกกับผลคำทำนายเมื่อสำเร็จ (null = กำลังดำเนินการ/ล้มเหลว)
            $table->foreignId('reading_id')
                ->nullable()
                ->constrained('readings')
                ->nullOnDelete();

            // ล้มเหลว (AI ไม่ตอบ/ระบบขัดข้อง) — คืนสิทธิ์ให้ลูกค้าแต่ยังนับเป็น
            // "สลอตที่จ่ายต้นทุน AI ไปแล้ว" ในเพดานต่อวัน
            $table->timestamp('failed_at')->nullable();

            $table->string('ip', 45)->nullable();

            $table->timestamps();

            // ด่านสุดท้ายกันแจกซ้ำ — race condition ผ่านตรงนี้ไม่ได้
            $table->unique(['user_id', 'batch'], 'free_claim_user_batch_uq');

            // นับเพดานต่อวัน — เป็นเรื่อง "ต้นทุนต่อวัน" ไม่ผูกกับรอบแจก
            // (ถ้าผูกกับ batch การเลื่อนรอบกลางวันจะรีเซ็ตเพดานไปด้วยแบบเงียบ ๆ)
            $table->index('created_at', 'free_claim_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_reading_claims');
    }
};
