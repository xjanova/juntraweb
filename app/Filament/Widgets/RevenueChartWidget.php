<?php

namespace App\Filament\Widgets;

use App\Models\WalletTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Daily approved top-up revenue over the last 30 days. Grouped in PHP (not SQL)
 * so it behaves identically on MySQL (prod) and SQLite (tests).
 */
class RevenueChartWidget extends ChartWidget
{
    protected static ?string $heading = 'รายได้จากการเติมเงิน (30 วันล่าสุด)';
    protected static ?int $sort = 1;
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    protected function getData(): array
    {
        $start = Carbon::today()->subDays(29);

        $rows = WalletTransaction::where('type', 'topup')->where('status', 'success')
            ->where('created_at', '>=', $start)
            ->get(['amount', 'created_at']);

        // Seed every day in range with 0 so the axis has no gaps.
        $byDay = [];
        for ($i = 0; $i < 30; $i++) {
            $byDay[$start->copy()->addDays($i)->format('Y-m-d')] = 0.0;
        }
        foreach ($rows as $r) {
            $key = $r->created_at->format('Y-m-d');
            if (isset($byDay[$key])) {
                $byDay[$key] += (float) $r->amount;
            }
        }

        return [
            'datasets' => [[
                'label' => 'รายได้ (฿)',
                'data' => array_values($byDay),
                'borderColor' => '#f59e0b',
                'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => array_map(fn ($d) => Carbon::parse($d)->format('d/m'), array_keys($byDay)),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
