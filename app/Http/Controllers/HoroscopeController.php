<?php

namespace App\Http\Controllers;

use App\Models\ChineseZodiac;
use App\Models\DailyHoroscope;
use App\Models\Zodiac;
use App\Services\DailyHoroscopeWriter;
use Carbon\Carbon;

class HoroscopeController extends Controller
{
    public function index()
    {
        return view('pages.horoscope.index', [
            'zodiacs' => Zodiac::orderBy('order_index')->get(),
        ]);
    }

    public function show(Zodiac $zodiac, DailyHoroscopeWriter $writer)
    {
        $today = Carbon::today();

        // Use whereDate so we match regardless of whether the column was stored
        // as a date or a full datetime (different drivers/casts produce both).
        $horoscope = DailyHoroscope::where('zodiac_id', $zodiac->id)
            ->whereDate('date', $today)
            ->first();

        if (!$horoscope) {
            // เขียนวันละครั้งต่อราศีแล้วเก็บลง DB — คนที่เปิดคนถัดไปอ่านของเดิม
            // (ดู App\Services\DailyHoroscopeWriter ว่าทำไมถึงไม่ไหลไปท่อแชท)
            $payload = $writer->write($zodiac, $today);
            try {
                $horoscope = DailyHoroscope::create(array_merge($payload, [
                    'zodiac_id' => $zodiac->id,
                    'date'      => $today,
                ]));
            } catch (\Illuminate\Database\QueryException $e) {
                // A concurrent first-view inserted the row between our read and
                // write (the [zodiac_id, date] unique index fires). Use theirs
                // instead of bubbling a raw duplicate-key 500 to the visitor.
                $horoscope = DailyHoroscope::where('zodiac_id', $zodiac->id)
                    ->whereDate('date', $today)
                    ->firstOrFail();
            }
        }

        return view('pages.horoscope.show', [
            'zodiac' => $zodiac,
            'horoscope' => $horoscope,
            'allZodiacs' => Zodiac::orderBy('order_index')->get(),
        ]);
    }

    public function thai()
    {
        return view('pages.horoscope.thai', [
            'chineseZodiacs' => ChineseZodiac::orderBy('order_index')->get(),
        ]);
    }
}
