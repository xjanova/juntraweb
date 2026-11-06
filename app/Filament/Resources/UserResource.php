<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Support\WalletAdminForms;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $modelLabel = 'สมาชิก';
    protected static ?string $pluralModelLabel = 'สมาชิก';
    protected static ?string $navigationGroup = 'สมาชิก';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    /** Include soft-deleted members so the "ถังขยะ" filter can surface them. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->withCount(['readings', 'chatConversations'])
            ->with('wallet');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('ข้อมูลบัญชี')->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('name')->label('ชื่อ')->required(),
                    Forms\Components\TextInput::make('email')->label('อีเมล')->email()->required()->unique(ignoreRecord: true),
                    Forms\Components\Select::make('role')->label('บทบาท')->options([
                        'admin' => 'Admin', 'editor' => 'Editor', 'member' => 'สมาชิก',
                    ])->default('member')->required(),
                    Forms\Components\TextInput::make('password')->label('รหัสผ่าน')->password()
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $context) => $context === 'create')
                        ->helperText('เว้นว่างหากไม่ต้องการเปลี่ยน'),
                ]),
            ]),
            Forms\Components\Section::make('การเชื่อมต่อบัญชีภายนอก')
                ->description('อ่านอย่างเดียว — ตั้งค่าโดยระบบ SSO ตอนผู้ใช้เชื่อมบัญชี')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('thaiprompt_user_id')->label('Thaiprompt User ID')->disabled(),
                        Forms\Components\TextInput::make('signup_via')->label('ช่องทางสมัคร')->disabled(),
                        Forms\Components\TextInput::make('facebook_user_id')->label('Facebook ID')->disabled(),
                        Forms\Components\TextInput::make('line_user_id')->label('LINE ID')->disabled(),
                    ]),
                ])->collapsed()
                ->hiddenOn('create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Tables\Columns\TextColumn::make('name')->label('ชื่อ')->searchable()
                ->description(fn (User $r) => $r->email),
            Tables\Columns\BadgeColumn::make('role')->label('บทบาท')->colors([
                'danger' => 'admin', 'warning' => 'editor', 'gray' => 'member',
            ])->formatStateUsing(fn ($state) => match ($state) {
                'admin' => 'Admin', 'editor' => 'Editor', default => 'สมาชิก',
            }),
            Tables\Columns\TextColumn::make('wallet.balance')->label('ยอดวอลเลต')
                ->formatStateUsing(fn ($state) => '฿' . number_format((float) ($state ?? 0), 2))
                ->color(fn ($state) => (float) ($state ?? 0) > 0 ? 'success' : 'gray')
                ->sortable(),
            Tables\Columns\TextColumn::make('link')->label('เชื่อมบัญชี')
                ->badge()
                ->state(fn (User $r) => array_values(array_filter([
                    $r->thaiprompt_user_id ? 'Thaiprompt' : null,
                    $r->facebook_user_id ? 'Facebook' : null,
                    $r->line_user_id ? 'LINE' : null,
                ])) ?: ['—'])
                ->color(fn ($state) => match ($state) {
                    'Thaiprompt' => 'warning', 'Facebook' => 'info', 'LINE' => 'success', default => 'gray',
                }),
            Tables\Columns\TextColumn::make('readings_count')->label('คำทำนาย')->alignCenter()->sortable()->toggleable(),
            Tables\Columns\TextColumn::make('chat_conversations_count')->label('แชท')->alignCenter()->sortable()->toggleable(),
            Tables\Columns\TextColumn::make('created_at')->label('สมัครเมื่อ')->dateTime('d/m/Y')->sortable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('role')->label('บทบาท')->options([
                'admin' => 'Admin', 'editor' => 'Editor', 'member' => 'สมาชิก',
            ]),
            Tables\Filters\SelectFilter::make('signup_via')->label('ช่องทางสมัคร')->options([
                'email' => 'อีเมล', 'thaiprompt' => 'Thaiprompt', 'facebook' => 'Facebook', 'line' => 'LINE',
            ]),
            Tables\Filters\TernaryFilter::make('thaiprompt_user_id')
                ->label('เชื่อม Thaiprompt')
                ->nullable()
                ->trueLabel('เชื่อมแล้ว')->falseLabel('ยังไม่เชื่อม'),
            Tables\Filters\TrashedFilter::make()->label('สถานะบัญชี'),
        ])->actions([
            Tables\Actions\ViewAction::make()->label('ดู'),
            Tables\Actions\EditAction::make()->label('แก้ไข'),
            Tables\Actions\ActionGroup::make(self::walletTableActions())
                ->label('จัดการเงิน')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->button()
                ->visible(fn (User $r) => self::canManageWalletOf($r)),
            Tables\Actions\RestoreAction::make()->label('กู้คืน'),
        ])->bulkActions([
            Tables\Actions\DeleteBulkAction::make(),
            Tables\Actions\RestoreBulkAction::make(),
        ]);
    }

    /**
     * ปุ่มเติม/หัก/คืนเงิน และโอนเครดิต — กติกาเงินทั้งหมดอยู่ที่ AdminWalletService
     * (audit + กันกดซ้ำ + ยอดอ้างอิง + ห้ามติดลบ) ที่นี่แค่ UI
     *
     * @return array<Tables\Actions\Action>
     */
    public static function walletTableActions(): array
    {
        return [
            Tables\Actions\Action::make('adjustWallet')
                ->label('เติม / หัก / คืนเงิน')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (User $r) => self::canManageWalletOf($r))
                ->modalHeading(fn (User $r) => 'จัดการเงินในวอลเลตของ ' . $r->name)
                ->modalSubmitActionLabel('ยืนยันทำรายการ')
                ->fillForm(fn (User $r) => WalletAdminForms::adjustFill($r))
                ->steps(WalletAdminForms::adjustSteps())
                ->action(fn (User $r, array $data) => WalletAdminForms::handleAdjust($r, $data)),
            Tables\Actions\Action::make('transferWallet')
                ->label('โอนเครดิตไปบัญชีอื่น')
                ->icon('heroicon-o-arrows-right-left')
                ->color('info')
                ->visible(fn (User $r) => self::canManageWalletOf($r))
                ->modalHeading(fn (User $r) => 'โอนเครดิตจากวอลเลตของ ' . $r->name)
                ->modalSubmitActionLabel('ยืนยันโอน')
                ->fillForm(fn (User $r) => WalletAdminForms::transferFill($r))
                ->steps(WalletAdminForms::transferSteps())
                ->action(fn (User $r, array $data) => WalletAdminForms::handleTransfer($r, $data)),
        ];
    }

    /**
     * ปุ่มจัดการเงินโชว์เฉพาะแอดมิน และไม่โชว์บนบัญชีของตัวเอง/บัญชีที่ถูกลบ
     * (ซ่อนปุ่มเป็นแค่ UI — ด่านจริงอยู่ที่ AdminWalletService)
     */
    public static function canManageWalletOf(User $user): bool
    {
        $me = auth()->user();

        return $me instanceof User && $me->isAdmin() && (int) $me->id !== (int) $user->id && ! $user->trashed();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
