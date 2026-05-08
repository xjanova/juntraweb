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

        // Use whereDate so we match regardless of whether the column was stored
        // as a date or a full datetime (different drivers/casts produce both).
        $horoscope = DailyHoroscope::where('zodiac_id', $zodiac->id)
            ->whereDate('date', $today)
            ->first();

        if (!$horoscope) {
            $payload = $oracle->generateDailyHoroscope($zodiac, $today);
            $horoscope = DailyHoroscope::create(array_merge($payload, [
                'zodiac_id' => $zodiac->id,
                'date'      => $today,
            ]));
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
