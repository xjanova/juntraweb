<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use App\Support\Alerts\AlertCard;
use App\Support\Telegram\TelegramBot;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * แจ้งเตือนแอดมินผ่าน Telegram — ตั้งบอท เลือกแชท เลือกหมวด ทดสอบ และดูประวัติที่ส่งไป
 *
 * เจ้าของขอ (2026-09-15): "ทำระบบแจ้งเตือนผ่าน telegram เหมือน netwix … เพราะไลน์มันเสีย
 * ค่าใช้จ่าย" — Telegram Bot API ส่งฟรีไม่จำกัด
 *
 * แอดมินเท่านั้น (ไม่ใช่ editor): หน้านี้ถือ Bot Token และคุมว่าเรื่องเงินจะไปถึงใคร
 */
class TelegramAlerts extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'แจ้งเตือน Telegram';

    protected static ?string $title = 'แจ้งเตือนแอดมินผ่าน Telegram';

    protected static ?string $navigationGroup = 'ตั้งค่า';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.telegram-alerts';

    public ?array $data = [];

    /** @var array<int,array{id:string,type:string,title:string}> chats found by "ค้นหาแชท" */
    public array $chats = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    public function mount(): void
    {
        $this->form->fill([
            'enabled' => Setting::get('telegram_alerts_enabled', '0') === '1',
            'bot_token' => '',
            'chat_id' => TelegramBot::chat(),
            'protect_content' => Setting::get('telegram_protect_content', '0') === '1',
            'categories' => AdminAlerts::categories(),
        ]);
    }

    public function form(Form $form): Form
    {
        $options = collect(AdminAlerts::CATEGORIES)->mapWithKeys(fn ($def, $key) => [$key => $def[0]])->all();
        $descriptions = collect(AdminAlerts::CATEGORIES)->mapWithKeys(fn ($def, $key) => [$key => $def[1]])->all();
        $bot = TelegramBot::username();

        return $form->schema([
            Section::make('บอท Telegram')
                ->description('1) คุยกับ @BotFather พิมพ์ /newbot เพื่อสร้างบอทแล้วคัดลอก Token มาใส่  2) เปิดแชทกับบอทแล้วกด Start (หรือเพิ่มบอทเข้ากลุ่มแอดมิน)  3) กด "ค้นหาแชท" ด้านบนแล้วเลือกแชท  4) กด "ทดสอบส่ง"')
                ->schema([
                    Toggle::make('enabled')
                        ->label('เปิดการแจ้งเตือน')
                        ->helperText('ปิด = ไม่ส่งอะไรเลย (ตั้งค่าเก็บไว้ครบ)')
                        ->onColor('success'),
                    TextInput::make('bot_token')
                        ->label('Bot Token')
                        ->password()->revealable()
                        ->placeholder(TelegramBot::token() !== '' ? 'บันทึกไว้แล้ว' . ($bot !== '' ? ' (@' . $bot . ')' : '') . ' — เว้นว่างเพื่อใช้ของเดิม' : '123456789:AA...')
                        ->maxLength(120)
                        ->rule('nullable')
                        ->rule('regex:/^\d{5,}:[A-Za-z0-9_-]{20,}$/')
                        ->validationMessages(['regex' => 'รูปแบบ Token ไม่ถูกต้อง (ตัวเลข:ตัวอักษร) — คัดลอกจาก @BotFather ทั้งบรรทัด']),
                    TextInput::make('chat_id')
                        ->label('Chat ID ปลายทาง')
                        ->helperText('ตัวเลขของแชทส่วนตัว, -100… ของกลุ่ม หรือ @ชื่อช่อง — กด "ค้นหาแชท" จะเติมให้เอง')
                        ->maxLength(64)
                        ->rule('nullable')
                        ->rule('regex:/^(-?\d{3,20}|@[A-Za-z0-9_]{5,64})$/')
                        ->validationMessages(['regex' => 'Chat ID ต้องเป็นตัวเลข (กลุ่มขึ้นต้น -100) หรือ @ชื่อช่อง']),
                    Toggle::make('protect_content')
                        ->label('ห้ามส่งต่อ/บันทึกข้อความเรื่องเงินและสมาชิก')
                        ->helperText('Telegram จะบล็อกการ forward/บันทึกรูปสลิปและข้อมูลลูกค้าจากแชทนี้'),
                ])->columns(2),

            Section::make('หมวดที่ต้องการรับ')
                ->description('ปิดหมวดไหน = เรื่องหมวดนั้นไม่ถูกส่ง (ไม่นับเป็นการแจ้งซ้ำ เปิดกลับมาแล้วได้ทันที) · หมวดดูดวงส่งแบบไม่มีเสียง')
                ->schema([
                    CheckboxList::make('categories')
                        ->label('')
                        ->options($options)
                        ->descriptions($descriptions)
                        ->columns(2),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $token = trim((string) ($data['bot_token'] ?? ''));
        if ($token !== '') {
            $who = TelegramBot::identify($token);
            if (! $who['ok'] && $who['reachable']) {
                Notification::make()->title('Token นี้ใช้ไม่ได้')->body($who['error'])->danger()->persistent()->send();

                return;
            }
            Setting::put('telegram_bot_token', $token, 'telegram', true);
            Setting::put('telegram_bot_username', (string) ($who['username'] ?? ''), 'telegram');
        }

        Setting::put('telegram_chat_id', trim((string) ($data['chat_id'] ?? '')), 'telegram');
        Setting::put('telegram_protect_content', ! empty($data['protect_content']) ? '1' : '0', 'telegram');
        AdminAlerts::saveCategories((array) ($data['categories'] ?? []));

        $wantOn = ! empty($data['enabled']);
        if ($wantOn && ! TelegramBot::configured()) {
            Setting::put('telegram_alerts_enabled', '0', 'telegram');
            Notification::make()->title('บันทึกแล้ว แต่ยังเปิดแจ้งเตือนไม่ได้')->body('ต้องมีทั้ง Bot Token และ Chat ID ก่อน')->warning()->send();
        } else {
            Setting::put('telegram_alerts_enabled', $wantOn ? '1' : '0', 'telegram');
            Notification::make()->title('บันทึกการตั้งค่าแจ้งเตือนแล้ว')->success()->send();
        }

        $this->form->fill(array_merge($data, ['bot_token' => '', 'enabled' => TelegramBot::enabled()]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('discover')
                ->label('ค้นหาแชท')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->action(function () {
                    $found = TelegramBot::discoverChats();
                    $this->chats = $found['chats'];
                    if ($found['error']) {
                        Notification::make()->title($found['error'])->warning()->persistent()->send();
                    } else {
                        Notification::make()->title('พบ ' . count($found['chats']) . ' แชท — กดเลือกด้านล่าง')->success()->send();
                    }
                }),
            Action::make('test')
                ->label('ทดสอบส่ง')
                ->icon('heroicon-o-paper-airplane')
                ->action(function () {
                    [$ok, $reason] = AdminAlerts::test();
                    $ok
                        ? Notification::make()->title('ส่งการ์ดทดสอบแล้ว — ดูในแชท Telegram')->success()->send()
                        : Notification::make()->title('ส่งไม่สำเร็จ')->body($reason)->danger()->persistent()->send();
                }),
            Action::make('forget')
                ->label('ลบ Token')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('ลบ Bot Token และปิดการแจ้งเตือน — ต้องใส่ Token ใหม่ถึงจะส่งได้อีก')
                ->visible(fn () => TelegramBot::token() !== '')
                ->action(function () {
                    Setting::put('telegram_bot_token', null, 'telegram', true);
                    Setting::put('telegram_bot_username', '', 'telegram');
                    Setting::put('telegram_alerts_enabled', '0', 'telegram');
                    $this->form->fill(array_merge($this->data ?? [], ['enabled' => false, 'bot_token' => '']));
                    Notification::make()->title('ลบ Token แล้ว')->success()->send();
                }),
        ];
    }

    /** Pick a chat found by "ค้นหาแชท" — fills the field; the admin still presses บันทึก. */
    public function useChat(string $id): void
    {
        if (! collect($this->chats)->contains(fn ($c) => (string) $c['id'] === $id)) {
            return;
        }
        $this->data['chat_id'] = $id;
        Notification::make()->title('เลือกแชทแล้ว — กด "บันทึก" เพื่อใช้แชทนี้')->success()->send();
    }

    /** A sample card so the owner sees what arrives before switching anything on. */
    public function getPreviewProperty(): ?string
    {
        if (! AlertCard::available()) {
            return null;
        }
        $png = AlertCard::png(new Alert(
            key: 'preview',
            level: Alert::MONEY,
            title: 'เงินเข้า ฿100.37 — เติมวอลเลตแล้ว',
            body: 'สลิปผ่าน SlipOK + ไม่ซ้ำทั้งเว็บและแม่หมอ + เข้าบัญชีร้าน — เครดิตเข้าวอลเลตแล้ว',
            facts: ['ลูกค้า' => 'คุณสมใจ', 'ช่องทาง' => 'ตรวจสลิปอัตโนมัติ', 'คงเหลือ' => '฿240.37', 'อ้างอิง' => 'TUP-8K2M4Q1Z'],
            url: url('/admin'),
            category: 'money',
        ));

        return $png ? 'data:image/png;base64,' . base64_encode($png) : null;
    }

    public function getRecentProperty(): array
    {
        return AdminAlerts::recent(30);
    }

    public function getStatusProperty(): array
    {
        return [
            'configured' => TelegramBot::configured(),
            'enabled' => TelegramBot::enabled(),
            'bot' => TelegramBot::username(),
            'card' => AlertCard::available(),
        ];
    }
}
