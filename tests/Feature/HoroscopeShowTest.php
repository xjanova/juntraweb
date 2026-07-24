<?php

namespace Tests\Feature;

use App\Models\DailyHoroscope;
use App\Models\Zodiac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: /horoscope/{slug} 500'd on every zodiac because the view called
 * __thaiElement() before its conditional (if !function_exists) declaration —
 * a conditional function is NOT hoisted, so the call hit an undefined function.
 */
class HoroscopeShowTest extends TestCase
{
    use RefreshDatabase;

    private function makeZodiac(string $slug = 'taurus', string $element = 'Earth'): Zodiac
    {
        return Zodiac::create([
            'slug' => $slug, 'name_en' => 'Taurus', 'name_th' => 'พฤษภ',
            'glyph' => '♉', 'element' => $element, 'date_range' => '20 เม.ย. – 20 พ.ค.',
            'order_index' => 2, 'traits_th' => 'มั่นคง อดทน',
        ]);
    }

    public function test_zodiac_page_renders_and_shows_thai_element(): void
    {
        $this->makeZodiac('taurus', 'Earth');

        $response = $this->get('/horoscope/taurus');

        $response->assertOk();
        $response->assertSee('พฤษภ');
        $response->assertSee('ธาตุดิน'); // Earth → ดิน, proving the element map works
    }

    public function test_zodiac_page_generates_and_persists_daily_horoscope(): void
    {
        $zodiac = $this->makeZodiac();

        $this->get('/horoscope/taurus')->assertOk();

        // First view generates + stores today's row (heuristic fallback, no AI key).
        $this->assertDatabaseHas('daily_horoscopes', ['zodiac_id' => $zodiac->id]);
        $this->assertSame(1, DailyHoroscope::count());

        // Second view reuses the same row (no duplicate insert).
        $this->get('/horoscope/taurus')->assertOk();
        $this->assertSame(1, DailyHoroscope::count());
    }

    public function test_all_four_elements_map_to_thai(): void
    {
        $cases = ['Fire' => 'ธาตุไฟ', 'Earth' => 'ธาตุดิน', 'Air' => 'ธาตุลม', 'Water' => 'ธาตุน้ำ'];
        $i = 10;
        foreach ($cases as $en => $th) {
            $slug = 'z' . $i;
            $this->makeZodiac($slug, $en);
            $this->get("/horoscope/{$slug}")->assertOk()->assertSee($th);
            $i++;
        }
    }
}
