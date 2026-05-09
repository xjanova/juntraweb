<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletTransactionResource\Pages;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;
    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $modelLabel      = 'ธุรกรรมวอลเลต';
    protected static ?string $pluralModelLabel = 'ธุรกรรมวอลเลต';
    protected static ?string $navigationGroup = 'การเงิน';
    protected static ?int    $navigationSort  = 1;

    public static function getNavigationBadge(): ?string
    {
        // Cached 30s — admin sidebar otherwise queries this on every page
        // load. The Approve/Reject actions invalidate via Cache::forget().
        $pending = Cache::remember(
            'wallet:pending_topup_count',
            30,
            fn () => static::getModel()::where('status', 'pending')->where('type', 'topup')->count(),
        );
        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('user.name')->label('ผู้ใช้')->disabled(),
                Forms\Components\TextInput::make('reference_code')->label('Ref')->disabled(),
                Forms\Components\TextInput::make('type')->disabled(),
                Forms\Components\TextInput::make('status')->disabled(),
                Forms\Components\TextInput::make('amount')->prefix('฿')->disabled(),
                Forms\Components\TextInput::make('balance_after')->prefix('฿')->disabled(),
            ]),
            Forms\Components\Textarea::make('description')->disabled()->rows(2),
            Forms\Components\Textarea::make('admin_note')
                ->label('หมายเหตุของแอดมิน (ใช้เมื่อปฏิเสธ)')
                ->dehydrated(false) // not stored on this column directly — used by reject action
                ->rows(2),
            Forms\Components\Placeholder::make('slip')
                ->label('สลิป')
                ->content(function (?WalletTransaction $record) {
                    if (!$record?->slip_path) return '— ไม่มีสลิป —';
                    // Slips live on the private disk — serve them via the
                    // auth-gated wallet.topup.slip route, never as a direct URL.
                    $url = route('wallet.topup.slip', $record);
                    return new \Illuminate\Support\HtmlString(
                        '<a href="' . e($url) . '" target="_blank"><img src="' . e($url) . '" style="max-width:320px;border:1px solid #ccc;border-radius:8px"></a>'
                    );
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('เวลา')->dateTime('d/m H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('ผู้ใช้')->searchable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('ประเภท')
                    ->colors([
                        'success' => 'topup',
                        'gray'    => 'debit',
                        'warning' => 'refund',
                        'info'    => 'adjustment',
                    ]),
                Tables\Columns\TextColumn::make('amount')
                    ->label('จำนวน')
                    ->formatStateUsing(fn ($state) => '฿' . number_format(abs((float) $state), 2))
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('balance_after')
                    ->label('คงเหลือ')
                    ->formatStateUsing(fn ($state) => $state !== null ? '฿' . number_format((float) $state, 2) : '—'),
                Tables\Columns\TextColumn::make('method')->label('ช่องทาง')->badge(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('สถานะ')
                    ->colors([
                        'success' => 'success',
                        'warning' => 'pending',
                        'danger'  => 'failed',
                        'gray'    => 'refunded',
                    ]),
                Tables\Columns\TextColumn::make('reference_code')->label('Ref')->copyable()->toggleable(),
                Tables\Columns\IconColumn::make('slip_path')
                    ->label('สลิป')
                    ->boolean()
                    ->trueIcon('heroicon-o-photo')
                    ->trueColor('success')
                    ->falseIcon('heroicon-o-x-circle')
                    ->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'  => 'รอตรวจสอบ',
                        'success'  => 'สำเร็จ',
                        'failed'   => 'ปฏิเสธ',
                        'refunded' => 'คืนเงิน',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'topup'      => 'เติมเงิน',
                        'debit'      => 'หักเครดิต',
                        'refund'     => 'คืนเครดิต',
                        'adjustment' => 'ปรับยอด',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Action::make('approve')
                    ->label('อนุมัติ')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WalletTransaction $r) => $r->type === 'topup' && $r->status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription(fn (WalletTransaction $r) => 'จะเครดิต ฿' . number_format((float) $r->amount, 2) . ' ให้ ' . ($r->user->name ?? '#' . $r->user_id))
                    ->action(function (WalletTransaction $r) {
                        try {
                            app(WalletService::class)->approveTopup($r, auth()->user());
                            Cache::forget('wallet:pending_topup_count');
                            Notification::make()->title('อนุมัติเรียบร้อย')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('อนุมัติไม่สำเร็จ: ' . $e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('reject')
                    ->label('ปฏิเสธ')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WalletTransaction $r) => $r->type === 'topup' && $r->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('เหตุผล')->rows(2)->required(),
                    ])
                    ->action(function (WalletTransaction $r, array $data) {
                        try {
                            app(WalletService::class)->rejectTopup($r, auth()->user(), $data['reason'] ?? null);
                            Cache::forget('wallet:pending_topup_count');
                            Notification::make()->title('ปฏิเสธเรียบร้อย')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('ปฏิเสธไม่สำเร็จ: ' . $e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletTransactions::route('/'),
            'view'  => Pages\ViewWalletTransaction::route('/{record}'),
        ];
    }
}
