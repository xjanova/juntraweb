<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * กู้บทสนทนาที่หายเพราะบั๊ก session_token + เพิ่ม index ให้ตัวนับโควตา
 *
 * บั๊กเดิม: index() หา/สร้างห้องด้วยคู่ (session_token, user_id) แต่ send()
 * หาด้วย session_token อย่างเดียว → ผู้ใช้ที่เปิด /chat ตอนยังไม่ล็อกอิน
 * (สร้างห้อง user_id = NULL) แล้วล็อกอินกลับมา (สร้างห้องที่สองผูก user_id)
 * จะถูกเขียนข้อความลงห้อง NULL ตลอด ขณะที่หน้าจอ render ห้องที่ผูก user_id
 * ผลคือรีเฟรชแล้วบทสนทนาหาย ประวัติไม่ขึ้นใน /account/chats และตัวนับ
 * เพดานคุยฟรีรายวันนับไม่เจอ (bypass ถาวร)
 *
 * migration นี้ย้ายข้อความจากห้องกำพร้า (user_id NULL) กลับเข้าห้องของเจ้าของ
 * ที่ใช้ session_token เดียวกัน แล้วลบห้องกำพร้าทิ้ง — ผู้ใช้ได้ประวัติคืน
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) ย้ายข้อความจากห้องกำพร้ากลับเข้าห้องของเจ้าของ
        //    เลือกห้องเป้าหมายเป็นห้องที่ "ใหม่ที่สุด" ของ token นั้น เพราะเป็น
        //    ห้องที่หน้าเว็บ render อยู่จริงหลังล็อกอิน
        $orphans = DB::table('chat_conversations')
            ->whereNull('user_id')
            ->pluck('id', 'session_token');

        foreach ($orphans as $token => $orphanId) {
            $target = DB::table('chat_conversations')
                ->where('session_token', $token)
                ->whereNotNull('user_id')
                ->orderByDesc('id')
                ->first();

            if (! $target) {
                continue; // guest แท้ ๆ ที่ไม่เคยล็อกอิน — ปล่อยไว้ตามเดิม
            }

            DB::table('chat_messages')
                ->where('chat_conversation_id', $orphanId)
                ->update(['chat_conversation_id' => $target->id]);

            DB::table('chat_conversations')->where('id', $orphanId)->delete();
        }

        // 2) index ให้ตัวนับโควตารายวัน (นับ role=user ต่อห้องต่อวัน) ใช้ได้จริง
        //    ของเดิมสแกน chat_messages ทั้งตารางทุกครั้งที่โหลดหน้าแชท
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index(['chat_conversation_id', 'role', 'created_at'], 'chat_messages_convo_role_created_idx');
        });

        // 3) index คู่ที่โค้ดใช้ค้นห้องจริง ๆ (session_token + user_id)
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->index(['session_token', 'user_id'], 'chat_convos_token_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('chat_messages_convo_role_created_idx');
        });
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropIndex('chat_convos_token_user_idx');
        });
        // ข้อความที่ย้ายแล้วไม่ย้อนกลับ — การย้อนจะทำให้ประวัติหายอีกรอบ
    }
};
