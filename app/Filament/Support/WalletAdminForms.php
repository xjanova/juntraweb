<?php

namespace App\Filament\Support;

use App\Exceptions\WalletAdminException;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\AdminWalletService;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * ฟอร์ม + ตัวจัดการของปุ่ม "จัดการเงิน" ในแผงแอดมิน (ใช้ร่วมกันหลายหน้า)
 *
 * ทุกฟอร์มเป็น wizard 2 ขั้น: กรอกรายการ → หน้ายืนยันที่โชว์ยอดก่อน → หลัง
 * ปุ่มบันทึกมีเฉพาะขั้นยืนยัน
 *
 * ค่าซ่อนในฟอร์ม (ใส่ตอนเปิดหน้าต่างผ่าน fillForm):
 *  - idempotency_key : UUID ต่อการเปิดหน้าต่าง 1 ครั้ง — กดยืนยันซ้ำ/ส่งซ้ำ = ทำครั้งเดียว
 *  - expected_balance: ยอดตอนเปิดหน้าต่าง — ถ้ามีรายการอื่นแทรกระหว่างนั้น service จะปฏิเสธ
 *
 * กติกาเงินจริงทั้งหมดอยู่ที่ AdminWalletService — ที่นี่แค่ UI
 */
class WalletAdminForms
{
    public const DIRECTIONS = [
        'credit' => 'เติมเครดิต',
        'debit'  => 'หักเครดิต',
        'refund' => 'คืนเงิน',
    ];

    // ───────────────────────── เติม / หัก / คืนเงิน ─────────────────────────

    /** ค่าตั้งต้นตอนเปิดหน้าต่าง (ผูกกับผู้ใช้คนเดียว) */
    public static function adjustFill(?User $user): array
    {
        return [
            'user_id'          => $user?->id,
            'direction'        => 'credit',
            'idempotency_key'  => (string) Str::uuid(),
            'expected_balance' => $user ? self::balanceOf($user) : null,
        ];
    }

    /** @return array<Step> */
    public static function adjustSteps(bool $pickUser = false): array
    {
        return [
            Step::make('รายการ')
                ->icon('heroicon-o-pencil-square')
                ->schema([
                    ...self::userField('user_id', 'ผู้ใช้', $pickUser, 'expected_balance'),
                    Forms\Components\ToggleButtons::make('direction')
                        ->label('ประเภทรายการ')
                        ->options(self::DIRECTIONS)
                        ->icons([
                            'credit' => 'heroicon-o-plus-circle',
                            'debit'  => 'heroicon-o-minus-circle',
                            'refund' => 'heroicon-o-arrow-uturn-left',
                        ])
                        ->colors(['credit' => 'success', 'debit' => 'danger', 'refund' => 'warning'])
                        ->inline()->required()->live(),
                    self::amountField(),
                    self::reasonField(),
                    Forms\Components\Hidden::make('idempotency_key'),
                    Forms\Components\Hidden::make('expected_balance'),
                ]),
            Step::make('ยืนยัน')
                ->icon('heroicon-o-shield-check')
                ->schema([
                    Forms\Components\Placeholder::make('confirm_summary')
                        ->hiddenLabel()
                        ->content(function (Get $get) {
                            $user   = self::findUser($get('user_id'));
                            $before = (string) ($get('expected_balance') ?? '0');
                            $amount = self::num($get('amount'));
                            $credit = $get('direction') !== 'debit';
                            $after  = $credit ? bcadd($before, $amount, 2) : bcsub($before, $amount, 2);

                            return self::summary([
                                'ผู้ใช้'     => $user ? self::userLabel($user) : '—',
                                'ประเภท'    => self::DIRECTIONS[$get('direction')] ?? '—',
                                'จำนวน'     => ($credit ? '+' : '−') . '฿' . self::fmt($amount),
                                'เหตุผล'    => (string) $get('reason'),
                            ], [[$user ? self::userLabel($user) : 'ยอดวอลเลต', $before, $after]]);
                        }),
                ]),
        ];
    }

    public static function handleAdjust(User $user, array $data): void
    {
        self::attempt(function () use ($user, $data) {
            $tx = app(AdminWalletService::class)->adjust(
                $user,
                (string) ($data['direction'] ?? ''),
                (string) ($data['amount'] ?? ''),
                (string) ($data['reason'] ?? ''),
                auth()->user(),
                $data['idempotency_key'] ?? null,
                $data['expected_balance'] ?? null,
            );

            return [$tx, (self::DIRECTIONS[$data['direction']] ?? 'ทำรายการ') . 'สำเร็จ — ยอดคงเหลือ ฿' . self::fmt((string) $tx->balance_after)];
        });
    }

    // ───────────────────────────── โอนเครดิต ─────────────────────────────

    public static function transferFill(?User $from): array
    {
        return [
            'from_user_id'     => $from?->id,
            'to_user_id'       => null,
            'idempotency_key'  => (string) Str::uuid(),
            'expected_balance' => $from ? self::balanceOf($from) : null,
            'to_balance'       => null,
        ];
    }

    /** @return array<Step> */
    public static function transferSteps(bool $pickFrom = false): array
    {
        return [
            Step::make('รายการ')
                ->icon('heroicon-o-arrows-right-left')
                ->schema([
                    ...self::userField('from_user_id', 'โอนจาก', $pickFrom, 'expected_balance'),
                    ...self::userField('to_user_id', 'โอนเข้าบัญชี', true, 'to_balance', 'from_user_id'),
                    self::amountField(),
                    self::reasonField(),
                    Forms\Components\Hidden::make('idempotency_key'),
                    Forms\Components\Hidden::make('expected_balance'),
                    Forms\Components\Hidden::make('to_balance'),
                ]),
            Step::make('ยืนยัน')
                ->icon('heroicon-o-shield-check')
                ->schema([
                    Forms\Components\Placeholder::make('confirm_summary')
                        ->hiddenLabel()
                        ->content(function (Get $get) {
                            $from   = self::findUser($get('from_user_id'));
                            $to     = self::findUser($get('to_user_id'));
                            $amount = self::num($get('amount'));
                            $fb     = (string) ($get('expected_balance') ?? '0');
                            $tb     = (string) ($get('to_balance') ?? ($to ? self::balanceOf($to) : '0'));

                            return self::summary([
                                'จำนวน'  => '฿' . self::fmt($amount),
                                'เหตุผล' => (string) $get('reason'),
                            ], [
                                ['โอนจาก ' . ($from ? self::userLabel($from) : '—'), $fb, bcsub($fb, $amount, 2)],
                                ['เข้า ' . ($to ? self::userLabel($to) : '—'), $tb, bcadd($tb, $amount, 2)],
                            ]);
                        }),
                ]),
        ];
    }

    public static function handleTransfer(User $from, array $data): void
    {
        self::attempt(function () use ($from, $data) {
            $to = User::find($data['to_user_id'] ?? null);
            if (! $to) {
                throw new WalletAdminException('กรุณาเลือกบัญชีปลายทาง');
            }
            $res = app(AdminWalletService::class)->transfer(
                $from, $to,
                (string) ($data['amount'] ?? ''),
                (string) ($data['reason'] ?? ''),
                auth()->user(),
                $data['idempotency_key'] ?? null,
                $data['expected_balance'] ?? null,
            );
            $res['out']->wasRecentlyCreated = ! $res['replayed'];

            return [$res['out'], 'โอนเครดิต ฿' . self::fmt(ltrim((string) $res['out']->amount, '-')) . ' ไปยัง ' . self::userLabel($to) . ' สำเร็จ'];
        });
    }

    // ───────────────────────────── ย้อนรายการ ─────────────────────────────

    /**
     * เช็คเบื้องต้นสำหรับซ่อน/แสดงปุ่ม — ด่านจริงอยู่ที่ service
     *
     * รายการเติม/ปรับยอด/คืนเครดิตที่ถูกย้อนแล้ว สถานะยังเป็น success (ไม่ได้เปลี่ยนเป็น refunded
     * เหมือนเติมเงิน/หักค่าบริการ) → ดูจากสมุดตรวจสอบ · รายการหักที่คืนไปบางส่วนแล้วก็ย้อนไม่ได้
     * ตารางโหลด admin_rollbacks_exists / refunds_exists มาให้แล้ว (withExists) ไม่ต้อง query ทีละแถว
     */
    public static function canRollback(WalletTransaction $tx): bool
    {
        $action = data_get($tx->meta, 'admin_action');

        $basic = $tx->status === 'success'
            && $tx->balance_after !== null
            && in_array($tx->type, ['topup', 'debit', 'refund', 'adjustment'], true)
            && $tx->reference_type !== 'wallet_transaction'
            && ! in_array($action, ['rollback', 'transfer_out', 'transfer_in'], true)
            && bccomp((string) $tx->amount, '0', 2) !== 0;
        if (! $basic) {
            return false;
        }

        $rolledBack = $tx->getAttribute('admin_rollbacks_exists') ?? $tx->adminRollbacks()->exists();
        if ($rolledBack) {
            return false;
        }
        if ($tx->type === 'debit') {
            return ! ($tx->getAttribute('refunds_exists') ?? $tx->refunds()->exists());
        }

        return true;
    }

    public static function rollbackFill(WalletTransaction $tx): array
    {
        return [
            'idempotency_key'  => (string) Str::uuid(),
            'expected_balance' => number_format((float) ($tx->wallet?->balance ?? 0), 2, '.', ''),
        ];
    }

    public static function rollbackSchema(): array
    {
        return [
            Forms\Components\Placeholder::make('confirm_summary')
                ->hiddenLabel()
                ->content(function (Get $get, ?WalletTransaction $record) {
                    if (! $record) {
                        return '—';
                    }
                    $before = (string) ($get('expected_balance') ?? '0');
                    $amount = ltrim(number_format((float) $record->amount, 2, '.', ''), '-');
                    $wasCredit = (float) $record->amount > 0;
                    $after  = $wasCredit ? bcsub($before, $amount, 2) : bcadd($before, $amount, 2);

                    return self::summary([
                        'รายการเดิม' => '#' . $record->id . ' ' . $record->typeLabel() . ' ' . ($wasCredit ? '+' : '−') . '฿' . self::fmt($amount),
                        'คำอธิบาย'  => (string) $record->description,
                        'การย้อน'   => $wasCredit ? 'หักคืน ฿' . self::fmt($amount) : 'คืนให้ ฿' . self::fmt($amount),
                    ], [[$record->user ? self::userLabel($record->user) : 'ยอดวอลเลต', $before, $after]],
                        'ย้อนได้ครั้งเดียว และย้อนกลับซ้ำไม่ได้');
                }),
            self::reasonField(),
            Forms\Components\Hidden::make('idempotency_key'),
            Forms\Components\Hidden::make('expected_balance'),
        ];
    }

    public static function handleRollback(WalletTransaction $tx, array $data): void
    {
        self::attempt(function () use ($tx, $data) {
            $res = app(AdminWalletService::class)->rollback(
                $tx,
                (string) ($data['reason'] ?? ''),
                auth()->user(),
                $data['idempotency_key'] ?? null,
                $data['expected_balance'] ?? null,
            );

            return [$res, 'ย้อนรายการ #' . $tx->id . ' สำเร็จ — ยอดคงเหลือ ฿' . self::fmt((string) $res->balance_after)];
        });
    }

    // ───────────────────────── คืนเงินของรายการหัก ─────────────────────────

    public static function refundDebitFill(WalletTransaction $tx): array
    {
        $remaining = bcsub(
            ltrim(number_format((float) $tx->amount, 2, '.', ''), '-'),
            app(AdminWalletService::class)->refundedSoFar($tx),
            2,
        );

        return [
            'amount'           => $remaining,
            'remaining'        => $remaining,
            'idempotency_key'  => (string) Str::uuid(),
            'expected_balance' => number_format((float) ($tx->wallet?->balance ?? 0), 2, '.', ''),
        ];
    }

    /** @return array<Step> */
    public static function refundDebitSteps(): array
    {
        return [
            Step::make('รายการ')
                ->icon('heroicon-o-arrow-uturn-left')
                ->schema([
                    Forms\Components\Placeholder::make('debit_info')
                        ->label('รายการหักที่จะคืน')
                        ->content(fn (?WalletTransaction $record, Get $get) => $record
                            ? '#' . $record->id . ' ' . $record->description . ' — หัก ฿' . self::fmt(ltrim((string) $record->amount, '-'))
                                . ' · คืนได้อีกไม่เกิน ฿' . self::fmt((string) $get('remaining'))
                            : '—'),
                    self::amountField()->maxValue(fn (Get $get) => (float) $get('remaining')),
                    self::reasonField(),
                    Forms\Components\Hidden::make('remaining'),
                    Forms\Components\Hidden::make('idempotency_key'),
                    Forms\Components\Hidden::make('expected_balance'),
                ]),
            Step::make('ยืนยัน')
                ->icon('heroicon-o-shield-check')
                ->schema([
                    Forms\Components\Placeholder::make('confirm_summary')
                        ->hiddenLabel()
                        ->content(function (Get $get, ?WalletTransaction $record) {
                            $before = (string) ($get('expected_balance') ?? '0');
                            $amount = self::num($get('amount'));

                            return self::summary([
                                'คืนเงินของรายการ' => $record ? '#' . $record->id . ' ' . $record->description : '—',
                                'จำนวน'          => '+฿' . self::fmt($amount),
                                'เหตุผล'          => (string) $get('reason'),
                            ], [[$record?->user ? self::userLabel($record->user) : 'ยอดวอลเลต', $before, bcadd($before, $amount, 2)]]);
                        }),
                ]),
        ];
    }

    public static function handleRefundDebit(WalletTransaction $tx, array $data): void
    {
        self::attempt(function () use ($tx, $data) {
            $res = app(AdminWalletService::class)->refundDebit(
                $tx,
                (string) ($data['amount'] ?? ''),
                (string) ($data['reason'] ?? ''),
                auth()->user(),
                $data['idempotency_key'] ?? null,
                $data['expected_balance'] ?? null,
            );

            return [$res, 'คืนเงิน ฿' . self::fmt((string) $res->amount) . ' สำเร็จ — ยอดคงเหลือ ฿' . self::fmt((string) $res->balance_after)];
        });
    }

    // ───────────────────────────── ส่วนกลาง ─────────────────────────────

    /** @param  callable(): array{0: WalletTransaction, 1: string}  $work */
    private static function attempt(callable $work): void
    {
        try {
            [$tx, $message] = $work();
            if ($tx->wasRecentlyCreated) {
                Notification::make()->title($message)->success()->send();
            } else {
                Notification::make()
                    ->title('รายการนี้ทำไปแล้ว (กดยืนยันซ้ำ) — ระบบไม่ได้ทำซ้ำ')
                    ->info()->send();
            }
        } catch (WalletAdminException $e) {
            Notification::make()->title('ยังไม่ได้ทำรายการ')->body($e->getMessage())->danger()->persistent()->send();
        } catch (Throwable $e) {
            Log::error('admin wallet action failed', ['error' => $e->getMessage(), 'admin' => auth()->id()]);
            Notification::make()->title('ทำรายการไม่สำเร็จ')->body('ระบบขัดข้อง ยอดเงินไม่ถูกเปลี่ยน — ลองใหม่อีกครั้ง หรือแจ้งผู้ดูแลระบบ')->danger()->persistent()->send();
        }
    }

    /**
     * ช่องผู้ใช้: ถ้าผูกกับ record อยู่แล้ว = ค่าซ่อน · ถ้าให้เลือก = Select ค้นหาชื่อ/อีเมล/เบอร์
     * เลือกแล้วจะเก็บยอดตอนนั้นลงช่อง $balanceField (ใช้เป็น expected_balance / แสดงในหน้ายืนยัน)
     */
    private static function userField(string $name, string $label, bool $pick, string $balanceField, ?string $excludeField = null): array
    {
        if (! $pick) {
            return [Forms\Components\Hidden::make($name)];
        }

        return [
            Forms\Components\Select::make($name)
                ->label($label)
                ->required()
                ->searchable()
                ->live()
                ->getSearchResultsUsing(function (string $search, Get $get) use ($excludeField) {
                    return User::query()
                        ->whereKeyNot(auth()->id())
                        ->when($excludeField && $get($excludeField), fn ($q) => $q->whereKeyNot($get($excludeField)))
                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"))
                        ->with('wallet')->limit(20)->get()
                        ->mapWithKeys(fn (User $u) => [$u->id => self::userLabel($u) . ' · ฿' . self::fmt((string) ($u->wallet?->balance ?? 0))])
                        ->all();
                })
                ->getOptionLabelUsing(fn ($value) => ($u = self::findUser($value)) ? self::userLabel($u) : null)
                ->afterStateUpdated(fn ($state, Set $set) => $set($balanceField, ($u = self::findUser($state)) ? self::balanceOf($u) : null))
                ->helperText($excludeField ? null : 'แอดมินจัดการวอลเลตของตัวเองไม่ได้'),
        ];
    }

    private static function amountField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('amount')
            ->label('จำนวนเงิน (฿)')
            ->numeric()
            ->inputMode('decimal')
            ->step('0.01')
            ->minValue(0.01)
            ->maxValue((float) AdminWalletService::MAX_AMOUNT)
            ->prefix('฿')
            ->required();
    }

    private static function reasonField(): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make('reason')
            ->label('เหตุผล (บังคับ — ลูกค้าเห็นในประวัติวอลเลต)')
            ->required()
            ->minLength(3)
            ->maxLength(500)
            ->rows(2);
    }

    /**
     * กล่องสรุปในหน้ายืนยัน — แถวข้อมูล + แถบ "ยอดก่อน → ยอดหลัง" ของแต่ละวอลเลต
     *
     * @param  array<string, string>  $facts
     * @param  array<array{0: string, 1: string, 2: string}>  $balances  [ชื่อ, ก่อน, หลัง]
     */
    private static function summary(array $facts, array $balances, ?string $note = null): HtmlString
    {
        // ⚠️ ใช้เฉพาะคลาสที่มีใน CSS สำเร็จรูปของ Filament (แผงนี้ไม่มี custom theme ที่คอมไพล์ Tailwind เอง)
        // สีสถานะใช้แบบเดียวกับ badge ของ Filament: text-custom-* + ตัวแปร --c-* ชี้ไปสี danger/success/warning
        // → ได้ทั้งโหมดสว่างและมืด
        $rows = '';
        foreach ($facts as $k => $v) {
            $rows .= '<div class="flex flex-col gap-1 sm:flex-row sm:gap-3">'
                . '<dt class="shrink-0 text-gray-500 dark:text-gray-400 w-32">' . e($k) . '</dt>'
                . '<dd class="min-w-0 font-medium text-gray-950 break-words dark:text-white">' . e($v !== '' ? $v : '—') . '</dd></div>';
        }

        $bars = '';
        foreach ($balances as [$who, $before, $after]) {
            $neg  = bccomp($after, '0', 2) < 0;
            $tone = $neg ? 'danger' : (bccomp($after, $before, 2) >= 0 ? 'success' : 'warning');
            $card = $neg
                ? 'bg-custom-50 ring-custom-600/10 dark:bg-custom-400/10 dark:ring-custom-400/30'
                : 'bg-gray-50 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10';
            $bars .= '<div class="flex flex-col gap-1 rounded-xl p-3 ring-1 ' . $card . '" style="' . self::tone('danger') . '">'
                . '<div class="text-xs text-gray-500 dark:text-gray-400">' . e($who) . '</div>'
                . '<div class="flex flex-wrap items-center gap-2 text-lg font-semibold">'
                . '<span class="text-gray-700 dark:text-gray-200">฿' . e(self::fmt($before)) . '</span>'
                . '<span class="text-gray-400">→</span>'
                . '<span class="text-custom-600 dark:text-custom-400" style="' . self::tone($tone) . '">' . ($neg ? '−' : '') . '฿' . e(self::fmt(ltrim($after, '-'))) . '</span>'
                . '</div>'
                . ($neg ? '<div class="text-sm text-custom-600 dark:text-custom-400">ยอดไม่พอ — ระบบไม่ยอมให้ติดลบ รายการนี้จะถูกปฏิเสธ</div>' : '')
                . '</div>';
        }

        return new HtmlString(
            '<div class="space-y-4 text-sm">'
            . '<p class="font-medium text-custom-600 dark:text-custom-400" style="' . self::tone('warning') . '">ยอดก่อน → หลัง: ตรวจให้ถูกต้องแล้วกดยืนยัน' . ($note ? ' · ' . e($note) : '') . '</p>'
            . '<dl class="space-y-2">' . $rows . '</dl>'
            . '<div class="grid gap-2 md:grid-cols-2">' . $bars . '</div>'
            . '</div>'
        );
    }

    /** ตัวแปรสีของ Filament (--c-50/400/600) สำหรับคลาส *-custom-* */
    private static function tone(string $color): string
    {
        return "--c-50:var(--{$color}-50);--c-400:var(--{$color}-400);--c-600:var(--{$color}-600);";
    }

    private static function findUser(mixed $id): ?User
    {
        return $id ? User::with('wallet')->find($id) : null;
    }

    public static function balanceOf(User $user): string
    {
        $user->loadMissing('wallet');

        return number_format((float) ($user->wallet?->balance ?? 0), 2, '.', '');
    }

    private static function userLabel(User $user): string
    {
        return trim(($user->name ?: 'สมาชิก #' . $user->id) . ($user->email ? ' (' . $user->email . ')' : ''));
    }

    /** ตัวเลขจากช่องกรอก → สตริง 2 ตำแหน่ง (ใช้แสดงผลเท่านั้น ไม่ใช่ตัวตัดสิน) */
    private static function num(mixed $v): string
    {
        $v = is_string($v) ? str_replace(',', '', trim($v)) : $v;

        return is_numeric($v) && is_finite((float) $v) ? number_format(round(abs((float) $v), 2), 2, '.', '') : '0.00';
    }

    private static function fmt(string $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
