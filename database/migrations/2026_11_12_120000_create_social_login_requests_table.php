<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_login_requests', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->char('code_challenge', 43);
            $table->string('provider', 16);
            $table->string('device', 64);
            $table->string('referral_code', 64)->nullable();
            $table->char('browser_hash', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_login_requests');
    }
};
