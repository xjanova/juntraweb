<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'facebook_user_id')) {
                $table->string('facebook_user_id', 64)->nullable()->after('thaiprompt_user_id');
                $table->index('facebook_user_id');
            }
            if (!Schema::hasColumn('users', 'line_user_id')) {
                $table->string('line_user_id', 64)->nullable()->after('facebook_user_id');
                $table->index('line_user_id');
            }
            if (!Schema::hasColumn('users', 'signup_via')) {
                // 'facebook' | 'line' | 'email' | 'thaiprompt' (general SSO) | null
                $table->string('signup_via', 32)->nullable()->after('line_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['facebook_user_id', 'line_user_id', 'signup_via'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    if (in_array($col, ['facebook_user_id', 'line_user_id'])) {
                        $table->dropIndex(["users_{$col}_index"]);
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
