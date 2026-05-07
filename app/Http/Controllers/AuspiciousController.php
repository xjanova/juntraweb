<?php

namespace App\Http\Controllers;

use App\Models\AuspiciousDate;
use App\Services\AiOracle;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuspiciousController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $upcoming = AuspiciousDate::where('active', true)
            ->whereBetween('date', [$today, $today->copy()->addDays(60)])
            ->orderBy('date')
            ->get();

        return view('pages.auspicious.index', compact('upcoming'));
    }

    public function find(Request $request, AiOracle $oracle)
    {
        $data = $request->validate([
            'occasion' => 'required|string|max:128',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $from = isset($data['from_date']) ? Carbon::parse($data['from_date']) : Carbon::today();
        $to   = isset($data['to_date'])   ? Carbon::parse($data['to_date'])   : $from->copy()->addDays(60);

        $candidates = $this->candidateDays($from, $to);
        $advice = $oracle->adviseAuspiciousDates($data['occasion'], $candidates);

        return view('pages.auspicious.result', [
            'occasion' => $data['occasion'],
            'candidates' => $candidates,
            'advice' => $advice,
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
