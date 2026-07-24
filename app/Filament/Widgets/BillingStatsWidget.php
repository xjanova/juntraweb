<?php

namespace App\Filament\Widgets;

use App\Models\Reading;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Dashboard KPI row — membership + billing overview (juntraweb-local data only;
 * MLM commission figures live upstream on Thaiprompt and are shown on /mlm).
 */
class BillingStatsWidget extends BaseWidget
{
    protected static ?int $sort = 0;
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    protected function getStats(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();

        $membersTotal = User::where('role', 'member')->count();
        $membersNew   = User::where('role', 'member')->where('created_at', '>=', $monthStart)->count();

        // Revenue = approved (success) top-ups. amount stored positive.
        $revenueMonth = (float) WalletTransaction::where('type', 'topup')->where('status', 'success')
            ->where('created_at', '>=', $monthStart)->sum('amount');
        $revenueAll = (float) WalletTransaction::where('type', 'topup')->where('status', 'success')->sum('amount');

        $outstanding = (float) Wallet::sum('balance');
        $pending     = WalletTransaction::where('type', 'topup')->where('status', 'pending')->count();
        $readings    = Reading::count();

        return [
            Stat::make('สมาชิกทั้งหมด', number_format($membersTotal))
                ->description('เดือนนี้ +' . number_format($membersNew) . ' คน')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('primary'),

            Stat::make('รายได้เดือนนี้', '฿' . number_format($revenueMonth, 2))
                ->description('สะสมทั้งหมด ฿' . number_format($revenueAll, 2))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('เครดิตคงค้างในระบบ', '฿' . number_format($outstanding, 2))
                ->description('ยอดวอลเลตรวมของสมาชิก')
                ->descriptionIcon('heroicon-m-wallet')
                ->color('info'),

            Stat::make('รอตรวจสลิป', number_format($pending))
                ->description($pending > 0 ? 'มีรายการรออนุมัติ' : 'ไม่มีค้าง')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pending > 0 ? 'warning' : 'gray'),

            Stat::make('คำทำนายทั้งหมด', number_format($readings))
                ->description('ทุกศาสตร์รวมกัน')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('primary'),
        ];
    }
}
