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
        ];

        foreach ($settings as [$key, $value, $group, $encrypted]) {
            Setting::put($key, $value, $group, $encrypted);
        }
    }
}
