<?php

namespace App\Http\Controllers;

use App\Models\ChineseZodiac;
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
        // อ่านของวันนี้ ไม่มีค่อยเขียนแล้วเก็บ — ตัวเดียวกับแอพ และคำสั่ง horoscope:warm
        $horoscope = $writer->today($zodiac, $today);

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
