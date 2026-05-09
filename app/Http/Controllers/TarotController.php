<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Services\FortuneBot\FortuneAiService;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TarotController extends Controller
{
    public function __construct(
        private FortuneAiService $ai,
        private WalletService $wallet,
    ) {}

    public function index()
    {
        return view('pages.tarot.index', [
            'cards'        => TarotCard::where('active', true)->get(),
            'priceThree'   => Pricing::for('tarot_three'),
            'priceCeltic'  => Pricing::for('tarot_celtic'),
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

        $type = $sess['spread'] === 'celtic' ? 'tarot_celtic' : 'tarot_three';

        return view('pages.tarot.pick', [
            'cards'       => $cards,
            'needed'      => $needed,
            'spread'      => $sess['spread'],
            'question'    => $sess['question'],
            'targetRoute' => $sess['spread'] === 'celtic' ? 'tarot.celtic-cross' : 'tarot.three-card',
            'cost'        => Pricing::for($type),
            'balance'     => $request->user() ? $this->wallet->balance($request->user()) : null,
        ]);
    }

    public function threeCardSpread(Request $request)
    {
        $data = $request->validate([
            'question' => 'nullable|string|max:500',
            'picked'   => 'nullable|array|size:3',
            'picked.*' => 'integer|exists:tarot_cards,id',
        ]);

        $cards = $this->resolvePickedCards($data['picked'] ?? null, 3);
        $positions = ['อดีต', 'ปัจจุบัน', 'อนาคต'];

        return $this->createReading($request, 'tarot_three', $cards, $positions);
    }

    public function celticCross(Request $request)
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

        return $this->createReading($request, 'tarot_celtic', $cards, $positions);
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

    private function createReading(Request $request, string $type, $cards, array $positions)
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('login')
                ->with('status', 'กรุณาเข้าสู่ระบบเพื่อเปิดไพ่ — เครดิตจะถูกหักจากวอลเลตของคุณ');
        }

        $cost = Pricing::for($type);

        // Reserve credit BEFORE we touch the AI, so a failed reading doesn't
        // leak server resources and a successful one always has a paired tx.
        // If insufficient, bounce to /wallet so the user can top up — their
        // session pick state is preserved so they can retry after.
        try {
            $tx = $this->wallet->debit($user, $cost, 'เปิดไพ่: ' . ($positions[0] ?? '') . ' (' . $type . ')', [
                'reference_type' => 'reading',
                'method'         => 'system',
            ]);
        } catch (InsufficientFundsException $e) {
            return redirect()->route('wallet.index')->with('status', $e->getMessage() . ' — กรุณาเติมเงินเข้าวอลเลต');
        }

        // Consume the session pick state — we're committed now.
        $request->session()->forget('tarot_pick');

        // From here on, ANY failure (DB error, AI throw, etc.) must roll back
        // the debit by issuing a refund row — otherwise the user is charged
        // for a reading they never received. Every catch path must refund.
        try {
            $reading = Reading::create([
                'user_id'       => $user->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => $type,
                'question'      => $request->input('question'),
                'payload'       => [
                    'positions'    => $positions,
                    'cost'         => $cost,
                    'wallet_tx_id' => $tx->id,
                ],
            ]);

            foreach ($cards as $i => $card) {
                $reversed = (bool) random_int(0, 1);
                $reading->tarotCards()->create([
                    'tarot_card_id'  => $card->id,
                    'position'       => $i + 1,
                    'position_label' => $positions[$i] ?? "ตำแหน่ง " . ($i + 1),
                    'reversed'       => $reversed,
                ]);
            }

            $reading->load('tarotCards.card');
            $aiResult = $this->ai->interpretTarot($reading, $user);
            $reading->result      = $aiResult['text'];
            $reading->ai_provider = $aiResult['provider'];
            $reading->ai_model    = $aiResult['model'];
            $reading->save();

            // Link the wallet transaction back to the reading (audit trail).
            $tx->update(['reference_id' => $reading->id]);
        } catch (\Throwable $e) {
            Log::error('Tarot reading creation failed after debit — refunding', [
                'user_id' => $user->id,
                'tx_id'   => $tx->id,
                'type'    => $type,
                'err'     => $e->getMessage(),
            ]);
            try {
                $this->wallet->refund($tx, 'ระบบขัดข้องระหว่างเปิดไพ่');
            } catch (\Throwable $refundErr) {
                Log::critical('Refund FAILED after reading failure — manual intervention needed', [
                    'tx_id' => $tx->id,
                    'err'   => $refundErr->getMessage(),
                ]);
            }
            return redirect()->route('tarot.index')
                ->with('status', 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนเข้าวอลเลตแล้ว กรุณาลองใหม่อีกครั้ง');
        }

        return redirect()->route('tarot.show', $reading);
    }

    public function show(Reading $reading)
    {
        if (!in_array($reading->type, ['tarot_three', 'tarot_celtic'])) {
            abort(404);
        }

        // Privacy: only the owner (or admin, or a public-shared reading) can view.
        $user = request()->user();
        $isOwner = $user && $reading->user_id === $user->id;
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        if (!$reading->shared_public && !$isOwner && !$isAdmin) {
            abort(403);
        }

        $reading->load('tarotCards.card');
        return view('pages.tarot.result', compact('reading'));
    }
}
