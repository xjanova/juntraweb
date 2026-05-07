<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Testimonial;
use App\Models\Zodiac;

class HomeController extends Controller
{
    public function index()
    {
        return view('welcome', [
            'zodiacs' => Zodiac::orderBy('order_index')->get(),
            'testimonials' => Testimonial::approved()->orderBy('order_index')->limit(3)->get(),
            'stats' => [
                'readings' => Setting::get('stat_readings', '250K+'),
                'accuracy' => Setting::get('stat_accuracy', '97.8%'),
                'cards'    => Setting::get('stat_cards', '78'),
            ],
            'hero' => [
                'lede' => Setting::get('hero_lede'),
            ],
        ]);
    }
}
