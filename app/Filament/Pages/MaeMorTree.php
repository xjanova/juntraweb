<?php

namespace App\Filament\Pages;

use App\Services\Affiliate\MaeMorAdmin;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * 🌙 ผังสายงานแม่หมอ — ค้นหาใครก็ได้ ดูยอด/ผัง และย้ายผู้แนะนำ (ทั้งทีมย้ายตาม)
 *
 * การย้ายสายทำที่ Thaiprompt (adminDirectTransfer ตัวเดียวกับหลังบ้านแม่หมอ):
 *   กันย้ายเข้าลูกทีมตัวเอง · โยกตัวนับทีม · บันทึกว่าแอดมินจันทราคนไหนสั่ง
 */
class MaeMorTree extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationLabel = 'ผังสายงาน';

    protected static ?string $title = 'ผังสายงาน (ผังแม่หมอ)';

    protected static ?string $navigationGroup = 'ผังแม่หมอ';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.maemor-tree';

    public string $q = '';

    public array $users = [];

    public ?int $userId = null;

    public int $depth = 3;

    public array $stats = [];

    public array $tree = [];

    public ?string $error = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    public function mount(): void
    {
        $this->searchUsers();
    }

    public function updatedQ(): void
    {
        $this->searchUsers();
    }

    public function updatedDepth(): void
    {
        if ($this->userId) {
            $this->show($this->userId);
        }
    }

    public function searchUsers(): void
    {
        $res = app(MaeMorAdmin::class)->users(trim($this->q));
        $this->error = $res['ok'] ? null : $res['message'];
        $this->users = $res['ok'] ? (array) ($res['data']['data'] ?? []) : [];
    }

    /** @param  int  $thaipromptUserId  id ผู้ใช้ฝั่งผังแม่หมอ */
    public function show(int $thaipromptUserId): void
    {
        $admin = app(MaeMorAdmin::class);
        $this->userId = $thaipromptUserId;
        $this->depth = max(1, min($this->depth, 10));

        $stats = $admin->userStats($thaipromptUserId);
        $tree = $admin->userTree($thaipromptUserId, $this->depth);

        $this->error = $stats['ok'] && $tree['ok'] ? null : ($stats['message'] ?? $tree['message']);
        $this->stats = $stats['ok'] ? (array) $stats['data'] : [];
        $this->tree = $tree['ok'] ? (array) $tree['data'] : [];
    }

    public function moveAction(): Action
    {
        return Action::make('move')
            ->label('ย้ายสาย')
            ->size('xs')
            ->link()
            ->modalHeading(fn (array $arguments) => 'ย้ายผู้แนะนำของ ' . ($arguments['name'] ?? 'สมาชิก'))
            ->modalDescription('ย้ายได้เฉพาะลูกค้าจันทรา · ทั้งทีมใต้สมาชิกคนนี้ย้ายตามไปด้วย · ค่าแนะนำที่จ่ายไปแล้วไม่ย้าย · มีผลกับบิลถัดไป')
            ->form([
                Select::make('new_sponsor_member_id')
                    ->label('ผู้แนะนำคนใหม่ (ค้นด้วยรหัสสมาชิก ชื่อ หรืออีเมล)')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(function (string $search) {
                        $res = app(MaeMorAdmin::class)->searchMembers($search);

                        return collect($res['ok'] ? ($res['data']['data'] ?? []) : [])
                            ->mapWithKeys(fn ($m) => [$m['id'] => "{$m['member_code']} · {$m['name']}"])
                            ->all();
                    }),
                Textarea::make('notes')->label('เหตุผล')->maxLength(400)->required(),
            ])
            ->requiresConfirmation()
            ->action(function (array $data, array $arguments) {
                $res = app(MaeMorAdmin::class)->moveMember(
                    auth()->user(),
                    (int) $arguments['member'],
                    (int) $data['new_sponsor_member_id'],
                    $data['notes'] ?? null,
                );

                $res['ok']
                    ? Notification::make()->title('ย้ายสายแล้ว')->success()->send()
                    : Notification::make()->title('ย้ายไม่สำเร็จ')->body($res['message'])->danger()->send();

                if ($this->userId) {
                    $this->show($this->userId);
                }
            });
    }
}
