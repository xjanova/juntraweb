<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Exceptions\PalmNotVisibleException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\Reading;
use App\Services\PalmReader;
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

    public function analyze(Request $request, PalmReader $palm)
    {
        $request->validate([
            // jpg/png/webp เท่านั้น — ด่าน vision รับแค่นี้ และกัน SVG (สคริปต์ฝังได้) ไปนอนใน storage สาธารณะ
            'image'    => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
            'question' => 'nullable|string|max:500',
        ]);

        if (!$request->user()) {
            return redirect()->route('login')->with('status', 'กรุณาเข้าสู่ระบบเพื่อใช้บริการดูลายมือ');
        }

        // ดูลายมือไม่มีทางสำรองที่เขียนเอง — ไม่มีทางอ่านเลย (ไม่ได้ต่อ Thaiprompt และไม่มีคีย์ Gemini)
        // ต้องปฏิเสธก่อนหักเงิน · อ่านแล้วพลาด = คืนเงินในบล็อก catch ด้านล่าง
        if (!$palm->isAvailable()) {
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

            $analysis = $palm->read($absolute, $request->input('question'));

            $reading = Reading::create([
                'user_id'       => $request->user()->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => 'palmistry',
                'question'      => $request->input('question'),
                'payload'       => ['image_path' => $path, 'cost' => $cost],
                'result'        => $analysis['reading'],
                'ai_provider'   => $analysis['ai_provider'],
                'ai_model'      => $analysis['ai_model'],
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
                ->with('status', $e instanceof PalmNotVisibleException
                    ? PalmNotVisibleException::MESSAGE
                    : 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนแล้ว กรุณาลองใหม่');
        }

        return view('pages.palmistry.result', [
            'reading'   => $reading,
            'image_url' => asset('storage/' . $path),
        ]);
    }
}
