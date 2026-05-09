<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum personal_access_tokens — required for the mobile API
 * (juntra Flutter app authenticates with Bearer tokens).
 *
 * Functionally identical to the vendor migration shipped with
 * laravel/sanctum, kept in our migration tree so `php artisan migrate`
 * is the single source of truth and we don't depend on vendor publish
 * being run before deploy.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
