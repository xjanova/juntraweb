<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('daily_horoscopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zodiac_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('summary');
            $table->text('love')->nullable();
            $table->text('career')->nullable();
            $table->text('money')->nullable();
            $table->text('health')->nullable();
            $table->string('lucky_number', 8)->nullable();
            $table->string('lucky_color', 32)->nullable();
            $table->string('lucky_card', 64)->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->timestamps();
            $table->unique(['zodiac_id', 'date']);
        });

        Schema::create('readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_token', 64)->index();
            $table->enum('type', ['tarot_three', 'tarot_celtic', 'numerology', 'palmistry', 'auspicious', 'chat']);
            $table->string('question', 500)->nullable();
            $table->json('payload')->nullable();
            $table->longText('result')->nullable();
            $table->string('ai_provider', 32)->nullable();
            $table->string('ai_model', 64)->nullable();
            $table->boolean('shared_public')->default(false);
            $table->timestamps();
            $table->index(['type', 'created_at']);
        });

        Schema::create('tarot_reading_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reading_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tarot_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('position_label', 64);
            $table->boolean('reversed')->default(false);
            $table->timestamps();
            $table->unique(['reading_id', 'position']);
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_token', 64)->index();
            $table->string('title', 128)->default('สนทนากับแม่หมอ');
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->longText('content');
            $table->timestamps();
            $table->index(['chat_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('tarot_reading_cards');
        Schema::dropIfExists('readings');
        Schema::dropIfExists('daily_horoscopes');
    }
};
