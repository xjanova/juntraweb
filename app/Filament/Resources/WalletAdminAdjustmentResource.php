<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletAdminAdjustmentResource\Pages;
use App\Models\User;
use App\Models\WalletAdminAdjustment;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ประวัติการจัดการเงินโดยแอดมิน (อ่านอย่างเดียว) — จากตาราง wallet_admin_adjustments
 *
 * ไม่มีปุ่มสร้าง/แก้/ลบ — เป็นหลักฐานทางบัญชี ทุกแถวเกิดจาก AdminWalletService เท่านั้น
 */
class WalletAdminAdjustmentResource extends Resource
{
    protected static ?string $model = WalletAdminAdjustment::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $modelLabel = 'ประวัติการจัดการเงินโดยแอดมิน';
    protected static ?string $pluralModelLabel = 'ประวัติการจัดการเงินโดยแอดมิน';
    protected static ?string $navigationLabel = 'ประวัติจัดการเงิน (แอดมิน)';
    protected static ?string $navigationGroup = 'การเงิน';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'wallet-admin-adjustments';

    /** ประวัติเงินดูได้เฉพาะ admin — editor เข้าแผงได้แต่ไม่เห็นหน้านี้ */
    public static function canViewAny(): bool
    {
        $me = auth()->user();

        return $me instanceof User && $me->isAdmin();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'admin']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('เวลา')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('action')->label('รายการ')->badge()
                    ->formatStateUsing(fn ($state) => WalletAdminAdjustment::ACTION_LABELS[$state] ?? $state)
                    ->color(fn ($state) => WalletAdminAdjustment::ACTION_COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('user.name')->label('ผู้ใช้')
                    ->description(fn (WalletAdminAdjustment $r) => $r->user?->email)
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('จำนวน')
                    ->formatStateUsing(fn ($state, WalletAdminAdjustment $r) => ($r->isCredit() ? '+' : '−') . '฿' . number_format((float) $state, 2))
                    ->color(fn (WalletAdminAdjustment $r) => $r->isCredit() ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('balance_after')->label('ยอดก่อน → หลัง')
                    ->formatStateUsing(fn ($state, WalletAdminAdjustment $r) => '฿' . number_format((float) $r->balance_before, 2) . ' → ฿' . number_format((float) $state, 2)),
                Tables\Columns\TextColumn::make('reason')->label('เหตุผล')->limit(50)->tooltip(fn (WalletAdminAdjustment $r) => $r->reason)->wrap()->searchable(),
                Tables\Columns\TextColumn::make('admin_name')->label('แอดมิน')->searchable(),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')->label('ประเภท')->options(WalletAdminAdjustment::ACTION_LABELS),
                Tables\Filters\SelectFilter::make('admin_id')->label('แอดมิน')
                    ->options(fn () => User::where('role', 'admin')->pluck('name', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('ดู'),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('รายการ')->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema([
                TextEntry::make('action')->label('ประเภท')->badge()
                    ->formatStateUsing(fn ($state) => WalletAdminAdjustment::ACTION_LABELS[$state] ?? $state)
                    ->color(fn ($state) => WalletAdminAdjustment::ACTION_COLORS[$state] ?? 'gray'),
                TextEntry::make('amount')->label('จำนวน')
                    ->formatStateUsing(fn ($state, WalletAdminAdjustment $record) => ($record->isCredit() ? '+' : '−') . '฿' . number_format((float) $state, 2)),
                TextEntry::make('balance_after')->label('ยอดก่อน → หลัง')
                    ->formatStateUsing(fn ($state, WalletAdminAdjustment $record) => '฿' . number_format((float) $record->balance_before, 2) . ' → ฿' . number_format((float) $state, 2)),
                TextEntry::make('user.name')->label('ผู้ใช้'),
                TextEntry::make('admin_name')->label('แอดมินที่ทำ'),
                TextEntry::make('created_at')->label('เวลา')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('reason')->label('เหตุผล')->columnSpanFull(),
                TextEntry::make('wallet_transaction_id')->label('ธุรกรรมที่เกิด')
                    ->formatStateUsing(fn ($state) => '#' . $state)
                    ->url(fn ($state) => $state ? WalletTransactionResource::getUrl('view', ['record' => $state]) : null)
                    ->placeholder('—'),
                TextEntry::make('original_transaction_id')->label('รายการต้นฉบับ')
                    ->formatStateUsing(fn ($state) => '#' . $state)
                    ->url(fn ($state) => $state ? WalletTransactionResource::getUrl('view', ['record' => $state]) : null)
                    ->placeholder('—'),
            ]),
            Section::make('ที่มาของคำสั่ง')->columns(['default' => 1, 'sm' => 2])->collapsible()->schema([
                TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                TextEntry::make('idempotency_key')->label('โทเคนฟอร์ม')->placeholder('—')->copyable(),
                TextEntry::make('user_agent')->label('เบราว์เซอร์ (User-Agent)')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletAdminAdjustments::route('/'),
            'view'  => Pages\ViewWalletAdminAdjustment::route('/{record}'),
        ];
    }
}
