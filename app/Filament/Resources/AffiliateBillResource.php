<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AffiliateBillResource\Pages;
use App\Models\AffiliateBill;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * 🌙 สมุดส่งบิลไปผังแม่หมอ — บิลไหนส่งแล้ว ค้างอยู่เพราะอะไร คืนเงินแล้วดึงค่าแนะนำคืนหรือยัง
 *
 * ยอดค่าแนะนำจริงอยู่ที่ผังแม่หมอ (หน้า "ค่าแนะนำ") — ที่นี่เป็นแค่สถานะการส่ง
 */
class AffiliateBillResource extends Resource
{
    protected static ?string $model = AffiliateBill::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $modelLabel = 'บิลที่ส่งแม่หมอ';

    protected static ?string $pluralModelLabel = 'บิลที่ส่งแม่หมอ';

    protected static ?string $navigationGroup = 'ผังแม่หมอ';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        // เมนูนี้ render ทุกหน้าหลังบ้าน — ช่วง deploy ก่อน migrate ตารางยังไม่มี ห้ามทำทุกหน้า 500
        try {
            $n = AffiliateBill::needsAdmin()->count();
        } catch (\Throwable) {
            return null;
        }

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('ลงสมุด')->dateTime('d/m/y H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('ลูกค้า')->searchable()
                    ->description(fn (AffiliateBill $b) => $b->user?->maemor_member_code),
                Tables\Columns\TextColumn::make('product')->label('รายการ')->limit(30)
                    // จ่ายก่อนเปิดระบบค่าแนะนำ — ส่งให้แม่หมอนับว่าลูกค้า "เคยมีบิลที่ชำระแล้ว" เท่านั้น
                    ->description(fn (AffiliateBill $b) => $b->history_only ? 'บิลก่อนเปิดระบบ · นับสิทธิ์ ไม่มีค่าแนะนำ' : null),
                Tables\Columns\TextColumn::make('amount')->label('ยอดบิล')->money('THB')->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('สถานะ')->badge()
                    ->formatStateUsing(fn (string $state) => AffiliateBill::statusLabel($state))
                    ->color(fn (string $state) => match ($state) {
                        AffiliateBill::STATUS_SENT => 'success',
                        AffiliateBill::STATUS_FAILED => 'danger',
                        AffiliateBill::STATUS_PENDING => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('commission_total')->label('ค่าแนะนำที่แม่หมอแจก')->money('THB')->alignEnd()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('bill_reference')->label('เลขบิลที่แม่หมอ')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('attempts')->label('ครั้งที่ลอง')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('last_error')->label('ปัญหาล่าสุด')->limit(50)->wrap()
                    ->tooltip(fn (AffiliateBill $b) => $b->last_error)->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('สถานะ')->options(collect([
                    AffiliateBill::STATUS_PENDING, AffiliateBill::STATUS_SENT, AffiliateBill::STATUS_FAILED,
                    AffiliateBill::STATUS_VOIDED, AffiliateBill::STATUS_SKIPPED,
                ])->mapWithKeys(fn ($s) => [$s => AffiliateBill::statusLabel($s)])->all()),
            ])
            ->actions([
                Action::make('resend')
                    ->label('ส่งซ้ำ')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (AffiliateBill $b) => $b->status === AffiliateBill::STATUS_FAILED)
                    ->requiresConfirmation()
                    ->modalDescription('ส่งซ้ำได้ปลอดภัย — แม่หมอไม่จ่ายค่าแนะนำซ้ำสำหรับบิลเดียวกัน')
                    ->action(fn (AffiliateBill $b) => self::queueResend(collect([$b]))),
            ])
            ->bulkActions([
                BulkAction::make('resendSelected')
                    ->label('ส่งซ้ำที่เลือก')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => self::queueResend($records)),
            ]);
    }

    /** ส่งไม่ผ่าน → กลับไปรอส่งทันทีในรอบถัดไปของ affiliate:sync-bills (ทุกนาที) */
    private static function queueResend($bills): void
    {
        $n = 0;
        foreach ($bills as $bill) {
            if ($bill->status !== AffiliateBill::STATUS_FAILED) {
                continue;
            }
            // attempts คงไว้ — ใช้ตัดสินว่าต้องสั่งยกเลิกที่แม่หมอถ้าลูกค้าได้เงินคืนภายหลัง
            $bill->update([
                'status' => AffiliateBill::STATUS_PENDING,
                'next_attempt_at' => null,
            ]);
            $n++;
        }

        Notification::make()->title("ตั้งส่งซ้ำ {$n} บิล — จะส่งภายใน 1 นาที")->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAffiliateBills::route('/'),
        ];
    }
}
