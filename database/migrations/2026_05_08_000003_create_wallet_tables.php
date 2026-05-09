<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 12, 2)->default(0);
            $table->char('currency', 3)->default('THB');
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            // 'topup' (+), 'debit' (-), 'refund' (+), 'adjustment' (signed)
            $table->string('type', 16);

            // 'pending' (slip awaiting admin) | 'success' | 'failed' | 'refunded'
            $table->string('status', 16)->default('success');

            // Always stored as POSITIVE for topup/refund and NEGATIVE for debit so
            // SUM(amount) over success rows = current balance. Audit-friendly.
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2)->nullable();

            $table->string('description', 255)->nullable();
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('method', 32)->nullable(); // 'promptpay' | 'manual' | 'gateway' | 'system'
            $table->string('slip_path')->nullable();
            $table->string('reference_code', 24)->nullable()->unique();
            $table->json('meta')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['type', 'status']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
