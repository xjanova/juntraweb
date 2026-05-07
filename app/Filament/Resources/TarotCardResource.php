<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TarotCardResource\Pages;
use App\Models\TarotCard;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TarotCardResource extends Resource
{
    protected static ?string $model = TarotCard::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $modelLabel = 'ไพ่ยิปซี';

    protected static ?string $pluralModelLabel = 'ไพ่ยิปซี';

    protected static ?string $navigationGroup = 'เนื้อหา';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(64),
                Forms\Components\Select::make('arcana')->required()->options(['major' => 'Major', 'minor' => 'Minor']),
                Forms\Components\TextInput::make('name_en')->required()->maxLength(64),
                Forms\Components\TextInput::make('name_th')->required()->maxLength(64),
                Forms\Components\Select::make('suit')->required()->options([
                    'major' => 'Major', 'wands' => 'Wands', 'cups' => 'Cups',
                    'swords' => 'Swords', 'pentacles' => 'Pentacles',
                ]),
                Forms\Components\TextInput::make('number')->required()->numeric()->minValue(0)->maxValue(99),
            ]),
            Forms\Components\TextInput::make('image_path')->maxLength(191)->helperText('เช่น images/card-magician.png'),
            Forms\Components\TextInput::make('keywords_th')->maxLength(255),
            Forms\Components\Textarea::make('upright_meaning_th')->required()->rows(3),
            Forms\Components\Textarea::make('reversed_meaning_th')->required()->rows(3),
            Forms\Components\Textarea::make('love_th')->rows(2),
            Forms\Components\Textarea::make('career_th')->rows(2),
            Forms\Components\Textarea::make('money_th')->rows(2),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')->sortable(),
                Tables\Columns\TextColumn::make('name_th')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name_en')->searchable(),
                Tables\Columns\BadgeColumn::make('arcana')->colors(['warning' => 'major', 'gray' => 'minor']),
                Tables\Columns\TextColumn::make('suit'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('arcana')->options(['major' => 'Major', 'minor' => 'Minor']),
                Tables\Filters\SelectFilter::make('suit')->options([
                    'major' => 'Major', 'wands' => 'Wands', 'cups' => 'Cups',
                    'swords' => 'Swords', 'pentacles' => 'Pentacles',
                ]),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTarotCards::route('/'),
            'create' => Pages\CreateTarotCard::route('/create'),
            'edit' => Pages\EditTarotCard::route('/{record}/edit'),
        ];
    }
}
