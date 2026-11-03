<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔮 (2026-11-03) ห้องแชทแต่ละห้องเป็นของผู้พยากรณ์คนไหน — maemor | busaba | kunsai (config/chat_rooms.php)
 *
 * ห้องเดิมทั้งหมดเป็นห้องแม่หมอ → default 'maemor' (ไม่ต้องแก้แถวเก่า)
 * index (user_id, persona): หาห้องสดของลูกค้าในห้องผู้พยากรณ์ที่เลือก
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chat_conversations', 'persona')) {
            return;
        }
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->string('persona', 16)->default('maemor')->after('reading_id');
            $table->index(['user_id', 'persona'], 'chat_convos_user_persona_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('chat_conversations', 'persona')) {
            return;
        }
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropIndex('chat_convos_user_persona_idx');
            $table->dropColumn('persona');
        });
    }
};
