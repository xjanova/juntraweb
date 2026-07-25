<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['site_name', 'แม่หมอจันทรา', 'general', false],
            ['site_tagline', 'ทำนายดวงด้วย AI ศักดิ์สิทธิ์', 'general', false],
            ['hero_lede', 'แม่หมอจันทรา ผสานศาสตร์ดวงดาวโบราณกับปัญญาประดิษฐ์ล้ำสมัย อ่านดวงชะตามนุษย์จากอดีต ปัจจุบัน สู่อนาคต ด้วยความแม่นยำที่เหนือคำบรรยาย', 'homepage', false],
            ['stat_readings', '250K+', 'homepage', false],
            ['stat_accuracy', '97.8%', 'homepage', false],
            ['stat_cards', '78', 'homepage', false],
            ['contact_line', '@chantra', 'contact', false],
            ['contact_facebook', '#', 'contact', false],
            ['contact_email', 'hello@xn--82c4af5bzdj.online', 'contact', false],
            ['ai_provider', 'gemini', 'ai', false],
            ['ai_model', 'gemini-2.0-flash-exp', 'ai', false],
            ['ai_api_key', '', 'ai', true],
            ['ai_system_prompt', 'คุณคือ "แม่หมอจันทรา" หมอดูออนไลน์ที่สุภาพ อบอุ่น และให้คำปรึกษาด้วยมุมมองสร้างสรรค์ ตอบเป็นภาษาไทยล้วน เข้าใจง่าย ไม่ขู่ ไม่ทำให้ผู้ใช้กลัว และให้คำแนะนำเชิงสร้างสรรค์เสมอ', 'ai', false],

            // Wallet pricing — admin can override per-feature via /admin/wallet-settings.
            // Pricing::for() reads these first, falls back to config/pricing.php.
            ['pricing_tarot_three',  '19', 'pricing', false],
            ['pricing_tarot_celtic', '99', 'pricing', false],
            ['pricing_numerology',   '9',  'pricing', false],
            ['pricing_palmistry',    '29', 'pricing', false],
            ['pricing_auspicious',   '19', 'pricing', false],
            ['pricing_chat_message', '2',  'pricing', false],
            ['promptpay_id',         '',   'pricing', false],
            ['promptpay_name',       '',   'pricing', false],

            // 🎁 (2026-07-26) ดูดวงฟรี 1 ใบ — ปลายทางปุ่ม "ดูดวงฟรี" จากบอท FB/LINE
            // ต้องมีแถวจริงใน DB ตั้งแต่แรก ไม่งั้น Setting::get() จะ cache
            // ค่า default ไว้ตลอดกาล (rememberForever ครอบทั้ง closure)
            ['free_reading_enabled',   '1',   'free_reading', false],
            ['free_reading_daily_cap', '0',   'free_reading', false],  // 0 = ไม่จำกัด
            ['free_reading_max_chars', '500', 'free_reading', false],
            ['free_reading_batch',     'v1',  'free_reading', false],  // เลื่อนรอบ = แจกใหม่ทุกคน
        ];

        // CRITICAL: deploy.sh re-runs this seeder on every push. Use
        // putIfMissing() so we only INSERT rows that don't exist —
        // never overwrite a value the admin has edited via /admin.
        // Past bug: site_name kept resetting to "แม่หมอจันทรา" every
        // deploy because we used Setting::put() (updateOrCreate).
        foreach ($settings as [$key, $value, $group, $encrypted]) {
            Setting::putIfMissing($key, $value, $group, $encrypted);
        }
    }
}
