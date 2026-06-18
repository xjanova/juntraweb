<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the OAuth refresh token + access-token expiry so a stale
 * thaiprompt_token can be refreshed instead of forcing a full re-link.
 * (Integration audit: the callback discarded refresh_token/expires_in.)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('thaiprompt_refresh_token')->nullable()->after('thaiprompt_token');
            $table->timestamp('thaiprompt_token_expires_at')->nullable()->after('thaiprompt_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['thaiprompt_refresh_token', 'thaiprompt_token_expires_at']);
        });
    }
};
