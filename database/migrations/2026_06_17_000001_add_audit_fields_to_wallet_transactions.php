<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening fields for the wallet ledger (2026-06-17 audit):
 *
 *  - slip_hash       sha256 of the uploaded slip → blocks the same payment
 *                    slip being credited to two different top-ups.
 *  - bank_reference  optional bank txn id (future OCR / manual entry) — a
 *                    second dedup key.
 *  - slip_amount     the amount the admin actually read on the slip when
 *                    approving — lets us flag a mismatch with the amount the
 *                    user typed (amount-tampering guard).
 *  - expires_at      pending top-ups auto-expire so abandoned slips don't
 *                    pile up forever (cleaned by `wallet:cleanup-expired-topups`).
 *  - idempotency_key per-submit key so a double-tap / retry of a paid action
 *                    can't debit twice (unique; NULLs allowed for legacy rows).
 *  + composite index (user_id, type, status) for the per-user pending count.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->char('slip_hash', 64)->nullable()->after('slip_path');
            $table->string('bank_reference', 64)->nullable()->after('slip_hash');
            $table->decimal('slip_amount', 12, 2)->nullable()->after('bank_reference');
            $table->timestamp('expires_at')->nullable()->after('approved_at');
            $table->string('idempotency_key', 64)->nullable()->after('expires_at');

            $table->index('slip_hash');
            $table->index('expires_at');
            $table->unique('idempotency_key');
            $table->index(['user_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['slip_hash']);
            $table->dropIndex(['expires_at']);
            $table->dropIndex(['user_id', 'type', 'status']);
            $table->dropColumn(['slip_hash', 'bank_reference', 'slip_amount', 'expires_at', 'idempotency_key']);
        });
    }
};
