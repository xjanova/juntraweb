<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🌙 บิลที่ลูกค้าจ่ายก่อนเปิดระบบค่าแนะนำ (ก่อน affiliate_sync_since) — ส่งให้ผังแม่หมอแบบ "นับสิทธิ์อย่างเดียว"
 *
 * เจ้าของสั่ง (2026-09-23): ผู้เชิญต้องเคยมีบิลที่ชำระแล้วจึงได้ค่าแนะนำ — ลูกค้าที่ซื้อบนเว็บก่อนเปิดระบบ
 *   ก็ "เคย" แล้ว แต่แม่หมอไม่เคยรู้ · บิลกลุ่มนี้ไม่แจกค่าแนะนำ (เจ้าของสั่ง 2026-09-21: ไม่จ่ายย้อนหลัง)
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('affiliate_bills', 'history_only')) {
            return;
        }

        Schema::table('affiliate_bills', function (Blueprint $table) {
            $table->boolean('history_only')->default(false)->after('product');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('affiliate_bills', 'history_only')) {
            return;
        }

        Schema::table('affiliate_bills', function (Blueprint $table) {
            $table->dropColumn('history_only');
        });
    }
};
