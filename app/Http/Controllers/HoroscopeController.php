<?php

namespace App\Http\Controllers;

use App\Models\ChineseZodiac;
use App\Models\DailyHoroscope;
use App\Models\Zodiac;
use App\Services\AiOracle;
use Carbon\Carbon;

class HoroscopeController extends Controller
{
    public function index()
    {
        return view('pages.horoscope.index', [
            'zodiacs' => Zodiac::orderBy('order_index')->get(),
        ]);
    }

    public function show(Zodiac $zodiac, AiOracle $oracle)
    {
        $today = Carbon::today();
        $horoscope = DailyHoroscope::firstOrCreate(
            ['zodiac_id' => $zodiac->id, 'date' => $today->toDateString()],
            $oracle->generateDailyHoroscope($zodiac, $today)
        );

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
