<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyHoroscopeResource\Pages;
use App\Models\DailyHoroscope;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DailyHoroscopeResource extends Resource
{
    protected static ?string $model = DailyHoroscope::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $modelLabel = 'ดวงรายวัน';
    protected static ?string $pluralModelLabel = 'ดวงรายวัน';
    protected static ?string $navigationGroup = 'เนื้อหา';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('zodiac_id')->relationship('zodiac', 'name_th')->required(),
                Forms\Components\DatePicker::make('date')->required(),
            ]),
            Forms\Components\Textarea::make('summary')->required()->rows(2),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Textarea::make('love')->rows(3),
                Forms\Components\Textarea::make('career')->rows(3),
                Forms\Components\Textarea::make('money')->rows(3),
                Forms\Components\Textarea::make('health')->rows(3),
            ]),
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('lucky_number'),
                Forms\Components\TextInput::make('lucky_color'),
                Forms\Components\TextInput::make('lucky_card'),
            ]),
            Forms\Components\Toggle::make('ai_generated')->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('date', 'desc')->columns([
            Tables\Columns\TextColumn::make('date')->date()->sortable(),
            Tables\Columns\TextColumn::make('zodiac.name_th')->label('ราศี'),
            Tables\Columns\TextColumn::make('summary')->limit(50),
            Tables\Columns\IconColumn::make('ai_generated')->boolean()->label('AI'),
        ])->actions([Tables\Actions\EditAction::make()])
          ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyHoroscopes::route('/'),
            'create' => Pages\CreateDailyHoroscope::route('/create'),
            'edit' => Pages\EditDailyHoroscope::route('/{record}/edit'),
        ];
    }
}
