<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * แปลง markdown เป็น HTML แบบปลอดภัยสำหรับข้อความที่มาจาก AI
 *
 * คำทำนายและคำตอบของแม่หมอใช้ **ตัวหนา**, _เอียง_ และ bullet เป็นปกติ
 * ถ้าโชว์ดิบผู้ใช้จะเห็นดอกจันเต็มไปหมด — ส่วน html_input => strip
 * ตัด HTML ที่ฝังมากับคำตอบทิ้ง (กัน XSS จาก output ของโมเดล)
 *
 * ใช้ร่วมกันทั้ง <x-reading-prose>, หน้าแชท (server render) และ JSON
 * ที่ส่งให้หน้าแชท/แอพ เพื่อให้ข้อความเดียวกันหน้าตาเหมือนกันทุกที่
 */
class Markdown
{
    public static function safe(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        return (string) Str::markdown($text, [
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
            'renderer'           => ['soft_break' => "<br />\n"],
        ]);
    }
}
