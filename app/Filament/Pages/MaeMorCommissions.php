<?php

namespace App\Filament\Pages;

use App\Services\Affiliate\MaeMorAdmin;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * 🌙 ค่าแนะนำของผังแม่หมอ — ภาพรวม · อนุมัติ · ปฏิเสธ · ปรับยอด · จ่ายเข้ากระเป๋า · สร้างมือ
 *
 * เจ้าของสั่ง (2026-09-21): เว็บใครเว็บมัน — หน้านี้เห็นและจัดการเฉพาะค่าแนะนำจากบิลจันทรา
 *   (Thaiprompt บังคับฝั่งนั้นด้วย) ค่าแนะนำจากบิลบอทจัดการที่หลังบ้านแม่หมอ
 *   ข้อมูลทุกแถวมาจาก Thaiprompt สด ๆ และทุกปุ่มสั่งให้ Thaiprompt ทำ (กติกาเงินอยู่ที่นั่นที่เดียว)
 */
class MaeMorCommissions extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'ค่าแนะนำ';

    protected static ?string $title = 'ค่าแนะนำ (ผังแม่หมอ)';

    protected static ?string $navigationGroup = 'ผังแม่หมอ';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.maemor-commissions';

    public string $status = 'all';

    public string $level = 'all';

    public string $search = '';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public int $listPage = 1;

    /** @var array<int, string> id ของแถวที่ติ๊กเลือก */
    public array $selected = [];

    public array $rows = [];

    public array $meta = [];

    public array $overview = [];

    public ?string $error = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    public function mount(): void
    {
        $this->load();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'level', 'search', 'dateFrom', 'dateTo'], true)) {
            $this->listPage = 1;
            $this->selected = [];
            $this->load();
        }
    }

    public function gotoPage(int $page): void
    {
        $this->listPage = max(1, min($page, (int) ($this->meta['last_page'] ?? 1)));
        $this->selected = [];
        $this->load();
    }

    public function load(): void
    {
        $admin = app(MaeMorAdmin::class);
        $dates = array_filter(['date_from' => $this->dateFrom, 'date_to' => $this->dateTo]);

        $list = $admin->commissions([
            'status' => $this->status,
            'level' => $this->level,
            'search' => trim($this->search),
        ] + $dates, $this->listPage);
        $overview = $admin->overview($dates);

        $this->error = $list['ok'] ? null : $list['message'];
        $this->rows = $list['ok'] ? (array) ($list['data']['data'] ?? []) : [];
        $this->meta = $list['ok'] ? (array) ($list['data']['meta'] ?? []) : [];
        $this->overview = $overview['ok'] ? (array) ($overview['data']['data'] ?? []) : [];
    }

    public function approveSelected(): void
    {
        $this->bulk(fn (MaeMorAdmin $admin, array $ids) => $admin->approve(auth()->user(), $ids), 'อนุมัติ');
    }

    public function paySelected(): void
    {
        $this->bulk(fn (MaeMorAdmin $admin, array $ids) => $admin->pay(auth()->user(), $ids), 'จ่ายเข้ากระเป๋า');
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('ปฏิเสธ')
            ->color('danger')
            ->size('xs')
            ->link()
            ->modalHeading('ปฏิเสธค่าแนะนำ')
            ->modalDescription('ปฏิเสธได้เฉพาะรายการที่รอดำเนินการ — รายการที่จ่ายแล้วดึงคืนได้ด้วยการคืนเงินบิลนั้นในหลังบ้านจันทรา')
            ->form([Textarea::make('reason')->label('เหตุผล')->maxLength(500)])
            ->action(function (array $data, array $arguments) {
                $res = app(MaeMorAdmin::class)->reject(auth()->user(), (int) $arguments['id'], $data['reason'] ?? null);
                $this->notify($res, 'ปฏิเสธแล้ว');
            });
    }

    public function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label('ปรับยอด')
            ->size('xs')
            ->link()
            ->modalHeading('ปรับจำนวนเงินค่าแนะนำ')
            ->modalDescription('ปรับได้เฉพาะรายการที่ยังไม่จ่ายเข้ากระเป๋า')
            ->fillForm(fn (array $arguments) => ['amount' => $arguments['amount'] ?? null])
            ->form([
                TextInput::make('amount')->label('จำนวนใหม่ (บาท)')->numeric()->minValue(0)->step(0.01)->required(),
                Textarea::make('reason')->label('เหตุผล')->maxLength(500),
            ])
            ->action(function (array $data, array $arguments) {
                $res = app(MaeMorAdmin::class)->adjust(auth()->user(), (int) $arguments['id'], (float) $data['amount'], $data['reason'] ?? null);
                $this->notify($res, 'ปรับยอดแล้ว');
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createManual')
                ->label('สร้างรายการเอง')
                ->icon('heroicon-o-plus')
                ->modalHeading('สร้างค่าแนะนำด้วยมือ')
                ->modalDescription('ใช้ซ่อมค่าแนะนำที่ตกหล่นของบิลเว็บ/แอพจันทรา — สร้างเป็นสถานะ "รอ" ต้องกดจ่ายแยกอีกครั้ง · ไม่เกินยอดบิล · บิลที่คืนเงินแล้วสร้างไม่ได้')
                ->form([
                    TextInput::make('bill_id')->label('เลขบิลจันทรา')
                        ->helperText('เลขรายการตัดเครดิตของบิลนั้น (ที่แสดงเป็น JW-เลขนี้ ในรายการค่าแนะนำ)')
                        ->prefix('JW-')->integer()->minValue(1)->required(),
                    TextInput::make('user_id')->label('id ผู้รับ (Thaiprompt)')
                        ->helperText('ต้องเป็นผู้แนะนำชั้นนั้นของเจ้าของบิลในผังตอนนี้ — ใส่ผิด ระบบจะบอกว่าผู้รับที่ถูกคือใคร')
                        ->integer()->minValue(1)->required(),
                    Select::make('level')->label('ชั้น')->options([1 => 'สายตรง', 2 => 'หลาน'])->required(),
                    TextInput::make('amount')->label('จำนวน (บาท)')->numeric()->minValue(0.01)->step(0.01)->required(),
                    Textarea::make('notes')->label('หมายเหตุ')->maxLength(400),
                ])
                ->action(function (array $data) {
                    // ลูกค้าเจ้าของบิล = เจ้าของบิล JW-… ที่ Thaiprompt หาให้เอง
                    $res = app(MaeMorAdmin::class)->createManual(auth()->user(), [
                        'bill_id' => (int) $data['bill_id'],
                        'user_id' => (int) $data['user_id'],
                        'level' => (int) $data['level'],
                        'amount' => (float) $data['amount'],
                        'notes' => $data['notes'] ?? null,
                    ]);
                    $this->notify($res, 'สร้างรายการแล้ว');
                }),
            Action::make('refresh')->label('ดึงข้อมูลใหม่')->icon('heroicon-o-arrow-path')->color('gray')
                ->action(fn () => $this->load()),
        ];
    }

    /**
     * ค่าแนะนำที่แม่หมอโอนเข้ากระเป๋ากลางแทนผู้แนะนำ → เหตุผลภาษาไทย · null = จ่ายผู้แนะนำตามปกติ
     *
     * แม่หมอบอกผ่านหมายเหตุ "[CENTRAL_FALLBACK:เหตุผล] …" (FortuneCommissionService::payToCentralWallet)
     *   ไม่แปลไว้ ช่องผู้รับจะเป็นชื่อบัญชีกลาง (ว่างอยู่) แอดมินไม่รู้ว่าทำไมผู้เชิญไม่ได้ค่าแนะนำ
     */
    public static function centralFallbackReason(?string $notes): ?string
    {
        if (! preg_match('/\[CENTRAL_FALLBACK:([a-z_]+)\]/', (string) $notes, $m)) {
            return null;
        }

        return match ($m[1]) {
            'no_referrer' => 'ลูกค้าไม่มีผู้แนะนำ',
            'sponsor_missing' => 'ไม่พบผู้แนะนำในผัง',
            'sponsor_inactive' => 'ผู้แนะนำไม่ active ตามเกณฑ์รักษายอดของแม่หมอ',
            'no_grandparent' => 'ไม่มีผู้รับชั้นหลาน',
            'grandparent_missing' => 'ไม่พบผู้รับชั้นหลานในผัง',
            'grandparent_inactive' => 'ผู้รับชั้นหลานไม่ active ตามเกณฑ์รักษายอดของแม่หมอ',
            default => $m[1],
        };
    }

    /* ============================================================ */

    private function bulk(callable $call, string $verb): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->selected)));
        if ($ids === []) {
            Notification::make()->title('ยังไม่ได้เลือกรายการ')->warning()->send();

            return;
        }

        $res = $call(app(MaeMorAdmin::class), $ids);
        if ($res['ok']) {
            $data = (array) ($res['data']['data'] ?? []);
            $count = (int) ($data['count'] ?? 0);
            $errors = (array) ($data['errors'] ?? []);
            $n = Notification::make()->title("{$verb} {$count} จาก " . count($ids) . ' รายการ');
            ($errors === [] ? $n->success() : $n->warning()->body(implode("\n", array_slice($errors, 0, 5))))->send();
        } else {
            Notification::make()->title("{$verb}ไม่สำเร็จ")->body($res['message'])->danger()->send();
        }

        $this->selected = [];
        $this->load();
    }

    private function notify(array $res, string $okTitle): void
    {
        $res['ok']
            ? Notification::make()->title($okTitle)->success()->send()
            : Notification::make()->title('ไม่สำเร็จ')->body($res['message'])->danger()->send();

        $this->load();
    }
}
