<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🪬 (2026-11-03) เจ้าของกำหนดราคาห้องคุณไสย 139 (เดิม 99)
 *
 * ราคาที่ใช้จริงอ่านจาก Setting `pricing_tarot_kunsai` ก่อน config — หน้าหลังบ้าน (WalletSettings)
 * บันทึกราคาของทุกแพ็กเกจลง Setting ตอนกดบันทึก จึงมีโอกาสสูงที่ prod มีแถว "99" อยู่แล้ว
 * แก้แค่ config แล้วราคาจริงไม่เปลี่ยน
 *
 * ปรับเฉพาะแถวที่ยังเป็นราคาเดิม (99) — แอดมินตั้งราคาอื่นไว้เอง = ไม่แตะ
 * ไม่มีแถว = ไม่ต้องทำอะไร (config ใหม่ = 139 อยู่แล้ว)
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->swap('99', '139');
    }

    public function down(): void
    {
        $this->swap('139', '99');
    }

    private function swap(string $from, string $to): void
    {
        $changed = DB::table('settings')
            ->where('key', 'pricing_tarot_kunsai')
            ->whereIn('value', [$from, $from . '.00', $from . '.0'])
            ->update(['value' => $to, 'updated_at' => now()]);

        if ($changed > 0) {
            // Setting::get() จำค่าไว้ตลอดกาลใน cache — ต้องล้างเอง ไม่งั้นหน้าเว็บยังขาย 99
            \App\Models\Setting::forgetCached('pricing_tarot_kunsai');
        }
    }
};
