<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Jobs\InterpretDeepReading;
use App\Models\Reading;
use App\Services\Tarot\TarotReadingFinisher;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ดูดวงเชิงลึก 39฿ — แพ็กเดียวกับที่บอทแม่หมอใน Facebook/LINE ขายอยู่
 *
 * เดิมแพ็กนี้มีเฉพาะในแชทบอท ลูกค้าที่เข้าเว็บซื้อไม่ได้เลยทั้งที่เป็นแพ็ก
 * ที่ขายดีที่สุด คำทำนายมาจาก Thaiprompt — 🔮 (2026-11-07) ทีละข้อ เลนเดียวกับบิลในแชท
 * (FortuneBotClient::deepQuestion ผ่าน InterpretDeepReading → DeepReadingFinisher)
 *
 * เส้นทางเงิน: หักเครดิตก่อนเรียก AI (กันคนยิงฟรี) และ **คืนเครดิตทุกกรณี**
 * ที่ไม่ได้คำทำนายจริง — ห้ามส่งข้อความปลอมแทนเพราะนี่คือสินค้าที่จ่ายเงินซื้อ
 */
class DeepReadingController extends Controller
{
    use PreventsDuplicateCharges;

    private const MAX_QUESTIONS = \App\Services\Deep\DeepReadingFinisher::MAX_QUESTIONS;

    public function __construct(
        private WalletService $wallet,
        private TarotReadingFinisher $finisher,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return view('pages.deep.index', [
            'cost'    => Pricing::for('deep'),
            'balance' => $user ? $this->wallet->balance($user) : null,
            'maxQ'    => self::MAX_QUESTIONS,
            // 🪐 กรอกวันเกิดในโปรไฟล์ไว้ให้ก่อน — ลูกค้าแก้ได้
            'profileBirth' => \App\Support\ProfileBirth::date($user),
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
        // 🪐 (2026-11-07) เว้นช่องวันเกิด = ใช้วันเกิดในโปรไฟล์ — คำทำนายผูกดวงจริงได้เหมือนบิลของบอทแชทเพจ
        $data['birth_date'] = ($data['birth_date'] ?? null) ?: \App\Support\ProfileBirth::date($user);

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

        // 🔮 (2026-11-07) แม่หมออ่าน "หลังส่งหน้า" ทีละข้อ — เลนเดียวกับบิล 39฿ ในแชท FB/LINE
        //    (ข้อละ ≤ ~55 วิ รวมหลายข้อเกินเพดาน ~60 วิของคำขอหน้าเว็บ) หน้าผลถามสถานะจนคำทำนายขึ้น
        //    ไม่สำเร็จ/โมเดลปฏิเสธ/คำถามถูกปัด = คืนเงินที่ DeepReadingFinisher
        try {
            $row = \App\Services\Deep\DeepReadingFinisher::pending($user, $questions, $data['birth_date'] ?? null, $cost, $tx);
            InterpretDeepReading::dispatchAfterResponse($row->id);
        } catch (\Throwable $e) {
            // สร้างรายการได้แต่ส่งงานไม่ได้ — ปิดรายการเป็นไม่สำเร็จ + คืนเงินผ่านทางเดียวกับงานเบื้องหลัง
            if (isset($row)) {
                Log::error('Deep reading dispatch failed', ['reading' => $row->id, 'err' => $e->getMessage()]);
                $this->finisher->fail($row, 'ส่งงานดูดวงเชิงลึกไม่สำเร็จ', [Reading::STATUS_PENDING]);

                return redirect()->route('deep.show', $row);
            }

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

    /** หน้าผลถามทุก ~3 วิ ระหว่างแม่หมอดูดวงเบื้องหลัง — สิทธิ์เดียวกับหน้าผล */
    public function status(Request $request, Reading $reading)
    {
        abort_unless($reading->type === 'deep', 404);

        $user    = $request->user();
        $isOwner = $user && $reading->user_id === $user->id;
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        abort_unless($isOwner || $isAdmin || $reading->shared_public, 403);

        return response()->json([
            'status' => $reading->isInProgress() ? 'reading' : ($reading->isFailed() ? 'failed' : 'done'),
        ])->header('Cache-Control', 'no-store');
    }
}
