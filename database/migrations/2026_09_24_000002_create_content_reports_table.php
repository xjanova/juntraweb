<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลูกค้ารายงานเนื้อหาที่ AI (แม่หมอ) สร้าง — คำทำนายหรือข้อความในแชทที่ไม่เหมาะสม/อันตราย/ผิด
 *
 * Google Play (นโยบาย AI-Generated Content) บังคับให้แอพที่สร้างเนื้อหาด้วย AI มีปุ่มรายงาน
 * ในแอพโดยไม่ต้องออกจากแอพ และผู้พัฒนาต้องเอารายงานไปใช้ปรับปรุงการกรองเนื้อหา
 * snapshot เก็บข้อความที่ถูกรายงาน ณ ตอนนั้น (ต้นฉบับอาจถูกลบตามการลบบัญชีภายหลัง)
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('content_reports')) {
            return;
        }

        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 20);            // reading | chat_message
            $table->unsignedBigInteger('subject_id');
            $table->string('reason', 20);                  // offensive | harmful | inaccurate | other
            $table->text('note')->nullable();
            $table->text('snapshot')->nullable();
            $table->string('status', 12)->default('open')->index();   // open | resolved | dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
    }
};
