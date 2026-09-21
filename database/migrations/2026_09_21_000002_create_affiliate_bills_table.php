<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * บิลที่ส่งไปให้ผังแม่หมอ (Thaiprompt) คำนวณค่าแนะนำ — หนึ่งแถวต่อหนึ่งรายการตัดเงินค่าคำทำนาย
 *
 * เจ้าของสั่ง (2026-09-21): เว็บ/แอพจันทราไม่คำนวณค่าแนะนำเอง ทุกบิลต้องส่งไปที่ผังแม่หมอ
 *   ตารางนี้คือสมุดส่งของ: ส่งแล้วหรือยัง · ค้างเพราะอะไร · ถูกคืนเงินแล้วดึงค่าแนะนำคืนหรือยัง
 *   (ยอดเงินและค่าแนะนำจริงอยู่ที่ Thaiprompt — ที่นี่ไม่มีตัวเลขที่คำนวณเอง)
 *
 * affiliate_sync_since: ส่งเฉพาะบิลที่เกิดหลังวันที่เปิดระบบ — บิลเก่าก่อนหน้านี้เจ้าของต้องสั่งเอง
 *   (`php artisan affiliate:sync-bills --since=...`) เพราะจะจ่ายค่าแนะนำย้อนหลังด้วยเงินจริง
 */
return new class extends Migration {
    public function up(): void
    {
        // จุดเริ่มนับ — ตั้งครั้งเดียว (migrate ซ้ำต้องไม่เลื่อน ไม่งั้นบิลช่วงระหว่างนั้นหาย)
        //   ต้องตั้ง "ก่อน" สร้างตาราง: affiliate:sync-bills รอให้มีตารางก่อนค่อยอ่านค่านี้ ถ้าตารางมาก่อน
        //   รอบที่ตกตรงช่องว่างจะจำค่า "ไม่มี" ไว้ใน cache ถาวร (Setting::get = rememberForever) แล้วหยุดส่งเงียบ ๆ
        DB::table('settings')->insertOrIgnore([
            'key' => 'affiliate_sync_since',
            'value' => now()->toIso8601String(),
            'group' => 'affiliate',
            'is_encrypted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Models\Setting::forgetCached('affiliate_sync_since');

        if (! Schema::hasTable('affiliate_bills')) {
            Schema::create('affiliate_bills', function (Blueprint $table) {
                $table->id();
                $table->foreignId('wallet_transaction_id')->unique()->constrained('wallet_transactions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('product', 60);
                // pending → sent → (voided) · skipped = คืนเงินก่อนส่ง · failed = ข้อมูลถูกปฏิเสธ รอแอดมินดู
                $table->string('status', 12)->default('pending')->index();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable()->index();
                $table->string('last_error', 255)->nullable();
                $table->string('bill_reference', 32)->nullable();
                $table->decimal('commission_total', 12, 2)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_bills');
        DB::table('settings')->where('key', 'affiliate_sync_since')->delete();
    }
};
