<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📲 (2026-11-12) แอป Android ที่จดไว้รับแจ้งเตือนผ่าน FCM — ช่องทางคู่กับ push_subscriptions (Web Push)
 *
 * push_devices = แอปหนึ่งเครื่องที่ล็อกอินอยู่ (1 คนมีได้หลายเครื่อง)
 *   token          FCM registration token ของแอปบนเครื่องนั้น (ยาวได้หลายร้อยตัว)
 *   token_hash     sha256 ของ token — ทำ unique ตรง ๆ บน text ไม่ได้ทุกฐานข้อมูล
 *   channel        play | direct (null = ไม่รู้ ถือเป็น play) — แอปจาก Google Play ไม่ได้รับเตือนยอดค้าง (ชวนไปจ่ายนอก Play)
 *   personal_access_token_id  โทเคน Sanctum ที่จดเครื่องนี้ — ออกจากระบบ/เปลี่ยนรหัส/ลบบัญชี = โทเคนถูกลบ
 *                  แถวนี้หายตาม (FK cascade) เครื่องที่ออกจากระบบแล้วจึงไม่ได้รับเรื่องของบัญชีเดิมต่อ
 *   fail_count     ส่งพลาดติดกัน (ไม่นับ token ตายที่ลบทิ้งทันที) — ถึงเพดานแล้วลบ
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_devices')) {
            return;
        }
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()
                ->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->string('platform', 16)->default('android');
            $table->string('channel', 16)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
