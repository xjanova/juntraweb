<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * การซื้อแพ็กเครดิตผ่าน Google Play Billing — หนึ่งแถวต่อหนึ่ง purchase token
 *
 * token_hash (sha256 ของ purchase token) เป็น unique = หัวใจของการกันเติมซ้ำ: แอพส่ง token เดิมมากี่รอบ
 * (เน็ตหลุด / เปิดแอพใหม่แล้วแอพเก็บตกการซื้อที่ยังไม่ consume) ก็เติมได้ครั้งเดียว
 * token จริงยาวเกินกว่าจะทำดัชนีได้ทุกฐานข้อมูล จึงเก็บตัวเต็มในคอลัมน์ text แยก (ใช้ถาม Google ภายหลัง)
 *
 * สถานะ: credited (เติมแล้ว) · voided (ลูกค้าขอคืนเงิน/chargeback — ดึงเครดิตคืนแล้วเท่าที่เหลือ)
 * consumed_at ว่าง = ยังไม่ได้บอก Google ว่าใช้แล้ว → googleplay:consume-pending ทำซ้ำให้
 * (ต้อง consume ภายใน 3 วัน ไม่งั้น Google คืนเงินลูกค้าอัตโนมัติ)
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('google_play_purchases')) {
            return;
        }

        Schema::create('google_play_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('product_id', 100);
            $table->char('token_hash', 64)->unique();
            $table->text('purchase_token');
            $table->string('order_id', 100)->nullable()->index();
            $table->decimal('credits', 12, 2);
            $table->string('status', 16)->default('credited')->index();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->unsignedSmallInteger('consume_attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 64)->nullable();
            $table->string('region_code', 8)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_play_purchases');
    }
};
