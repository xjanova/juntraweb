<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ZodiacResource\Pages;
use App\Models\Zodiac;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ZodiacResource extends Resource
{
    protected static ?string $model = Zodiac::class;
    protected static ?string $navigationIcon = 'heroicon-o-star';
    protected static ?string $modelLabel = 'ราศี (ตะวันตก)';
    protected static ?string $pluralModelLabel = 'ราศี (ตะวันตก)';
    protected static ?string $navigationGroup = 'เนื้อหา';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('order_index')->required()->numeric()->minValue(1)->maxValue(12),
                Forms\Components\TextInput::make('name_th')->required(),
                Forms\Components\TextInput::make('name_en')->required(),
                Forms\Components\TextInput::make('glyph')->required()->maxLength(8),
                Forms\Components\TextInput::make('element')->required(),
                Forms\Components\TextInput::make('ruler'),
                Forms\Components\TextInput::make('date_range')->required(),
            ]),
            Forms\Components\Textarea::make('traits_th')->required()->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('order_index')->columns([
            Tables\Columns\TextColumn::make('order_index')->label('#'),
            Tables\Columns\TextColumn::make('glyph'),
            Tables\Columns\TextColumn::make('name_th')->searchable(),
            Tables\Columns\TextColumn::make('name_en'),
            Tables\Columns\TextColumn::make('element'),
            Tables\Columns\TextColumn::make('date_range'),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZodiacs::route('/'),
            'create' => Pages\CreateZodiac::route('/create'),
            'edit' => Pages\EditZodiac::route('/{record}/edit'),
        ];
    }
}
