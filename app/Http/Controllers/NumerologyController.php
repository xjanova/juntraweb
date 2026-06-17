<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\Reading;
use App\Services\Numerology;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NumerologyController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(private WalletService $wallet) {}

    public function index()
    {
        return view('pages.numerology.index', [
            'cost' => Pricing::for('numerology'),
        ]);
    }

    public function calculate(Request $request, Numerology $numerology)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:128',
            // Reject impossible birthdates (future / absurd years) before we
            // charge — they'd produce a meaningless numerology result.
            'birth_date' => 'required|date|before_or_equal:today|after:1900-01-01',
        ]);

        if (!$request->user()) {
            return redirect()->route('login')->with('status', 'กรุณาเข้าสู่ระบบเพื่อใช้บริการดูเลขศาสตร์');
        }

        if ($this->guardCharge($request, 'reading') === false) {
            return redirect()->route('numerology.index')->with('status', 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่');
        }

        $cost = Pricing::for('numerology');
        $tx = null;
        if ($cost > 0) {
            try {
                $tx = $this->wallet->debit($request->user(), $cost, 'ดูเลขศาสตร์: ' . $data['name'], [
                    'reference_type' => 'reading',
                ]);
            } catch (InsufficientFundsException $e) {
                return redirect()->route('wallet.index')->with('status', $e->getMessage() . ' — กรุณาเติมเงิน');
            }
        }

        // Refund the debit on any post-debit failure so the user is never
        // charged for a reading they didn't receive.
        try {
            $result = $numerology->analyze($data['name'], $data['birth_date']);

            $reading = Reading::create([
                'user_id'       => $request->user()->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => 'numerology',
                'question'      => $data['name'],
                'payload'       => array_merge($data, ['cost' => $cost]),
                'result'        => $result['narrative'],
            ]);

            if ($tx) {
                $tx->update(['reference_id' => $reading->id]);
            }
        } catch (\Throwable $e) {
            Log::error('Numerology reading failed after debit — refunding', [
                'user_id' => $request->user()->id, 'tx_id' => $tx?->id, 'err' => $e->getMessage(),
            ]);
            if ($tx) {
                try { $this->wallet->refund($tx, 'ระบบขัดข้องระหว่างคำนวณเลขศาสตร์'); }
                catch (\Throwable $refundErr) {
                    Log::critical('Refund FAILED — manual intervention needed', ['tx_id' => $tx->id, 'err' => $refundErr->getMessage()]);
                }
            }
            return redirect()->route('numerology.index')
                ->with('status', 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนแล้ว กรุณาลองใหม่');
        }

        return view('pages.numerology.result', [
            'reading' => $reading,
            'result'  => $result,
        ]);
    }
}
