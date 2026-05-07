<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use App\Models\TarotCard;
use App\Services\AiOracle;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TarotController extends Controller
{
    public function index()
    {
        return view('pages.tarot.index', [
            'cards' => TarotCard::where('active', true)->get(),
        ]);
    }

    public function threeCardSpread(Request $request, AiOracle $oracle)
    {
        $request->validate([
            'question' => 'nullable|string|max:500',
        ]);

        $cards = TarotCard::where('active', true)->inRandomOrder()->limit(3)->get();
        $positions = ['อดีต', 'ปัจจุบัน', 'อนาคต'];

        return $this->createReading($request, 'tarot_three', $cards, $positions, $oracle);
    }

    public function celticCross(Request $request, AiOracle $oracle)
    {
        $request->validate([
            'question' => 'nullable|string|max:500',
        ]);

        $cards = TarotCard::where('active', true)->inRandomOrder()->limit(10)->get();
        // Canonical Rider-Waite Celtic Cross positions (Stuart Kaplan ordering):
        //   1 ปัจจุบัน · 2 ขวางกั้น · 3 รากฐาน · 4 อดีต · 5 เป้าหมาย
        //   6 อนาคตอันใกล้ · 7 ตัวตน · 8 สิ่งแวดล้อม · 9 ความหวัง/ความกลัว · 10 ผลลัพธ์
        $positions = [
            'สถานการณ์ปัจจุบัน',
            'สิ่งที่ขวางกั้น',
            'รากฐานของเรื่อง',
            'อดีตที่ผ่านมา',
            'เป้าหมาย / สิ่งที่อาจเกิด',
            'อนาคตอันใกล้',
            'ตัวตนของคุณ',
            'สิ่งแวดล้อมรอบตัว',
            'ความหวังและความกลัว',
            'ผลลัพธ์สุดท้าย',
        ];

        return $this->createReading($request, 'tarot_celtic', $cards, $positions, $oracle);
    }

    private function createReading(Request $request, string $type, $cards, array $positions, AiOracle $oracle)
    {
        $reading = Reading::create([
            'user_id' => $request->user()?->id,
            'session_token' => Str::uuid()->toString(),
            'type' => $type,
            'question' => $request->input('question'),
            'payload' => ['positions' => $positions],
        ]);

        foreach ($cards as $i => $card) {
            $reversed = (bool) random_int(0, 1);
            $reading->tarotCards()->create([
                'tarot_card_id' => $card->id,
                'position' => $i + 1,
                'position_label' => $positions[$i] ?? "ตำแหน่ง " . ($i + 1),
                'reversed' => $reversed,
            ]);
        }

        $reading->load('tarotCards.card');
        $reading->result = $oracle->interpretTarotReading($reading);
        $reading->ai_provider = $oracle->provider();
        $reading->ai_model = $oracle->model();
        $reading->save();

        return redirect()->route('tarot.show', $reading);
    }

    public function show(Reading $reading)
    {
        if (!in_array($reading->type, ['tarot_three', 'tarot_celtic'])) {
            abort(404);
        }
        $reading->load('tarotCards.card');
        return view('pages.tarot.result', compact('reading'));
    }
}
