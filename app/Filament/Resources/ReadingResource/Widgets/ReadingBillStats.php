<?php

namespace App\Filament\Resources\ReadingResource\Widgets;

use App\Models\Reading;
use App\Models\WalletTransaction;
use App\Support\ReadingBill;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * สรุปบนหน้า "บิลดูดวง" — รายได้จากการดูดวงจริง (ยอดตัดเงินที่ไม่ถูกคืน) ไม่ใช่ยอดเติมเงิน
 * (ยอดเติมเงินอยู่ที่แดชบอร์ด BillingStatsWidget — คนละตัวเลข ห้ามปนกัน)
 */
class ReadingBillStats extends BaseWidget
{
    protected static ?string $pollingInterval = '60s';

    /** เบา (sum/count ไม่กี่ตัว) — แสดงพร้อมหน้า ไม่ต้องรอโหลดทีหลัง */
    protected static bool $isLazy = false;

    /** ยอดตัดเงินค่าดูดวงที่ยังไม่ถูกคืน ตั้งแต่ $from */
    private function revenueSince(Carbon $from, ?Carbon $until = null): float
    {
        $q = WalletTransaction::where('type', 'debit')->where('reference_type', 'reading')
            ->where('status', 'success')->where('created_at', '>=', $from);
        if ($until) {
            $q->where('created_at', '<', $until);
        }

        return abs((float) $q->sum('amount'));
    }

    protected function getStats(): array
    {
        $tz = 'Asia/Bangkok';
        $today = Carbon::now($tz)->startOfDay()->utc();

        $daily = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::now($tz)->subDays($i)->startOfDay()->utc();
            $daily[] = round($this->revenueSince($d, $d->copy()->addDay()), 2);
        }

        $billsToday = Reading::where('created_at', '>=', $today)->count();
        $inProgress = Reading::whereIn('status', [Reading::STATUS_PENDING, Reading::STATUS_WORKING])->count();
        $weekAgo = Carbon::now()->subDays(7);
        $paidWeek = Reading::where('created_at', '>=', $weekAgo)->where('payload->cost', '>', 0)->count();
        $failedWeek = Reading::where('created_at', '>=', $weekAgo)->where('status', Reading::STATUS_FAILED)->count();

        // แพ็กเกจขายดี 30 วัน (เฉพาะบิลที่สำเร็จและไม่ถูกคืน)
        $top = Reading::where('created_at', '>=', Carbon::now()->subDays(30))->whereNull('status')
            ->whereNull('payload->refunded_at')->where('payload->cost', '>', 0)
            ->get(['id', 'type', 'payload'])
            ->groupBy(fn (Reading $r) => ReadingBill::package($r))
            ->map->count()->sortDesc();

        return [
            Stat::make('รายได้ดูดวงวันนี้', '฿'.number_format(end($daily), 2))
                ->description('บิลวันนี้ '.number_format($billsToday).' รายการ')
                ->color('success')->chart($daily),
            Stat::make('รายได้ 7 วัน', '฿'.number_format(array_sum($daily), 2))
                ->description('30 วัน ฿'.number_format($this->revenueSince(Carbon::now()->subDays(30)), 2))
                ->color('primary'),
            Stat::make('แพ็กเกจขายดี (30 วัน)', $top->keys()->first() ?? '—')
                ->description($top->isNotEmpty() ? $top->first().' บิล · รองลงมา '.($top->keys()->get(1) ?? '—') : 'ยังไม่มีบิลที่จ่ายเงิน')
                ->color('info'),
            Stat::make('กำลังอ่าน / ไม่สำเร็จ 7 วัน', number_format($inProgress).' / '.number_format($failedWeek))
                ->description($paidWeek > 0 ? 'ไม่สำเร็จ '.round($failedWeek * 100 / $paidWeek, 1).'% ของบิลที่จ่ายเงิน (คืนเงินอัตโนมัติแล้ว)' : 'ยังไม่มีบิลที่จ่ายเงินใน 7 วัน')
                ->color($failedWeek > 0 ? 'warning' : 'gray'),
        ];
    }
}
