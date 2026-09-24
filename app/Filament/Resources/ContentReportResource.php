<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContentReportResource\Pages;
use App\Models\ContentReport;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * คิวรายงานเนื้อหาที่ AI สร้าง (ลูกค้ากดรายงานจากแอพ/เว็บ)
 * Google Play บังคับให้มีช่องทางรายงาน และให้ผู้พัฒนาใช้รายงานปรับปรุงการกรองเนื้อหา
 */
class ContentReportResource extends Resource
{
    protected static ?string $model = ContentReport::class;
    protected static ?string $navigationIcon = 'heroicon-o-flag';
    protected static ?string $modelLabel = 'รายงานเนื้อหา AI';
    protected static ?string $pluralModelLabel = 'รายงานเนื้อหา AI';
    protected static ?string $navigationGroup = 'ดูดวง';
    protected static ?int $navigationSort = 30;

    public static function getNavigationBadge(): ?string
    {
        $open = ContentReport::where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('เวลา')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('สถานะ')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'open' => 'รอตรวจ', 'resolved' => 'แก้ไขแล้ว', 'dismissed' => 'ไม่ต้องทำอะไร', default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'warning', 'resolved' => 'success', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('reason')->label('เหตุผล')
                    ->formatStateUsing(fn (string $state) => ContentReport::REASONS[$state] ?? $state),
                Tables\Columns\TextColumn::make('subject_type')->label('ประเภท')
                    ->formatStateUsing(fn (string $state, ContentReport $record) => (ContentReport::SUBJECTS[$state] ?? $state) . ' #' . $record->subject_id),
                Tables\Columns\TextColumn::make('user.name')->label('ลูกค้า')->placeholder('-'),
                Tables\Columns\TextColumn::make('snapshot')->label('เนื้อหา')->limit(80)->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('สถานะ')->options([
                    'open' => 'รอตรวจ', 'resolved' => 'แก้ไขแล้ว', 'dismissed' => 'ไม่ต้องทำอะไร',
                ])->default('open'),
                Tables\Filters\SelectFilter::make('reason')->label('เหตุผล')->options(ContentReport::REASONS),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->form([
                    Forms\Components\Placeholder::make('reason_label')->label('เหตุผล')
                        ->content(fn (ContentReport $record) => ContentReport::REASONS[$record->reason] ?? $record->reason),
                    Forms\Components\Textarea::make('note')->label('ลูกค้าเขียนว่า')->rows(3)->disabled(),
                    Forms\Components\Textarea::make('snapshot')->label('เนื้อหาที่ถูกรายงาน (ตอนที่รายงาน)')->rows(14)->disabled(),
                ]),
                Tables\Actions\Action::make('resolve')->label('แก้ไขแล้ว')->icon('heroicon-o-check')
                    ->visible(fn (ContentReport $record) => $record->status === 'open')
                    ->requiresConfirmation()
                    ->action(fn (ContentReport $record) => $record->update([
                        'status' => 'resolved', 'resolved_by' => auth()->id(), 'resolved_at' => now(),
                    ])),
                Tables\Actions\Action::make('dismiss')->label('ไม่ต้องทำอะไร')->icon('heroicon-o-x-mark')->color('gray')
                    ->visible(fn (ContentReport $record) => $record->status === 'open')
                    ->requiresConfirmation()
                    ->action(fn (ContentReport $record) => $record->update([
                        'status' => 'dismissed', 'resolved_by' => auth()->id(), 'resolved_at' => now(),
                    ])),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentReports::route('/'),
        ];
    }
}
