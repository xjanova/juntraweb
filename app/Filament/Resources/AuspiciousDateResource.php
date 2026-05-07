<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuspiciousDateResource\Pages;
use App\Models\AuspiciousDate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AuspiciousDateResource extends Resource
{
    protected static ?string $model = AuspiciousDate::class;
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $modelLabel = 'ฤกษ์ยาม';
    protected static ?string $pluralModelLabel = 'ฤกษ์ยาม';
    protected static ?string $navigationGroup = 'เนื้อหา';
    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\DatePicker::make('date')->required(),
                Forms\Components\Select::make('type')->options([
                    'wedding' => 'แต่งงาน',
                    'business' => 'เปิดกิจการ',
                    'house' => 'ขึ้นบ้านใหม่',
                    'travel' => 'เดินทาง',
                    'general' => 'งานทั่วไป',
                ])->required(),
            ]),
            Forms\Components\TextInput::make('title')->required(),
            Forms\Components\Textarea::make('description')->rows(3),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('date')->columns([
            Tables\Columns\TextColumn::make('date')->date()->sortable(),
            Tables\Columns\TextColumn::make('type')->badge(),
            Tables\Columns\TextColumn::make('title')->searchable(),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->actions([Tables\Actions\EditAction::make()])
          ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuspiciousDates::route('/'),
            'create' => Pages\CreateAuspiciousDate::route('/create'),
            'edit' => Pages\EditAuspiciousDate::route('/{record}/edit'),
        ];
    }
}
