<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every alert we push to the admin Telegram chat.
 *
 * Two reasons it exists. "An alert came through on my phone — what was it, and has it happened
 * before?" must be answerable from the admin page (the throttle key lives hashed in the cache, so
 * nothing else can answer it). And an alert about a thing — an order, a top-up — has to be findable
 * again later, so that when the thing changes (paid, rejected, handled by a teammate) the card in
 * the chat can be updated in place: that is what `subject` and `message_id` are for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20)->index();
            $table->string('level', 12);
            // The un-hashed throttle key ('order:42:new', 'login-brute:1.2.3.4'…).
            $table->string('alert_key', 120)->nullable()->index();
            // What the alert is about ('order:42'), so a later change can find and edit its card.
            $table->string('subject', 64)->nullable()->index();
            $table->string('title', 255);
            $table->text('body');
            $table->boolean('ok')->default(false);
            $table->string('error', 255)->nullable();
            $table->string('chat_id', 64)->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_alerts');
    }
};
