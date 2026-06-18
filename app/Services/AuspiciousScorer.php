<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Deterministic auspicious-day scorer (Thai folk heuristics). Shared by the
 * web AuspiciousController and the mobile Api\V1\FortuneController so both
 * tiers compute identical candidate dates.
 */
class AuspiciousScorer
{
    /**
     * Score every day in [from, to] and return the up-to-10 highest-scoring
     * auspicious days (score >= 7), best first.
     *
     * @return array<int, array{date: Carbon, score: int, label: string}>
     */
    public function candidateDays(Carbon $from, Carbon $to): array
    {
        $days = [];
        $cursor = $from->copy();
        while ($cursor <= $to && count($days) < 30) {
            $score = $this->dayScore($cursor);
            if ($score >= 7) {
                $days[] = [
                    'date'  => $cursor->copy(),
                    'score' => $score,
                    'label' => $this->dayLabel($cursor),
                ];
            }
            $cursor->addDay();
        }
        usort($days, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($days, 0, 10);
    }

    public function dayScore(Carbon $date): int
    {
        $score = 5;
        if (in_array($date->dayOfWeek, [Carbon::TUESDAY, Carbon::THURSDAY, Carbon::SATURDAY])) $score += 2;
        if ($date->day === 9 || $date->day === 19 || $date->day === 29) $score += 2;
        $sumDigits = array_sum(str_split($date->format('Ymd')));
        if ($sumDigits % 9 === 0) $score += 2;
        return min(10, $score);
    }

    public function dayLabel(Carbon $d): string
    {
        $days = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
        return 'วัน' . $days[$d->dayOfWeek] . ' ที่ ' . $d->format('d/m/Y');
    }
}
