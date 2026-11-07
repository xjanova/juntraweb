<?php

use App\Support\Astrology;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🇹🇭 ย้ายราศีในโปรไฟล์ลูกค้าไปเป็นราศีแบบไทย (นิรายนะ) ให้ตรงกับผังดวงของบอทแม่หมอ
 *
 * เปลี่ยนเฉพาะคนที่ "ระบบเลือกให้" = ราศีที่เก็บไว้ตรงกับที่คิดจากวันเกิดแบบสากล (หรือยังว่าง)
 * คนที่เลือกราศีเองจนไม่ตรงกับสูตรเดิม ถือว่าตั้งใจ — ไม่แตะ
 * รันซ้ำได้: แถวที่ย้ายแล้วจะไม่ตรงกับสูตรสากลอีก (ยกเว้นวันที่สองระบบตรงกันซึ่งได้ค่าเดิมอยู่แล้ว)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profiles') || ! Schema::hasColumn('profiles', 'zodiac_slug')) {
            return;
        }

        DB::table('profiles')->whereNotNull('birth_date')
            ->select(['id', 'birth_date', 'birth_time', 'zodiac_slug'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        $birth = Carbon::parse($row->birth_date);
                    } catch (Throwable) {
                        continue;
                    }
                    if ($row->zodiac_slug !== null && $row->zodiac_slug !== Astrology::westernZodiacSlug($birth)) {
                        continue; // เลือกเอง
                    }
                    $thai = Astrology::thaiZodiacSlug($birth, $row->birth_time);
                    if ($thai !== null && $thai !== $row->zodiac_slug) {
                        DB::table('profiles')->where('id', $row->id)->update(['zodiac_slug' => $thai]);
                    }
                }
            });
    }

    /** ย้อนไม่ได้แบบแม่นยำ (ไม่รู้ว่าแถวไหนเคยเป็นค่าอะไร) — ไม่ทำอะไร */
    public function down(): void {}
};
