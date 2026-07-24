<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReadingResource\Pages;
use App\Models\Reading;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only window into member readings (all six fortune systems) for support
 * and moderation. Readings are created by the app/web flows, never in admin —
 * so no create/edit, just browse + view + toggle public sharing.
 */
class ReadingResource extends Resource
{
    protected static ?string $model = Reading::class;
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $modelLabel = 'คำทำนาย';
    protected static ?string $pluralModelLabel = 'คำทำนาย';
    protected static ?string $navigationGroup = 'ดูดวง';
    protected static ?int $navigationSort = 70;

    public static function canCreate(): bool
    {
        return false;
    }

    private const TYPE_LABELS = [
        'tarot' => 'ไพ่ยิปซี',
        'numerology' => 'เลขศาสตร์',
        'palmistry' => 'ลายมือ',
        'auspicious' => 'ฤกษ์ยาม',
        'horoscope' => 'ดวงรายวัน',
        'chat' => 'แชท',
    ];

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Tables\Columns\TextColumn::make('created_at')->label('เวลา')->dateTime('d/m/Y H:i')->sortable(),
            Tables\Columns\TextColumn::make('user.name')->label('ผู้ใช้')->searchable()
                ->placeholder('ผู้เยี่ยมชม (ไม่ล็อกอิน)'),
            Tables\Columns\TextColumn::make('type')->label('ประเภท')->badge()
                ->formatStateUsing(fn ($state) => self::TYPE_LABELS[$state] ?? $state),
            Tables\Columns\TextColumn::make('question')->label('คำถาม')->limit(40)->tooltip(fn ($state) => $state)->placeholder('—'),
            Tables\Columns\TextColumn::make('ai_provider')->label('AI')->badge()->toggleable()->placeholder('—'),
            Tables\Columns\IconColumn::make('shared_public')->label('แชร์สาธารณะ')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('type')->label('ประเภท')->options(self::TYPE_LABELS),
            Tables\Filters\TernaryFilter::make('shared_public')->label('แชร์สาธารณะ'),
        ])->actions([
            Tables\Actions\ViewAction::make()->label('ดู'),
            Tables\Actions\DeleteAction::make()->label('ลบ')
                ->visible(fn () => (bool) auth()->user()?->isAdmin()),
        ])->bulkActions([
            Tables\Actions\DeleteBulkAction::make(),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(3)->schema([
                Infolists\Components\TextEntry::make('user.name')->label('ผู้ใช้')->placeholder('ผู้เยี่ยมชม'),
                Infolists\Components\TextEntry::make('type')->label('ประเภท')->badge()
                    ->formatStateUsing(fn ($state) => self::TYPE_LABELS[$state] ?? $state),
                Infolists\Components\TextEntry::make('created_at')->label('เวลา')->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('ai_provider')->label('AI Provider')->placeholder('—'),
                Infolists\Components\TextEntry::make('ai_model')->label('AI Model')->placeholder('—'),
                Infolists\Components\IconEntry::make('shared_public')->label('แชร์สาธารณะ')->boolean(),
            ]),
            Infolists\Components\TextEntry::make('question')->label('คำถาม')->placeholder('—')->columnSpanFull(),
            Infolists\Components\TextEntry::make('result')->label('คำทำนาย')->placeholder('—')
                ->markdown()->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReadings::route('/'),
            'view' => Pages\ViewReading::route('/{record}'),
        ];
    }
}
