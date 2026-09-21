<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Jobs\InterpretTarotReading;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Services\FortuneBot\FortuneAiService;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use App\Support\ReadingCooldown;
use App\Support\TarotSpreads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TarotController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(
        private FortuneAiService $ai,
        private WalletService $wallet,
    ) {}

    public function index(Request $request)
    {
        // Decorate each registered spread with its live resolved price so the
        // landing page lists every "รูปแบบการวางไพ่" without hardcoding.
        $user = $request->user();
        $spreads = collect(TarotSpreads::all())->map(function ($meta, $key) use ($user) {
            $meta['key']   = $key;
            $meta['count'] = count($meta['positions']);
            $meta['price'] = Pricing::for(TarotSpreads::priceKey($key));
            // ข้อห้ามเปิดซ้ำ — บอกบนการ์ดเลยว่าเปิดได้อีกเมื่อไหร่ ไม่ต้องให้ลูกค้าเลือกไพ่ก่อนแล้วค่อยรู้
            $blocking = ReadingCooldown::blockingReading($user, $key);
            $meta['locked_until']   = $blocking ? ReadingCooldown::thaiDate(ReadingCooldown::availableAt($blocking, $key)) : null;
            $meta['locked_reading'] = $blocking?->id;
            return $meta;
        })->values()->all();

        return view('pages.tarot.index', [
            'spreads' => $spreads,
        ]);
    }

    /**
     * Step 1 → user submits spread choice + question.
     * Stash both in session and forward to the pick view (78-card fan).
     */
    public function begin(Request $request)
    {
        $data = $request->validate([
            'spread'   => ['required', Rule::in(TarotSpreads::keys())],
            'question' => 'nullable|string|max:500',
        ]);

        if ($blocked = $this->cooldownBlock($request, $data['spread'])) {
            return $blocked;
        }

        $request->session()->put('tarot_pick', [
            'spread'   => $data['spread'],
            'question' => $data['question'] ?? null,
        ]);

        return redirect()->route('tarot.pick');
    }

    /**
     * Step 2 → render 78 face-down cards in a fan, user picks N cards
     * (N = the chosen spread's card count).
     */
    public function pick(Request $request)
    {
        $sess = $request->session()->get('tarot_pick');
        if (!$sess || !TarotSpreads::has($sess['spread'] ?? '')) {
            return redirect()->route('tarot.index')
                ->with('status', 'กรุณาเลือกรูปแบบการดูดวงก่อน');
        }

        $key   = $sess['spread'];
        if ($blocked = $this->cooldownBlock($request, $key)) {
            return $blocked;
        }
        $cards = TarotCard::where('active', true)
            ->inRandomOrder()
            ->get(['id', 'slug', 'name_th']);

        return view('pages.tarot.pick', [
            'cards'       => $cards,
            'needed'      => TarotSpreads::cardCount($key),
            'spread'      => $key,
            'spreadName'  => TarotSpreads::get($key)['name_th'] ?? 'ไพ่ยิปซี',
            'question'    => $sess['question'],
            'targetRoute' => 'tarot.cast',
            'cost'        => Pricing::for(TarotSpreads::priceKey($key)),
            'balance'     => $request->user() ? $this->wallet->balance($request->user()) : null,
            // 🌠 (2026-09-15) Celtic / 12 เดือน / คุณไสย — วันเกิด (ไม่บังคับ) ให้แม่หมอผสานดวงดาวแบบ 99
            //    เติมจากโปรไฟล์ดวงของลูกค้าให้เลย ถ้าเคยบันทึกไว้ (ไม่ต้องพิมพ์ซ้ำ)
            'askBirth'    => TarotSpreads::wantsBirthDate($key),
            'birthDate'   => optional($request->user()?->profile?->birth_date)->format('Y-m-d'),
        ]);
    }

    /**
     * Step 3 → user has picked N cards; create the reading for ANY spread.
     *
     * The spread is taken from the POST and validated against the registry;
     * `picked` must contain exactly that spread's card count or we fall back
     * to a random draw of the right size (resolvePickedCards). Because the
     * price is derived from the validated spread AND the card count is forced
     * to match it, a tampered `spread`/`picked` can never buy a 10-card
     * reading at the 1-card price.
     */
    public function cast(Request $request)
    {
        $data = $request->validate([
            'spread'   => ['required', Rule::in(TarotSpreads::keys())],
            'question' => 'nullable|string|max:500',
            'picked'   => 'nullable|array',
            'picked.*' => 'integer|exists:tarot_cards,id',
            // วันเกิด (ไม่บังคับ) — ใช้เฉพาะแพ็กเกจที่ผสานดวงดาว (TarotSpreads::wantsBirthDate)
            'birth_date' => 'nullable|date_format:Y-m-d|before:today|after:1900-01-01',
        ]);

        $key       = $data['spread'];
        $needed    = TarotSpreads::cardCount($key);
        $cards     = $this->resolvePickedCards($data['picked'] ?? null, $needed);
        $positions = TarotSpreads::positionLabels($key);

        return $this->createReading($request, TarotSpreads::typeFromKey($key), $cards, $positions);
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

        // Safety: only proceed if the deck actually yielded enough cards for
        // this spread (a mis-seeded or over-deactivated deck could return
        // fewer). Never charge full price for an incomplete spread.
        if (count($cards) !== count($positions)) {
            return redirect()->route('tarot.index')
                ->with('status', 'ขออภัย ชุดไพ่ยังไม่พร้อมสำหรับการเปิดรูปแบบนี้ — ยังไม่มีการหักเครดิต');
        }

        // Idempotency — block a double-submit of this exact reading.
        if ($this->guardCharge($request, 'reading') === false) {
            return redirect()->route('tarot.index')
                ->with('status', 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่');
        }

        // ข้อห้ามเปิดซ้ำ — ตรวจใต้ล็อกต่อ "ลูกค้า × แพ็กเกจ" จนสร้างรายการเสร็จ: สองแท็บที่กดพร้อมกัน
        // (คนละ _idem จึงผ่านด่านกันกดซ้ำทั้งคู่) ต้องไม่ผ่านด่านนี้ไปทั้งคู่
        $key = TarotSpreads::keyFromType($type);
        $openLock = null;
        if ($key !== null && ReadingCooldown::days($key) > 0) {
            $openLock = Cache::lock("tarot-open:{$user->id}:{$key}", 30);
            if (! $openLock->get()) {
                return redirect()->route('tarot.index')
                    ->with('status', 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่');
            }
        }

        try {
            if ($key !== null && ($blocked = $this->cooldownBlock($request, $key))) {
                return $blocked;
            }

            return $this->chargeAndCreate($request, $user, $type, $cards, $positions);
        } finally {
            $openLock?->release();
        }
    }

    /** ข้อห้ามเปิดซ้ำ — redirect พร้อมเหตุผล หรือ null ถ้าเปิดได้ */
    private function cooldownBlock(Request $request, string $key)
    {
        $blocking = ReadingCooldown::blockingReading($request->user(), $key);

        return $blocking
            ? redirect()->route('tarot.index')->with('status', ReadingCooldown::message($blocking, $key))
            : null;
    }

    private function chargeAndCreate(Request $request, $user, string $type, $cards, array $positions)
    {
        $cost = Pricing::for($type);

        // ระบบทำนายจริงต่อไม่ได้เลย (ไม่ได้ตั้ง client ของเว็บ และลูกค้าไม่มี token) → หยุดก่อนหักเงิน
        // (แอพทำแบบนี้อยู่แล้วที่ Api\V1\HistoryController — เว็บเคยหักเงินแล้วส่งข้อความประกอบ
        //  จากความหมายไพ่ให้แทนคำทำนาย)
        if ($cost > 0 && ! $this->ai->isAvailableFor($user)) {
            return redirect()->route('tarot.index')
                ->with('status', 'ตอนนี้แม่หมอยังเชื่อมต่อระบบทำนายไม่ได้ชั่วคราว — ยังไม่มีการหักเครดิต ลองใหม่อีกครั้งนะคะ');
        }

        // Reserve credit BEFORE we touch the AI, so a failed reading doesn't
        // leak server resources and a successful one always has a paired tx.
        // If insufficient, bounce to /wallet so the user can top up — their
        // session pick state is preserved so they can retry after.
        // Guarded by cost > 0 so admin can set the price to 0 (free reading)
        // without debit() throwing InvalidArgumentException.
        $tx = null;
        if ($cost > 0) {
            try {
                $spreadLabel = TarotSpreads::nameForType($type) ?? 'ไพ่ยิปซี';
                $tx = $this->wallet->debit($user, $cost, 'เปิดไพ่: ' . $spreadLabel, [
                    'reference_type' => 'reading',
                    'method'         => 'system',
                ]);
            } catch (InsufficientFundsException $e) {
                return redirect()->route('wallet.index')->with('status', $e->getMessage() . ' — กรุณาเติมเงินเข้าวอลเลต');
            }
        }

        // Consume the session pick state — we're committed now.
        $request->session()->forget('tarot_pick');

        // From here on, ANY failure (DB error, AI throw, etc.) must roll back
        // the debit by issuing a refund row — otherwise the user is charged
        // for a reading they never received. Every catch path must refund.
        $reading = null;
        try {
            $reading = Reading::create([
                'user_id'       => $user->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => $type,
                'question'      => $request->input('question'),
                'payload'       => array_filter([
                    'positions'    => $positions,
                    'cost'         => $cost,
                    'wallet_tx_id' => $tx?->id,
                    // เก็บไว้กับรายการ — แม่หมอใช้ตอนทำนาย และหลังบ้านเห็นว่าคำทำนายนี้มีดวงวันเกิดประกอบ
                    'birth_date'   => TarotSpreads::wantsBirthDate(TarotSpreads::keyFromType($type) ?? '')
                        ? $request->input('birth_date') : null,
                ], fn ($v) => $v !== null),
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

            // Link the wallet transaction back to the reading (audit trail).
            if ($tx) {
                $tx->update(['reference_id' => $reading->id]);
            }

            // 🔮 (2026-09-15) แม่หมออ่านไพ่ "หลังส่งหน้า" — แพ็กเกจยาวบนเลนทำนายใช้ 36-55 วิ ชนเพดาน
            //    ~60 วิของคำขอหน้าเว็บ ลูกค้าเห็นไพ่ที่เปิดได้ทันที แล้วหน้าผลถามสถานะจนคำทำนายขึ้น
            //    ไม่สำเร็จ (AI เงียบ / ได้แค่ข้อความประกอบจากความหมายไพ่) = คืนเงินที่ TarotReadingFinisher
            $reading->update(['status' => Reading::STATUS_PENDING]);
            InterpretTarotReading::dispatchAfterResponse($reading->id);
        } catch (\Throwable $e) {
            Log::error('Tarot reading creation failed after debit — refunding', [
                'user_id' => $user->id,
                'tx_id'   => $tx?->id,
                'type'    => $type,
                'err'     => $e->getMessage(),
            ]);
            // Track whether the refund actually succeeded so we don't tell the
            // user "เครดิตถูกคืนแล้ว" when it didn't.
            $refunded = true;
            if ($tx) {
                try {
                    $this->wallet->refund($tx, 'ระบบขัดข้องระหว่างเปิดไพ่');
                } catch (\Throwable $refundErr) {
                    $refunded = false;
                    Log::critical('Refund FAILED after reading failure — manual intervention needed', [
                        'tx_id' => $tx->id,
                        'err'   => $refundErr->getMessage(),
                    ]);
                }
            }
            // แถวที่ยังไม่มีคำทำนายต้องไม่ค้างในประวัติ — ไม่งั้นลูกค้าเปิดเจอรายการเปล่า
            // ที่ดูเหมือน "จ่ายแล้วไม่ได้อะไร" ทั้งที่เงินถูกคืนไปแล้ว
            if ($refunded && $reading && blank($reading->result)) {
                try {
                    $reading->tarotCards()->delete();
                    $reading->delete();
                } catch (\Throwable) {
                    // ลบไม่ได้ก็ไม่เป็นไร — เงินคืนแล้ว แค่มีแถวว่างค้าง
                }
            }
            return redirect()->route('tarot.index')->with('status', $refunded
                ? 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนเข้าวอลเลตแล้ว กรุณาลองใหม่อีกครั้ง'
                : 'ระบบขัดข้องชั่วคราว — ทีมงานกำลังตรวจสอบและคืนเครดิตให้คุณ หากยอดไม่กลับคืนกรุณาติดต่อแอดมิน');
        }

        return redirect()->route('tarot.show', $reading);
    }

    public function show(Reading $reading)
    {
        if (!TarotSpreads::isTarotType($reading->type)) {
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

    /**
     * หน้าผลถามว่าแม่หมออ่านเสร็จหรือยัง (รายการที่อ่านเบื้องหลัง) — สิทธิ์เดียวกับหน้าผล
     * ไม่ส่งเนื้อคำทำนายมาทางนี้: เสร็จแล้วหน้าผลโหลดใหม่ทั้งหน้า (ได้การ์ด/ตารางจาก server)
     */
    public function status(Reading $reading)
    {
        if (! TarotSpreads::isTarotType($reading->type)) {
            abort(404);
        }
        $user = request()->user();
        $isOwner = $user && $reading->user_id === $user->id;
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        if (! $reading->shared_public && ! $isOwner && ! $isAdmin) {
            abort(403);
        }

        return response()->json([
            'status' => $reading->isInProgress() ? 'reading' : ($reading->isFailed() ? 'failed' : 'done'),
        ])->header('Cache-Control', 'no-store');
    }
}
