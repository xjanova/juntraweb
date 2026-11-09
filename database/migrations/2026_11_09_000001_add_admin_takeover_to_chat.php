<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🧑‍💼 (2026-11-09) แอดมินคุยกับลูกค้าในห้องผู้พยากรณ์ได้ทุกห้อง (App\Support\ChatTakeover)
 *
 * เจ้าของสั่ง "เวลาแอดมินพูดแชท บอทแม่หมอต้องหยุดก่อน และมีปุ่มเทคบอทเช่นกัน"
 *   chat_conversations.bot_paused_at = แอดมินคุมห้องอยู่ (null = บอทตอบเอง) — ขยับทุกครั้งที่แอดมินพิมพ์
 *                                       ใช้นับเวลาเงียบ คืนให้บอทเองตามที่ตั้งไว้
 *   chat_conversations.bot_paused_by = แอดมินคนที่กดเทคบอท/พิมพ์ล่าสุด
 *   chat_conversations.admin_read_at = แอดมินเปิดอ่านห้องนี้ล่าสุดเมื่อไร (นับข้อความที่ยังไม่อ่าน)
 *   chat_messages.admin_id / admin_as = ข้อความนี้แอดมินพิมพ์ (role ยังเป็น assistant ให้โค้ดเดิม/แอพเดิมไม่พัง)
 *       admin_as: oracle = ลูกค้าเห็นเป็นผู้พยากรณ์พูด · admin = ลูกค้าเห็นป้าย "แอดมิน" · null = บอท/ลูกค้า
 *                 ข้อความลูกค้า: held = ส่งตอนแอดมินคุมห้อง บอทไม่ได้ตอบ (ไม่นับโควตาคุยฟรี)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_conversations', 'bot_paused_at')) {
                $table->timestamp('bot_paused_at')->nullable()->after('qa_ended_at');
            }
            if (! Schema::hasColumn('chat_conversations', 'bot_paused_by')) {
                $table->foreignId('bot_paused_by')->nullable()->after('bot_paused_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('chat_conversations', 'admin_read_at')) {
                $table->timestamp('admin_read_at')->nullable()->after('bot_paused_by');
            }
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_messages', 'admin_id')) {
                $table->foreignId('admin_id')->nullable()->after('offer_topic')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('chat_messages', 'admin_as')) {
                $table->string('admin_as', 16)->nullable()->after('admin_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('chat_messages', 'admin_id')) {
                $table->dropConstrainedForeignId('admin_id');
            }
            if (Schema::hasColumn('chat_messages', 'admin_as')) {
                $table->dropColumn('admin_as');
            }
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            if (Schema::hasColumn('chat_conversations', 'bot_paused_by')) {
                $table->dropConstrainedForeignId('bot_paused_by');
            }
            foreach (['admin_read_at', 'bot_paused_at'] as $col) {
                if (Schema::hasColumn('chat_conversations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
