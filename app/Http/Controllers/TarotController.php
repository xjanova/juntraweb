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

    /**
     * Step 1 → user submits spread choice + question.
     * Stash both in session and forward to the pick view (78-card fan).
     */
    public function begin(Request $request)
    {
        $data = $request->validate([
            'spread'   => 'required|in:three,celtic',
            'question' => 'nullable|string|max:500',
        ]);

        $request->session()->put('tarot_pick', [
            'spread'   => $data['spread'],
            'question' => $data['question'] ?? null,
        ]);

        return redirect()->route('tarot.pick');
    }

    /**
     * Step 2 → render 78 face-down cards in a fan, user picks N (3 or 10).
     */
    public function pick(Request $request)
    {
        $sess = $request->session()->get('tarot_pick');
        if (!$sess) {
            return redirect()->route('tarot.index')
                ->with('status', 'กรุณาเลือกรูปแบบการดูดวงก่อน');
        }

        $needed = $sess['spread'] === 'celtic' ? 10 : 3;
        $cards  = TarotCard::where('active', true)
            ->inRandomOrder()
            ->get(['id', 'slug', 'name_th']);

        return view('pages.tarot.pick', [
            'cards'    => $cards,
            'needed'   => $needed,
            'spread'   => $sess['spread'],
            'question' => $sess['question'],
            'targetRoute' => $sess['spread'] === 'celtic' ? 'tarot.celtic-cross' : 'tarot.three-card',
        ]);
    }

    public function threeCardSpread(Request $request, AiOracle $oracle)
    {
        $data = $request->validate([
            'question' => 'nullable|string|max:500',
            'picked'   => 'nullable|array|size:3',
            'picked.*' => 'integer|exists:tarot_cards,id',
        ]);

        $cards = $this->resolvePickedCards($data['picked'] ?? null, 3);
        $positions = ['อดีต', 'ปัจจุบัน', 'อนาคต'];

        return $this->createReading($request, 'tarot_three', $cards, $positions, $oracle);
    }

    public function celticCross(Request $request, AiOracle $oracle)
    {
        $data = $request->validate([
            'question' => 'nullable|string|max:500',
            'picked'   => 'nullable|array|size:10',
            'picked.*' => 'integer|exists:tarot_cards,id',
        ]);

        $cards = $this->resolvePickedCards($data['picked'] ?? null, 10);
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

    /**
     * Honour user-picked card IDs (in pick order). Fall back to random.
     * The DB returns cards in arbitrary order; we re-sort by the user's pick sequence.
     */
    private function resolvePickedCards(?array $pickedIds, int $needed)
    {
        if (!$pickedIds || count(array_unique($pickedIds)) !== $needed) {
            return TarotCard::where('active', true)->inRandomOrder()->limit($needed)->get();
        }

        $cards = TarotCard::whereIn('id', $pickedIds)->get()->keyBy('id');
        $ordered = collect();
        foreach ($pickedIds as $id) {
            if ($cards->has($id)) {
                $ordered->push($cards->get($id));
            }
        }

        return $ordered->count() === $needed
            ? $ordered
            : TarotCard::where('active', true)->inRandomOrder()->limit($needed)->get();
    }

    private function createReading(Request $request, string $type, $cards, array $positions, AiOracle $oracle)
    {
        // consume the session pick state — we're done with it
        $request->session()->forget('tarot_pick');

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
