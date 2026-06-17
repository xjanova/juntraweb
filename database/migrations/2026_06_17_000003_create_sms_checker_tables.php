<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS Checker payment gateway tables (thaiprompt-smschecker-v1).
 *
 *  - sms_checker_devices: each registered Android device with its api_key +
 *    secret_key (the secret drives AES-GCM + HMAC; never exposed after setup).
 *  - sms_payment_notifications: every bank-SMS the device forwards, plus the
 *    match result against a pending wallet top-up.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sms_checker_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64)->unique();          // SMSCHK-XXXXXXXX
            $table->string('device_name')->nullable();
            $table->string('api_key', 64)->unique();            // bin2hex(32)
            $table->string('secret_key', 64);                   // bin2hex(32)
            $table->string('platform', 16)->default('android');
            $table->string('app_version', 32)->nullable();
            $table->string('status', 16)->default('active');     // active|inactive|blocked
            $table->string('approval_mode', 16)->default('auto'); // auto|manual
            $table->string('fcm_token')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64)->index();
            $table->string('bank', 32)->nullable();
            $table->string('type', 16)->default('credit');       // credit|debit
            $table->decimal('amount', 12, 2);
            $table->string('account_number', 32)->nullable();
            $table->string('sender_or_receiver')->nullable();
            $table->string('reference_number', 64)->nullable();
            $table->string('nonce', 64)->nullable()->unique();   // replay guard
            $table->timestamp('sms_timestamp')->nullable();
            // pending → matched → confirmed | rejected | expired
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('matched_transaction_id')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['type', 'amount', 'status']);
            $table->index('matched_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_payment_notifications');
        Schema::dropIfExists('sms_checker_devices');
    }
};
