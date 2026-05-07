<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->date('birth_date')->nullable();
            $table->time('birth_time')->nullable();
            $table->string('birth_place', 191)->nullable();
            $table->string('zodiac_slug', 32)->nullable();
            $table->string('chinese_zodiac_slug', 32)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('line_id', 64)->nullable();
            $table->string('avatar_path', 255)->nullable();
            $table->boolean('subscribed')->default(false);
            $table->timestamps();
        });

        Schema::create('zodiacs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name_en', 32);
            $table->string('name_th', 64);
            $table->string('glyph', 8);
            $table->string('element', 16);
            $table->string('ruler', 32)->nullable();
            $table->string('date_range', 64);
            $table->unsignedTinyInteger('order_index');
            $table->text('traits_th');
            $table->timestamps();
        });

        Schema::create('chinese_zodiacs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name_th', 32);
            $table->string('name_en', 32);
            $table->string('glyph', 8);
            $table->unsignedTinyInteger('order_index');
            $table->text('traits_th');
            $table->timestamps();
        });

        Schema::create('tarot_cards', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name_en', 64);
            $table->string('name_th', 64);
            $table->enum('arcana', ['major', 'minor']);
            $table->enum('suit', ['major', 'wands', 'cups', 'swords', 'pentacles']);
            $table->unsignedTinyInteger('number');
            $table->string('image_path', 191)->nullable();
            $table->string('keywords_th', 255)->nullable();
            $table->text('upright_meaning_th');
            $table->text('reversed_meaning_th');
            $table->text('love_th')->nullable();
            $table->text('career_th')->nullable();
            $table->text('money_th')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();
            $table->string('group', 32)->default('general');
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('service', 96);
            $table->unsignedTinyInteger('rating')->default(5);
            $table->text('message');
            $table->boolean('approved')->default(true);
            $table->unsignedInteger('order_index')->default(0);
            $table->timestamps();
        });

        Schema::create('auspicious_dates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('type', 32);
            $table->string('title', 128);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['date', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auspicious_dates');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('tarot_cards');
        Schema::dropIfExists('chinese_zodiacs');
        Schema::dropIfExists('zodiacs');
        Schema::dropIfExists('profiles');
    }
};
