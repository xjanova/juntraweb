<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReadingResource\Pages;
use App\Models\Reading;
use App\Services\Readings\ReadingBillActions;
use App\Support\ReadingBill;
use App\Support\TarotSpreads;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 🧾 บิลดูดวง — หลังบ้านจัดการบิล + คำทำนายของเว็บ แบบเดียวกับหน้าจัดการบิลดูดวงของ Thaiprompt
 *
 * เจ้าของสั่ง (2026-09-15): บิลและคำทำนายของเว็บจันทราแยกเก็บจาก Thaiprompt (ฝั่งนั้นแค่ให้ AI ทำนาย)
 * แต่ต้องมีระบบจัดการหลังบ้านเหมือนกัน — เลขบิล แพ็กเกจ ยอด สถานะ ช่องทาง โมเดล AI · กรองตามสถานะ/
 * แพ็กเกจ/ช่วงวัน · สรุปรายได้ · ปุ่มคืนเงิน/ทำนายใหม่/เปิดหน้าที่ลูกค้าเห็น · ส่งออก CSV
 *
 * รายการถูกสร้างโดยหน้าเว็บ/แอพเท่านั้น (ไม่มีสร้าง/แก้ในหลังบ้าน) — บิลที่จ่ายเงินลบไม่ได้ (เอกสารการเงิน)
 */
class ReadingResource extends Resource
{
    protected static ?string $model = Reading::class;
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationLabel = 'บิลดูดวง';
    protected static ?string $modelLabel = 'บิลดูดวง';
    protected static ?string $pluralModelLabel = 'บิลดูดวง';
    protected static ?string $navigationGroup = 'ดูดวง';
    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    /** ป้ายนับบนเมนู = รายการที่แม่หมอกำลังอ่านอยู่ (ถ้าค้างนาน ตัวกวาดจะคืนเงินเอง) */
    public static function getNavigationBadge(): ?string
    {
        $n = Reading::whereIn('status', [Reading::STATUS_PENDING, Reading::STATUS_WORKING])->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** ตัวเลือกแพ็กเกจในตัวกรอง — ไพ่ทุกแบบ (รวมที่ปิดขาย) + บริการอื่น */
    public static function packageOptions(): array
    {
        $opts = [];
        foreach (TarotSpreads::registry() as $key => $meta) {
            $opts[TarotSpreads::typeFromKey($key)] = 'ไพ่ · '.$meta['name_th'];
        }

        return $opts + ReadingBill::SERVICE_LABELS;
    }

    /** ตัวกรองสถานะ — สัญญาเดียวกับ ReadingBill::status() */
    public static function applyStatus(Builder $q, ?string $status): Builder
    {
        return match ($status) {
            'reading' => $q->whereIn('status', [Reading::STATUS_PENDING, Reading::STATUS_WORKING]),
            'failed' => $q->where('status', Reading::STATUS_FAILED),
            'refunded' => $q->whereNull('status')->whereNotNull('payload->refunded_at'),
            'free' => $q->whereNull('status')->whereNull('payload->refunded_at')
                ->where(fn ($w) => $w->whereNull('payload->cost')->orWhere('payload->cost', '<=', 0)),
            'paid' => $q->whereNull('status')->whereNull('payload->refunded_at')->where('payload->cost', '>', 0),
            default => $q,
        };
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('bill_no')->label('เลขบิล')
                    ->state(fn (Reading $record) => ReadingBill::number($record))
                    ->fontFamily('mono')->copyable()->size('sm')
                    ->searchable(query: fn (Builder $query, string $search) => ($id = ReadingBill::idFromNumber($search))
                        ? $query->where('readings.id', $id) : $query->whereRaw('0 = 1')),
                Tables\Columns\TextColumn::make('created_at')->label('วันที่')->dateTime('d/m/y H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('ลูกค้า')->searchable()
                    ->description(fn (Reading $record) => $record->user?->email ?: $record->user?->phone)
                    ->placeholder('ผู้เยี่ยมชม (ไม่ล็อกอิน)'),
                Tables\Columns\TextColumn::make('package')->label('แพ็กเกจ')
                    ->state(fn (Reading $record) => ReadingBill::package($record))->badge()->color('primary'),
                Tables\Columns\TextColumn::make('amount')->label('ยอด')
                    ->state(fn (Reading $record) => ReadingBill::amount($record))
                    ->formatStateUsing(fn ($state) => $state > 0 ? '฿'.number_format((float) $state, 2) : 'ฟรี')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('bill_status')->label('สถานะ')
                    ->state(fn (Reading $record) => ReadingBill::statusLabel($record))
                    ->badge()->color(fn (Reading $record) => ReadingBill::statusColor($record)),
                Tables\Columns\TextColumn::make('channel')->label('ช่องทาง')
                    ->state(fn (Reading $record) => ReadingBill::channel($record))->toggleable(),
                Tables\Columns\TextColumn::make('question')->label('คำถาม')->limit(40)
                    ->tooltip(fn ($state) => $state)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('ai_model')->label('โมเดล AI')->badge()->color('gray')
                    ->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('shared_public')->label('แชร์')->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bill_status')->label('สถานะ')
                    ->options(collect(ReadingBill::STATUSES)->map(fn ($s) => $s[0])->all())
                    ->query(fn (Builder $query, array $data) => self::applyStatus($query, $data['value'] ?? null)),
                Tables\Filters\SelectFilter::make('type')->label('แพ็กเกจ')->options(fn () => self::packageOptions()),
                Tables\Filters\SelectFilter::make('channel')->label('ช่องทาง')
                    ->options(['web' => 'เว็บ', 'mobile' => 'แอพ'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'mobile' => $query->where('payload->source', 'mobile'),
                        'web' => $query->where(fn ($w) => $w->whereNull('payload->source')->orWhere('payload->source', '!=', 'mobile')),
                        default => $query,
                    }),
                Tables\Filters\Filter::make('created_at')->label('ช่วงวันที่')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('ตั้งแต่วันที่'),
                        Forms\Components\DatePicker::make('until')->label('ถึงวันที่'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($w, $d) => $w->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($w, $d) => $w->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'ตั้งแต่ '.$data['from'] : null,
                        ($data['until'] ?? null) ? 'ถึง '.$data['until'] : null,
                    ])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('ดูบิล'),
                Tables\Actions\ActionGroup::make(self::billActions()),
            ])
            ->bulkActions([]);
    }

    /**
     * ปุ่มจัดการบิล — ใช้ทั้งในตาราง (เมนู ⋯) และหน้าดูบิล
     *
     * @return array<int, Tables\Actions\Action|\Filament\Actions\Action>
     */
    public static function billActions(bool $header = false): array
    {
        $A = $header ? \Filament\Actions\Action::class : Tables\Actions\Action::class;

        return [
            $A::make('customer_page')->label('เปิดหน้าที่ลูกค้าเห็น')->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (Reading $record) => ReadingBill::customerUrl($record))->openUrlInNewTab(),

            $A::make('refund')->label('คืนเงิน')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->visible(fn (Reading $record) => ReadingBill::refundable($record))
                ->requiresConfirmation()
                ->modalHeading(fn (Reading $record) => 'คืนเงินบิล '.ReadingBill::number($record))
                ->modalDescription(fn (Reading $record) => 'คืน ฿'.number_format(ReadingBill::amount($record), 2)
                    .' เข้าวอลเลตของลูกค้า — คำทำนายยังอยู่ให้ลูกค้าอ่านได้ บิลจะขึ้นว่า "คืนเงินแล้ว"')
                ->form([
                    Forms\Components\Textarea::make('reason')->label('เหตุผล (ลูกค้าเห็นในรายการวอลเลต)')
                        ->required()->maxLength(300)->rows(2),
                ])
                ->action(function (Reading $record, array $data) {
                    $ok = app(ReadingBillActions::class)->refund($record, (string) $data['reason'], auth()->user());
                    $ok
                        ? Notification::make()->title('คืนเงินเรียบร้อย')->success()->send()
                        : Notification::make()->title('คืนเงินไม่ได้ — บิลนี้ถูกคืนไปแล้วหรือไม่ได้ตัดเงิน')->warning()->send();
                }),

            $A::make('retry')->label('ให้แม่หมอทำนายใหม่')->icon('heroicon-o-arrow-path')->color('warning')
                ->visible(fn (Reading $record) => ReadingBill::retryable($record))
                ->requiresConfirmation()
                ->modalHeading('ทำนายใหม่จากไพ่ชุดเดิม')
                ->modalDescription('แม่หมอจะอ่านไพ่ใบเดิม ตำแหน่งเดิมใหม่อีกครั้ง (ไม่หักเงินเพิ่ม) — คำทำนายเดิมจะถูกแทนที่ ลูกค้าเห็นสถานะ "กำลังอ่านไพ่" ประมาณ 15 วินาทีถึง 1 นาที')
                ->action(function (Reading $record) {
                    app(ReadingBillActions::class)->retry($record)
                        ? Notification::make()->title('สั่งทำนายใหม่แล้ว — รีเฟรชหน้าในอีกสักครู่')->success()->send()
                        : Notification::make()->title('ทำนายใหม่ไม่ได้ในตอนนี้ (กำลังอ่านอยู่แล้ว)')->warning()->send();
                }),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('บิล')->columns(4)->schema([
                Infolists\Components\TextEntry::make('bill_no')->label('เลขบิล')
                    ->state(fn (Reading $record) => ReadingBill::number($record))->fontFamily('mono')->copyable(),
                Infolists\Components\TextEntry::make('bill_status')->label('สถานะ')
                    ->state(fn (Reading $record) => ReadingBill::statusLabel($record))->badge()
                    ->color(fn (Reading $record) => ReadingBill::statusColor($record)),
                Infolists\Components\TextEntry::make('amount')->label('ยอด')
                    ->state(fn (Reading $record) => ReadingBill::amount($record) > 0 ? '฿'.number_format(ReadingBill::amount($record), 2) : 'ฟรี'),
                Infolists\Components\TextEntry::make('package')->label('แพ็กเกจ')
                    ->state(fn (Reading $record) => ReadingBill::package($record))->badge()->color('primary'),
                Infolists\Components\TextEntry::make('user.name')->label('ลูกค้า')->placeholder('ผู้เยี่ยมชม')
                    ->helperText(fn (Reading $record) => $record->user?->email ?: $record->user?->phone),
                Infolists\Components\TextEntry::make('channel')->label('ช่องทาง')
                    ->state(fn (Reading $record) => ReadingBill::channel($record)),
                Infolists\Components\TextEntry::make('created_at')->label('สร้างเมื่อ')->dateTime('d/m/Y H:i:s'),
                Infolists\Components\TextEntry::make('updated_at')->label('อัปเดตล่าสุด')->dateTime('d/m/Y H:i:s'),
            ]),

            Infolists\Components\Section::make('การเงิน')->columns(4)->collapsible()->schema([
                Infolists\Components\TextEntry::make('tx')->label('รายการตัดเงิน')
                    ->state(fn (Reading $record) => ($t = ReadingBill::debit($record)) ? '#'.$t->id.' · ฿'.number_format(abs((float) $t->amount), 2) : '— (ไม่ได้ตัดเงิน)'),
                Infolists\Components\TextEntry::make('tx_status')->label('สถานะธุรกรรม')
                    ->state(fn (Reading $record) => match (ReadingBill::debit($record)?->status) {
                        'success' => 'ตัดเงินแล้ว', 'refunded' => 'คืนเงินแล้ว', null => '—', default => ReadingBill::debit($record)->status,
                    })->badge(),
                Infolists\Components\TextEntry::make('payload.refunded_at')->label('คืนเงินโดยแอดมินเมื่อ')->placeholder('—'),
                Infolists\Components\TextEntry::make('payload.refund_reason')->label('เหตุผลที่คืน')->placeholder('—'),
            ]),

            Infolists\Components\Section::make('คำขอของลูกค้า')->columns(2)->schema([
                Infolists\Components\TextEntry::make('question')->label('คำถาม')->placeholder('— ไม่ได้ระบุคำถาม —')->columnSpan(1),
                Infolists\Components\TextEntry::make('payload.birth_date')->label('วันเกิดที่กรอก')->placeholder('—'),
            ]),

            Infolists\Components\Section::make('ไพ่ที่เปิดได้')->collapsible()
                ->visible(fn (Reading $record) => $record->tarotCards()->exists())
                ->schema([
                    Infolists\Components\RepeatableEntry::make('tarotCards')->label('')->columns(3)->schema([
                        Infolists\Components\TextEntry::make('position_label')->label('ตำแหน่ง')
                            ->prefix(fn ($record) => 'ใบที่ '.$record->position.' · '),
                        Infolists\Components\TextEntry::make('card.name_th')->label('ไพ่'),
                        Infolists\Components\TextEntry::make('reversed')->label('ทิศ')
                            ->formatStateUsing(fn ($state) => $state ? 'กลับหัว' : 'ตั้งตรง')->badge()
                            ->color(fn ($state) => $state ? 'warning' : 'success'),
                    ]),
                ]),

            Infolists\Components\Section::make('คำทำนาย')->schema([
                Infolists\Components\TextEntry::make('ai')->label('ทำนายโดย')
                    ->state(fn (Reading $record) => trim(($record->ai_provider ?? '').' · '.($record->ai_model ?? ''), ' ·') ?: '—'),
                Infolists\Components\TextEntry::make('result')->label('')->markdown()
                    ->placeholder(fn (Reading $record) => $record->isInProgress() ? 'แม่หมอกำลังอ่านไพ่อยู่…' : ($record->isFailed() ? 'อ่านไม่สำเร็จ — ระบบคืนเงินให้ลูกค้าแล้ว' : '—'))
                    ->columnSpanFull(),
            ]),
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
