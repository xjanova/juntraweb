<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\Reading;
use App\Services\AiOracle;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PalmistryController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(private WalletService $wallet) {}

    public function index()
    {
        return view('pages.palmistry.index', [
            'cost' => Pricing::for('palmistry'),
        ]);
    }

    public function analyze(Request $request, AiOracle $oracle)
    {
        $request->validate([
            'image'    => 'required|image|max:4096',
            'question' => 'nullable|string|max:500',
        ]);

        if (!$request->user()) {
            return redirect()->route('login')->with('status', 'กรุณาเข้าสู่ระบบเพื่อใช้บริการดูลายมือ');
        }

        // Palmistry needs a vision model and has NO heuristic fallback. If the
        // AI isn't configured, refuse BEFORE charging — never debit for a
        // reading we cannot produce. (A configured-but-failed call is handled
        // by the refund path below, since analyzePalmImage() now throws.)
        if (!$oracle->isConfigured()) {
            return redirect()->route('palmistry.index')
                ->with('status', 'ขออภัย บริการดูลายมือ AI ยังไม่พร้อมให้บริการในขณะนี้ — ยังไม่มีการหักเครดิต กรุณาลองบริการอื่นก่อนนะคะ');
        }

        if ($this->guardCharge($request, 'reading') === false) {
            return redirect()->route('palmistry.index')->with('status', 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่');
        }

        $cost = Pricing::for('palmistry');
        $tx = null;
        if ($cost > 0) {
            try {
                $tx = $this->wallet->debit($request->user(), $cost, 'ดูลายมือ AI', [
                    'reference_type' => 'reading',
                ]);
            } catch (InsufficientFundsException $e) {
                return redirect()->route('wallet.index')->with('status', $e->getMessage() . ' — กรุณาเติมเงิน');
            }
        }

        try {
            $path     = $request->file('image')->store('palmistry', 'public');
            $absolute = storage_path('app/public/' . $path);

            $analysis = $oracle->analyzePalmImage($absolute, $request->input('question'));

            $reading = Reading::create([
                'user_id'       => $request->user()->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => 'palmistry',
                'question'      => $request->input('question'),
                'payload'       => ['image_path' => $path, 'cost' => $cost],
                'result'        => $analysis,
                'ai_provider'   => $oracle->provider(),
                'ai_model'      => $oracle->model(),
            ]);

            if ($tx) {
                $tx->update(['reference_id' => $reading->id]);
            }
        } catch (\Throwable $e) {
            Log::error('Palmistry reading failed after debit — refunding', [
                'user_id' => $request->user()->id, 'tx_id' => $tx?->id, 'err' => $e->getMessage(),
            ]);
            if ($tx) {
                try { $this->wallet->refund($tx, 'ระบบขัดข้องระหว่างวิเคราะห์ลายมือ'); }
                catch (\Throwable $refundErr) {
                    Log::critical('Refund FAILED — manual intervention needed', ['tx_id' => $tx->id, 'err' => $refundErr->getMessage()]);
                }
            }
            return redirect()->route('palmistry.index')
                ->with('status', 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนแล้ว กรุณาลองใหม่');
        }

        return view('pages.palmistry.result', [
            'reading'   => $reading,
            'image_url' => asset('storage/' . $path),
        ]);
    }
}
