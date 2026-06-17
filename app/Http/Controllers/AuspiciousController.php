<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\AuspiciousDate;
use App\Models\Reading;
use App\Services\AiOracle;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuspiciousController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(private WalletService $wallet) {}

    public function index()
    {
        $today = Carbon::today();
        $upcoming = AuspiciousDate::where('active', true)
            ->whereBetween('date', [$today, $today->copy()->addDays(60)])
            ->orderBy('date')
            ->get();

        return view('pages.auspicious.index', [
            'upcoming' => $upcoming,
            'cost'     => Pricing::for('auspicious'),
        ]);
    }

    public function find(Request $request, AiOracle $oracle)
    {
        $data = $request->validate([
            'occasion'  => 'required|string|max:128',
            'from_date' => 'nullable|date',
            'to_date'   => 'nullable|date|after_or_equal:from_date',
        ]);

        if (!$request->user()) {
            return redirect()->route('login')->with('status', 'กรุณาเข้าสู่ระบบเพื่อใช้บริการหาฤกษ์ยาม');
        }

        if ($this->guardCharge($request, 'reading') === false) {
            return redirect()->route('auspicious.index')->with('status', 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่');
        }

        $cost = Pricing::for('auspicious');
        $tx = null;
        if ($cost > 0) {
            try {
                $tx = $this->wallet->debit($request->user(), $cost, 'หาฤกษ์ยาม: ' . $data['occasion'], [
                    'reference_type' => 'reading',
                ]);
            } catch (InsufficientFundsException $e) {
                return redirect()->route('wallet.index')->with('status', $e->getMessage() . ' — กรุณาเติมเงิน');
            }
        }

        try {
            $from = isset($data['from_date']) ? Carbon::parse($data['from_date']) : Carbon::today();
            $to   = isset($data['to_date'])   ? Carbon::parse($data['to_date'])   : $from->copy()->addDays(60);

            $candidates = $this->candidateDays($from, $to);
            $advice = $oracle->adviseAuspiciousDates($data['occasion'], $candidates);

            $reading = Reading::create([
                'user_id'       => $request->user()->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => 'auspicious',
                'question'      => $data['occasion'],
                'payload'       => [
                    'occasion'   => $data['occasion'],
                    'from_date'  => $from->toDateString(),
                    'to_date'    => $to->toDateString(),
                    'candidates' => array_map(fn ($c) => [
                        'date'  => $c['date']->toDateString(),
                        'score' => $c['score'],
                        'label' => $c['label'],
                    ], $candidates),
                    'cost'       => $cost,
                ],
                'result'        => $advice,
                'ai_provider'   => $oracle->provider(),
                'ai_model'      => $oracle->model(),
            ]);

            if ($tx) {
                $tx->update(['reference_id' => $reading->id]);
            }
        } catch (\Throwable $e) {
            Log::error('Auspicious reading failed after debit — refunding', [
                'user_id' => $request->user()->id, 'tx_id' => $tx?->id, 'err' => $e->getMessage(),
            ]);
            if ($tx) {
                try { $this->wallet->refund($tx, 'ระบบขัดข้องระหว่างหาฤกษ์ยาม'); }
                catch (\Throwable $refundErr) {
                    Log::critical('Refund FAILED — manual intervention needed', ['tx_id' => $tx->id, 'err' => $refundErr->getMessage()]);
                }
            }
            return redirect()->route('auspicious.index')
                ->with('status', 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนแล้ว กรุณาลองใหม่');
        }

        return view('pages.auspicious.result', [
            'occasion'   => $data['occasion'],
            'candidates' => $candidates,
            'advice'     => $advice,
            'reading'    => $reading,
        ]);
    }

    private function candidateDays(Carbon $from, Carbon $to): array
    {
        $days = [];
        $cursor = $from->copy();
        while ($cursor <= $to && count($days) < 30) {
            $score = $this->dayScore($cursor);
            if ($score >= 7) {
                $days[] = [
                    'date' => $cursor->copy(),
                    'score' => $score,
                    'label' => $this->dayLabel($cursor),
                ];
            }
            $cursor->addDay();
        }
        usort($days, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($days, 0, 10);
    }

    private function dayScore(Carbon $date): int
    {
        $score = 5;
        if (in_array($date->dayOfWeek, [Carbon::TUESDAY, Carbon::THURSDAY, Carbon::SATURDAY])) $score += 2;
        if ($date->day === 9 || $date->day === 19 || $date->day === 29) $score += 2;
        $sumDigits = array_sum(str_split($date->format('Ymd')));
        if ($sumDigits % 9 === 0) $score += 2;
        return min(10, $score);
    }

    private function dayLabel(Carbon $d): string
    {
        $days = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
        return 'วัน' . $days[$d->dayOfWeek] . ' ที่ ' . $d->format('d/m/Y');
    }
}
