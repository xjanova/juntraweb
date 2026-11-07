<?php

namespace App\Jobs;

use App\Services\Deep\DeepReadingFinisher;
use App\Services\Tarot\TarotReadingFinisher;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * แม่หมอดูดวงเชิงลึก 39฿ ทีละข้อ หลังส่งหน้าผลให้ลูกค้าแล้ว — แบบเดียวกับ InterpretTarotReading
 * dispatchAfterResponse() รันในโปรเซสเดียวกันหลัง fastcgi_finish_request (ไม่ต้องมี queue worker)
 *
 * งานตายกลางทาง (PHP รีสตาร์ต/deploy) → รายการค้าง → readings:sweep-stuck คืนเงินใน 5 นาที
 */
class InterpretDeepReading
{
    use Dispatchable;

    /** คำถามสูงสุดต่อบิล (ตรงกับ DeepReadingController::MAX_QUESTIONS) — ใช้คิดเพดานเวลา */
    private const MAX_QUESTIONS = 3;

    public function __construct(public int $readingId) {}

    public function handle(DeepReadingFinisher $finisher): void
    {
        ignore_user_abort(true);
        @set_time_limit(self::MAX_QUESTIONS * TarotReadingFinisher::BACKGROUND_TIMEOUT + 60);

        $finisher->finish($this->readingId);
    }
}
