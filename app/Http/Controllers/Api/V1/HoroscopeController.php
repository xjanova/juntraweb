<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyHoroscope;
use App\Models\Zodiac;
use App\Services\AiOracle;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Mobile daily horoscope — JSON parity to the web HoroscopeController so the
 * app's home "ดวงรายวัน" shows the same AI-generated content per zodiac
 * instead of hardcoded strings. Free + read-only (no auth, no debit); the
 * row is cached per zodiac/date and generated on first view.
 */
class HoroscopeController extends Controller
{
    /** GET /v1/horoscope — the 12 zodiac signs. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Zodiac::orderBy('order_index')->get()->map(fn (Zodiac $z) => [
                'slug'       => $z->slug,
                'name_th'    => $z->name_th,
                'name_en'    => $z->name_en,
                'glyph'      => $z->glyph,
                'element'    => $z->element,
                'date_range' => $z->date_range,
            ])->values(),
        ]);
    }

    /** GET /v1/horoscope/{zodiac} — today's reading for one sign. */
    public function show(Zodiac $zodiac, AiOracle $oracle): JsonResponse
    {
        $today = Carbon::today();

        $h = DailyHoroscope::where('zodiac_id', $zodiac->id)
            ->whereDate('date', $today)
            ->first();

        if (!$h) {
            $payload = $oracle->generateDailyHoroscope($zodiac, $today);
            try {
                $h = DailyHoroscope::create(array_merge($payload, [
                    'zodiac_id' => $zodiac->id,
                    'date'      => $today,
                ]));
            } catch (QueryException) {
                // Concurrent first-view created it — use theirs.
                $h = DailyHoroscope::where('zodiac_id', $zodiac->id)
                    ->whereDate('date', $today)
                    ->firstOrFail();
            }
        }

        return response()->json([
            'data' => [
                'zodiac' => [
                    'slug'       => $zodiac->slug,
                    'name_th'    => $zodiac->name_th,
                    'name_en'    => $zodiac->name_en,
                    'glyph'      => $zodiac->glyph,
                    'element'    => $zodiac->element,
                    'date_range' => $zodiac->date_range,
                ],
                'date'         => $today->toDateString(),
                'summary'      => $h->summary,
                'love'         => $h->love,
                'career'       => $h->career,
                'money'        => $h->money,
                'health'       => $h->health,
                'lucky_number' => $h->lucky_number,
                'lucky_color'  => $h->lucky_color,
                'lucky_card'   => $h->lucky_card,
            ],
        ]);
    }
}
