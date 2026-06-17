<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete users so deleting an account NEVER cascade-wipes the wallet
 * ledger (wallets / wallet_transactions FK cascadeOnDelete). A money ledger
 * must survive account closure (Thai bookkeeping retention ~7y). With the
 * User model soft-deleting, the user row stays, so the cascade never fires
 * and the financial history is preserved.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
