<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TestimonialResource\Pages;
use App\Models\Testimonial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TestimonialResource extends Resource
{
    protected static ?string $model = Testimonial::class;
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $modelLabel = 'รีวิว';
    protected static ?string $pluralModelLabel = 'รีวิว';
    protected static ?string $navigationGroup = 'เนื้อหา';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')->required(),
                Forms\Components\TextInput::make('service')->required(),
                Forms\Components\Select::make('rating')->options([1=>'1★',2=>'2★',3=>'3★',4=>'4★',5=>'5★'])->default(5)->required(),
                Forms\Components\TextInput::make('order_index')->numeric()->default(0),
            ]),
            Forms\Components\Textarea::make('message')->required()->rows(4),
            Forms\Components\Toggle::make('approved')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('order_index')->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('service'),
            Tables\Columns\TextColumn::make('rating')->formatStateUsing(fn($s) => str_repeat('★', (int) $s)),
            Tables\Columns\IconColumn::make('approved')->boolean(),
            Tables\Columns\TextColumn::make('message')->limit(60),
        ])->actions([Tables\Actions\EditAction::make()])
          ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTestimonials::route('/'),
            'create' => Pages\CreateTestimonial::route('/create'),
            'edit' => Pages\EditTestimonial::route('/{record}/edit'),
        ];
    }
}
