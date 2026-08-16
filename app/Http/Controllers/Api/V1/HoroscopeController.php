<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyHoroscope;
use App\Models\ChineseZodiac;
use App\Models\Zodiac;
use App\Services\DailyHoroscopeWriter;
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
    /**
     * ภาพประจำราศีที่เว็บใช้อยู่แล้ว — เช็คไฟล์จริงก่อนเสมอ
     *
     * เดิม API ไม่ส่งคีย์รูปเลย แอพจึงวาดได้แค่สัญลักษณ์ ♈ บนกล่องม่วง
     * ทั้งที่ของสวยมีอยู่บนเซิร์ฟเวอร์แล้ว 12 ใบ (เช็ค file_exists แบบเดียวกับ
     * horoscope/show.blade.php เพื่อไม่ยิง 404 ให้ไคลเอนต์)
     */
    private function artUrl(string $slug): ?string
    {
        $rel = "images/juntra/art/zodiac/{$slug}.webp";

        return file_exists(public_path($rel)) ? asset($rel) : null;
    }

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
                'image_url'  => $this->artUrl($z->slug),
            ])->values(),
        ]);
    }

    /**
     * GET /v1/horoscope/thai-zodiac — ปีนักษัตรทั้ง 12 + ปีนักษัตรของปีนี้
     *
     * เว็บมีหน้านี้มานานแล้ว (`/horoscope/thai-zodiac`) แต่ไม่มี endpoint ให้แอพ
     * ผู้ใช้แอพจึงเข้าถึงเนื้อหาส่วนนี้ไม่ได้เลย — และห้ามให้แอพคำนวณปีนักษัตรเอง
     * (กฎเหล็กข้อ 1: ค่าที่อ้างเป็นผลคำนวณต้องมาจากฝั่งเซิร์ฟเวอร์ที่เดียว)
     *
     * ปีนักษัตรนับตามปีนักษัตรไทยที่เริ่มต้นตามปฏิทินจันทรคติ — ที่นี่ใช้การนับ
     * แบบปีคริสต์ศักราชหารเศษ ซึ่งเป็นวิธีที่หน้าเว็บใช้อยู่แล้ว (ชวด = ค.ศ. 2020)
     * ค่าที่ได้จึงตรงกับที่ผู้ใช้เห็นบนเว็บเป๊ะ
     */
    public function thaiZodiac(): JsonResponse
    {
        $rows = ChineseZodiac::orderBy('order_index')->get();

        // 2020 = ปีชวด (order_index 1) → ((ปี - 2020) mod 12) + 1
        $year  = (int) Carbon::now(\App\Support\ThaiAstro::TZ)->year;
        $index = ((($year - 2020) % 12) + 12) % 12 + 1;

        return response()
            ->json([
                'data' => [
                    'year'         => $year,
                    'current_slug' => $rows->firstWhere('order_index', $index)?->slug,
                    'signs'        => $rows->map(fn (ChineseZodiac $z) => [
                        'slug'        => $z->slug,
                        'name_th'     => $z->name_th,
                        'name_en'     => $z->name_en,
                        'glyph'       => $z->glyph,
                        'order_index' => $z->order_index,
                        'traits_th'   => $z->traits_th,
                    ])->values(),
                ],
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /** GET /v1/horoscope/{zodiac} — today's reading for one sign. */
    public function show(Zodiac $zodiac, DailyHoroscopeWriter $writer): JsonResponse
    {
        $today = Carbon::today();

        $h = DailyHoroscope::where('zodiac_id', $zodiac->id)
            ->whereDate('date', $today)
            ->first();

        if (!$h) {
            // เครื่องเขียนตัวเดียวกับเว็บ — แอพกับเว็บต้องเห็นดวงวันเดียวกัน
            $payload = $writer->write($zodiac, $today);
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
                    'image_url'  => $this->artUrl($zodiac->slug),
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
