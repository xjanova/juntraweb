<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\WalletTransactionResource;
use App\Models\WalletTransaction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quick-glance list of top-ups awaiting slip review, straight on the dashboard.
 * Approve/reject actions live on the full WalletTransaction resource — this
 * widget just links there so the operator sees the queue on login.
 */
class PendingTopupsWidget extends BaseWidget
{
    protected static ?string $heading = 'รายการเติมเงินที่รอตรวจสอบ';
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                WalletTransaction::query()
                    ->where('type', 'topup')
                    ->where('status', 'pending')
                    ->latest('id')
            )
            ->emptyStateHeading('ไม่มีรายการค้าง 🎉')
            ->emptyStateDescription('ทุกการเติมเงินได้รับการตรวจสอบแล้ว')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('เวลา')->dateTime('d/m H:i'),
                Tables\Columns\TextColumn::make('user.name')->label('ผู้ใช้')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('ยอดแจ้ง')
                    ->formatStateUsing(fn ($state) => '฿' . number_format((float) $state, 2)),
                Tables\Columns\IconColumn::make('slip_path')->label('สลิป')->boolean()
                    ->trueIcon('heroicon-o-photo')->trueColor('success')
                    ->falseIcon('heroicon-o-x-circle')->falseColor('gray'),
                Tables\Columns\TextColumn::make('reference_code')->label('Ref')->copyable()->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('ตรวจสอบ')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (WalletTransaction $r) => WalletTransactionResource::getUrl('view', ['record' => $r]))
                    ->openUrlInNewTab(),
            ])
            ->paginated([5, 10]);
    }
}
