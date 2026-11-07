<?php

namespace App\Console\Commands;

use App\Models\Zodiac;
use App\Services\DailyHoroscopeWriter;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * 🌅 อุ่นดวงรายวันทั้ง 12 ราศีหลังเที่ยงคืน — คนแรกของวันไม่ต้องรอ AI และไม่โดนตัดที่ 60 วิ
 *
 * หน้าเว็บรอ Thaiprompt ได้แค่ ~45 วิ (Apache ตัด 60) ถ้าคนแรกของวันเปิดตอน AI ช้า
 * แถวที่เขียนเองจากธาตุ/ดิถีจะถูกเก็บไว้ทั้งวัน ทั้งที่ฉบับจากดาวจริงเสร็จทีหลัง
 * คำสั่งนี้รันเบื้องหลัง รอได้นาน จึงได้ฉบับจากดาวจริงเกือบทุกวัน
 * ราศีที่มีแถวของวันนี้แล้วข้าม (รันซ้ำได้ ไม่เขียนทับ)
 */
class HoroscopeWarmCommand extends Command
{
    protected $signature = 'horoscope:warm {--date= : Y-m-d (ค่าเริ่มต้น = วันนี้)}';

    protected $description = 'เขียนดวงรายวันของทั้ง 12 ราศีล่วงหน้า (ข้ามราศีที่มีแล้ว)';

    public function handle(DailyHoroscopeWriter $writer): int
    {
        $date = $this->option('date') ? Carbon::parse((string) $this->option('date'))->startOfDay() : Carbon::today();

        $ai = 0;
        foreach (Zodiac::orderBy('order_index')->get() as $zodiac) {
            $row = $writer->today($zodiac, $date, timeout: 120);
            $ai += $row->ai_generated ? 1 : 0;
        }
        $this->info("ดวง {$date->toDateString()}: จาก Thaiprompt {$ai} ราศี");

        return self::SUCCESS;
    }
}
