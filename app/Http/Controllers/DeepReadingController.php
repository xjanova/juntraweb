<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\Reading;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ดูดวงเชิงลึก 39฿ — แพ็กเดียวกับที่บอทแม่หมอใน Facebook/LINE ขายอยู่
 *
 * เดิมแพ็กนี้มีเฉพาะในแชทบอท ลูกค้าที่เข้าเว็บซื้อไม่ได้เลยทั้งที่เป็นแพ็ก
 * ที่ขายดีที่สุด คำทำนายมาจาก Thaiprompt (prompt + คีย์พูลชุดเดียวกับบอท)
 * ผ่าน FortuneBotClient::deepReading()
 *
 * เส้นทางเงิน: หักเครดิตก่อนเรียก AI (กันคนยิงฟรี) และ **คืนเครดิตทุกกรณี**
 * ที่ไม่ได้คำทำนายจริง — ห้ามส่งข้อความปลอมแทนเพราะนี่คือสินค้าที่จ่ายเงินซื้อ
 */
class DeepReadingController extends Controller
{
    use PreventsDuplicateCharges;

    private const MAX_QUESTIONS = 3;

    public function __construct(
        private FortuneBotClient $bot,
        private WalletService $wallet,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return view('pages.deep.index', [
            'cost'    => Pricing::for('deep'),
            'balance' => $user ? $this->wallet->balance($user) : null,
            'maxQ'    => self::MAX_QUESTIONS,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login')
                ->with('status', 'กรุณาเข้าสู่ระบบก่อนดูดวงเชิงลึก — เครดิตจะถูกหักจากวอลเลตของคุณ');
        }

        $data = $request->validate([
            'birth_date'  => 'nullable|date_format:Y-m-d|before:today',
            'questions'   => 'required|array|min:1|max:'.self::MAX_QUESTIONS,
            'questions.*' => 'nullable|string|max:500',
        ], [], [
            'birth_date' => 'วันเกิด',
            'questions'  => 'คำถาม',
        ]);

        $questions = array_values(array_filter(array_map(
            fn ($q) => trim((string) $q),
            $data['questions'],
        ), fn ($q) => $q !== ''));

        if (empty($questions)) {
            return back()->withInput()->withErrors(['questions' => 'กรุณาพิมพ์คำถามอย่างน้อย 1 ข้อ']);
        }

        // กดซ้ำ/รีเฟรชฟอร์มเดิม ต้องไม่ถูกคิดเงินสองรอบ
        if ($this->guardCharge($request, 'reading') === false) {
            return redirect()->route('deep.index')
                ->with('status', 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่');
        }

        $cost = Pricing::for('deep');
        $tx   = null;

        if ($cost > 0) {
            try {
                $tx = $this->wallet->debit($user, $cost, 'ดูดวงเชิงลึก', [
                    'reference_type' => 'reading',
                    'method'         => 'system',
                ]);
            } catch (InsufficientFundsException $e) {
                return redirect()->route('wallet.topup')
                    ->with('status', $e->getMessage().' — กรุณาเติมเครดิตก่อนนะคะ');
            }
        }

        $reading = $this->bot->deepReading($user, $questions, $data['birth_date'] ?? null, $user->name);

        // ไม่ได้คำทำนายจริง = ไม่มีสินค้าให้ลูกค้า → คืนเงินเสมอ
        if (! $reading || empty($reading['reading'])) {
            if ($tx) {
                try {
                    $this->wallet->refund($tx, 'ระบบคำทำนายเชิงลึกไม่พร้อม');
                } catch (\Throwable $e) {
                    Log::critical('Deep reading refund FAILED — ต้องคืนเงินด้วยมือ', [
                        'tx_id' => $tx->id, 'err' => $e->getMessage(),
                    ]);
                }
            }

            return redirect()->route('deep.index')->with(
                'status',
                'ขออภัยค่ะ ระบบคำทำนายเชิงลึกไม่พร้อมชั่วคราว '
                .($tx ? 'เครดิตถูกคืนเข้ากระเป๋าเรียบร้อยแล้ว ' : '')
                .'ลองใหม่อีกครั้งในอีกสักครู่นะคะ',
            );
        }

        try {
            $row = Reading::create([
                'user_id'       => $user->id,
                'session_token' => (string) Str::uuid(),
                'type'          => 'deep',
                'question'      => $questions[0],
                'payload'       => [
                    'questions'    => $questions,
                    'birth_date'   => $data['birth_date'] ?? null,
                    'cost'         => $cost,
                    'wallet_tx_id' => $tx?->id,
                ],
                'result'      => $reading['reading'],
                'ai_provider' => $reading['ai_provider'] ?? null,
                'ai_model'    => $reading['ai_model'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // จ่ายแล้วแต่บันทึกไม่ได้ = ลูกค้าไม่มีอะไรให้เปิดอ่านย้อน → คืนเงิน
            if ($tx) {
                try {
                    $this->wallet->refund($tx, 'บันทึกคำทำนายไม่สำเร็จ');
                } catch (\Throwable $refundErr) {
                    Log::critical('Deep reading refund FAILED after persist failure', [
                        'tx_id' => $tx->id, 'err' => $refundErr->getMessage(),
                    ]);
                }
            }
            Log::error('Deep reading persist failed', ['user_id' => $user->id, 'err' => $e->getMessage()]);

            return redirect()->route('deep.index')
                ->with('status', 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนแล้ว กรุณาลองใหม่อีกครั้ง');
        }

        return redirect()->route('deep.show', $row);
    }

    public function show(Request $request, Reading $reading)
    {
        abort_unless($reading->type === 'deep', 404);

        $user    = $request->user();
        $isOwner = $user && $reading->user_id === $user->id;
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        abort_unless($isOwner || $isAdmin || $reading->shared_public, 403);

        return view('pages.deep.result', ['reading' => $reading]);
    }
}
