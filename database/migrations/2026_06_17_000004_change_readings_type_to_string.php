<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original `readings.type` was a fixed enum
 * (tarot_three, tarot_celtic, numerology, palmistry, auspicious, chat).
 *
 * Tarot now supports a data-driven set of spreads (config/tarot_spreads.php:
 * single, three, love, career, decision, celtic, year, …) and each saves as
 * "tarot_{key}". A closed enum would reject every new spread, so widen the
 * column to a plain string. The ['type','created_at'] index is unaffected
 * (the column keeps its name).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->string('type', 32)->change();
        });
    }

    public function down(): void
    {
        // Best-effort revert. Any rows whose type is outside the original
        // enum set would block this; collapse unknown tarot rows first.
        Schema::table('readings', function (Blueprint $table) {
            $table->enum('type', [
                'tarot_three', 'tarot_celtic', 'numerology', 'palmistry', 'auspicious', 'chat',
            ])->change();
        });
    }
};
