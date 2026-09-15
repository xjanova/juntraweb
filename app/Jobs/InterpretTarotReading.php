<?php

namespace App\Jobs;

use App\Services\Tarot\TarotReadingFinisher;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * แม่หมออ่านไพ่หลังส่งหน้าผลให้ลูกค้าแล้ว — dispatchAfterResponse() รันในโปรเซสเดียวกัน
 * หลัง fastcgi_finish_request (ไม่ต้องมี queue worker) คำขอหน้าเว็บจึงไม่ชนเพดาน ~60 วิของ Apache
 *
 * งานตายกลางทาง (PHP รีสตาร์ต/deploy) → รายการค้าง → readings:sweep-stuck คืนเงินใน 5 นาที
 */
class InterpretTarotReading
{
    use Dispatchable;

    public function __construct(public int $readingId) {}

    public function handle(TarotReadingFinisher $finisher): void
    {
        // ส่งหน้าให้ลูกค้าไปแล้ว — ห้ามให้การตัดการเชื่อมต่อ/เพดานเวลาฆ่างานกลางคัน
        ignore_user_abort(true);
        @set_time_limit(TarotReadingFinisher::BACKGROUND_TIMEOUT + 60);

        $finisher->interpret($this->readingId);
    }
}
