<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make()];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('ข้อมูลสมาชิก')->columns(3)->schema([
                TextEntry::make('name')->label('ชื่อ'),
                TextEntry::make('email')->label('อีเมล')->copyable(),
                TextEntry::make('role')->label('บทบาท')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'admin' => 'Admin', 'editor' => 'Editor', default => 'สมาชิก',
                    })
                    ->color(fn ($state) => match ($state) {
                        'admin' => 'danger', 'editor' => 'warning', default => 'gray',
                    }),
                TextEntry::make('signup_via')->label('ช่องทางสมัคร')->placeholder('—')->badge(),
                TextEntry::make('created_at')->label('สมัครเมื่อ')->dateTime('d/m/Y H:i'),
                TextEntry::make('deleted_at')->label('สถานะ')
                    ->formatStateUsing(fn ($state) => $state ? 'ถูกลบ (soft-delete)' : 'ใช้งานอยู่')
                    ->badge()->color(fn ($state) => $state ? 'danger' : 'success'),
            ]),

            Section::make('การเชื่อมต่อบัญชีภายนอก')->columns(3)->schema([
                TextEntry::make('thaiprompt_user_id')->label('Thaiprompt')->placeholder('ยังไม่เชื่อม'),
                TextEntry::make('facebook_user_id')->label('Facebook')->placeholder('—'),
                TextEntry::make('line_user_id')->label('LINE')->placeholder('—'),
            ])->collapsible(),

            Section::make('วอลเลต')->columns(3)->schema([
                TextEntry::make('wallet.balance')->label('ยอดคงเหลือ')
                    ->formatStateUsing(fn ($state) => '฿' . number_format((float) ($state ?? 0), 2))
                    ->weight('bold')->size(TextEntry\TextEntrySize::Large)->color('success'),
                TextEntry::make('readings_count')->label('จำนวนคำทำนาย')
                    ->state(fn (User $r) => $r->readings()->count()),
                TextEntry::make('chat_conversations_count')->label('จำนวนบทสนทนา')
                    ->state(fn (User $r) => $r->chatConversations()->count()),
            ]),

            Section::make('ธุรกรรมวอลเลตล่าสุด')->schema([
                RepeatableEntry::make('recentTransactions')
                    ->label('')
                    ->state(fn (User $r) => $r->walletTransactions()->limit(10)->get())
                    ->columns(4)
                    ->schema([
                        TextEntry::make('created_at')->label('เวลา')->dateTime('d/m H:i'),
                        TextEntry::make('type')->label('ประเภท')->badge()
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'topup' => 'เติมเงิน', 'debit' => 'หักค่าบริการ',
                                'refund' => 'คืนเครดิต', 'adjustment' => 'ปรับยอด', default => $state,
                            }),
                        TextEntry::make('amount')->label('จำนวน')
                            ->formatStateUsing(fn ($state) => ((float) $state >= 0 ? '+' : '−') . '฿' . number_format(abs((float) $state), 2))
                            ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
                        TextEntry::make('status')->label('สถานะ')->badge()
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'pending' => 'รอตรวจสอบ', 'success' => 'สำเร็จ', 'failed' => 'ปฏิเสธ',
                                'refunded' => 'คืนเงินแล้ว', 'cancelled' => 'ยกเลิก', default => $state,
                            })
                            ->color(fn ($state) => match ($state) {
                                'success' => 'success', 'pending' => 'warning',
                                'failed' => 'danger', default => 'gray',
                            }),
                    ])
                    ->placeholder('ยังไม่มีธุรกรรม'),
            ])->collapsible(),
        ]);
    }
}
