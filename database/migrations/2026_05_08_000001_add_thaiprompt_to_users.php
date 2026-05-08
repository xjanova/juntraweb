<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'thaiprompt_user_id')) {
                $table->string('thaiprompt_user_id', 64)->nullable()->after('role');
                $table->index('thaiprompt_user_id');
            }
            if (!Schema::hasColumn('users', 'thaiprompt_token')) {
                $table->text('thaiprompt_token')->nullable()->after('thaiprompt_user_id');
            }
            if (!Schema::hasColumn('users', 'thaiprompt_synced_at')) {
                $table->timestamp('thaiprompt_synced_at')->nullable()->after('thaiprompt_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'thaiprompt_user_id')) {
                $table->dropIndex(['thaiprompt_user_id']);
                $table->dropColumn(['thaiprompt_user_id', 'thaiprompt_token', 'thaiprompt_synced_at']);
            }
        });
    }
};
