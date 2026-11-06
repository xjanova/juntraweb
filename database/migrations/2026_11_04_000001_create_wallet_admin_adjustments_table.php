<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สมุดตรวจสอบ (audit trail) ของทุกรายการที่แอดมินแตะยอดวอลเลตของผู้ใช้โดยตรง
 * (เติม / หัก / คืนเงิน / ย้อนรายการ / โอนระหว่างบัญชี) — ดู AdminWalletService
 *
 * แต่ละแถวบอก: ใครทำ · ทำกับวอลเลตใคร · ยอดก่อน/หลัง · เหตุผล · IP · UA · เวลา
 * unique(idempotency_key, wallet_id) — ส่งฟอร์มเดิมซ้ำจะไม่เติม/หักเงินซ้ำ
 *
 * ยอดเงินจริงยังอยู่ใน wallet_transactions เหมือนเดิม (wallet:reconcile ใช้ตารางนั้น)
 * ตารางนี้เป็นหลักฐานเพิ่ม ห้ามแก้/ลบแถวจากหน้าจอใด ๆ
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wallet_admin_adjustments')) {
            return;
        }

        Schema::create('wallet_admin_adjustments', function (Blueprint $table) {
            $table->id();
            // ลบวอลเลต/ผู้ใช้ (force delete) แล้วหลักฐานต้องยังอยู่ → ว่างได้ + nullOnDelete (ไม่ใช่ cascade)
            // ตัวตนเจ้าของยังอยู่ที่ user_id (ไม่มี FK)
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            // เจ้าของวอลเลต (เก็บซ้ำไว้ค้นหาเร็ว)
            $table->unsignedBigInteger('user_id')->index();
            // แอดมินที่ทำรายการ — ถ้าบัญชีแอดมินถูกลบ ประวัติยังอยู่ (ชื่ออยู่ใน admin_name)
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name')->nullable();
            // credit | debit | refund | rollback | transfer_out | transfer_in
            $table->string('action', 20)->index();
            // ยอดที่ขยับ (บวกเสมอ — ทิศทางดูจาก action / ยอดก่อน-หลัง)
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->text('reason');
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            // ธุรกรรมต้นฉบับ (ย้อนรายการ / คืนเงินของรายการหัก) — กันย้อนรายการเดิมซ้ำ
            $table->unsignedBigInteger('original_transaction_id')->nullable()->index();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['idempotency_key', 'wallet_id'], 'waa_idem_wallet_unique');
            $table->index(['wallet_id', 'created_at'], 'waa_wallet_created_idx');
            $table->index(['admin_id', 'created_at'], 'waa_admin_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_admin_adjustments');
    }
};
