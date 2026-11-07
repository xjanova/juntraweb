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
            Forms\Components\Toggle::make('approved')->default(true)
                ->helperText('รีวิวจากลูกค้าขึ้นหน้าแรกได้เฉพาะเมื่อลูกค้าติ๊กยอมไว้ด้วย — อนุมัติรีวิวที่ลูกค้าไม่ยอม จะไม่ขึ้นหน้าแรก'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('order_index')->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('service'),
            Tables\Columns\TextColumn::make('rating')->formatStateUsing(fn($state) => str_repeat('★', (int) $state)),
            Tables\Columns\IconColumn::make('approved')->boolean()->label('อนุมัติ'),
            // 🌟 (2026-11-07) รีวิวจากลูกค้าหลังจบคำทำนาย — ขึ้นหน้าแรกได้เมื่อลูกค้ายอม + แอดมินอนุมัติ
            Tables\Columns\IconColumn::make('allow_public')->boolean()->label('ลูกค้ายอมให้โชว์')
                ->getStateUsing(fn (Testimonial $r) => $r->reading_id ? $r->allow_public : null),
            Tables\Columns\TextColumn::make('reading_id')->label('คำทำนาย')->placeholder('แอดมินเขียน')
                ->formatStateUsing(fn ($state) => '#'.$state)
                ->url(fn (Testimonial $r) => $r->reading_id ? route('tarot.show', $r->reading_id) : null, true),
            Tables\Columns\TextColumn::make('message')->limit(60),
            Tables\Columns\TextColumn::make('created_at')->label('เมื่อ')->since()->sortable(),
        ])->filters([
            Tables\Filters\TernaryFilter::make('from_customers')->label('ที่มา')
                ->placeholder('ทั้งหมด')->trueLabel('รีวิวจากลูกค้า')->falseLabel('แอดมินเขียน')
                ->queries(
                    true: fn ($q) => $q->whereNotNull('reading_id'),
                    false: fn ($q) => $q->whereNull('reading_id'),
                ),
            Tables\Filters\TernaryFilter::make('approved')->label('อนุมัติแล้ว'),
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
