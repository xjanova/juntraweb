<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔔 (2026-11-11) Web Push — แจ้งเตือนหลังติดตั้งเว็บเป็นแอปบนมือถือ/ไอแพด
 *
 * เจ้าของสั่ง "ทำให้แจ้งเตือนได้เมื่อติดตั้งแล้ว เช่น แจ้งเตือนยอดค้าง หรือตามมาดูดวงได้"
 *
 * push_subscriptions = เครื่องที่ผู้ใช้กด "เปิดแจ้งเตือน" (1 คนมีได้หลายเครื่อง)
 *   endpoint_hash  sha256 ของ endpoint — endpoint ยาวได้เกิน 500 ตัว ทำ unique ตรง ๆ ไม่ได้ทุกฐานข้อมูล
 *   fail_count     ส่งพลาดติดกัน (ไม่นับ 404/410 ที่ลบทิ้งทันที) — ถึงเพดานแล้วลบ
 *
 * push_deliveries = สมุดจดว่าส่งอะไรให้ใครไปแล้ว — กันส่งซ้ำ (unique user+type+ref) และนับเพดานต่อวัน
 *   ref  ตัวระบุของเรื่อง เช่น topup:32 · reading:88 · daily:2026-11-11
 *
 * users.push_muted = หมวดที่ผู้ใช้ปิดเอง
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->text('endpoint');
                $table->string('endpoint_hash', 64)->unique();
                $table->string('p256dh', 120);
                $table->string('auth', 40);
                $table->string('user_agent', 255)->nullable();
                $table->unsignedSmallInteger('fail_count')->default(0);
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->timestamps();

                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('push_deliveries')) {
            Schema::create('push_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 32);
                $table->string('ref', 64);
                $table->unsignedTinyInteger('devices')->default(0);
                $table->unsignedTinyInteger('delivered')->default(0);
                $table->timestamp('created_at')->nullable();

                $table->unique(['user_id', 'type', 'ref'], 'push_deliveries_once');
                $table->index(['user_id', 'created_at']);
                $table->index(['type', 'created_at']);
            });
        }

        // หมวดที่ผู้ใช้ปิดเองในหน้าบัญชี (JSON list เช่น ["daily"]) — null = รับทุกหมวดที่แอดมินเปิด
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'push_muted')) {
                $table->text('push_muted')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'push_muted')) {
                $table->dropColumn('push_muted');
            }
        });
        Schema::dropIfExists('push_deliveries');
        Schema::dropIfExists('push_subscriptions');
    }
};
