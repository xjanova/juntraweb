<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\FreeReadingPolicy;
use App\Support\TarotSpreads;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class WalletSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'ตั้งค่าวอลเลต/ราคา';

    protected static ?string $title = 'ตั้งค่าวอลเลตและราคาบริการ';

    protected static ?string $navigationGroup = 'การเงิน';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.wallet-settings';

    public ?array $data = [];

    /** Non-tarot billable services: feature key → [label, config default]. */
    private const OTHER_SERVICES = [
        'numerology' => ['เลขศาสตร์', 9],
        'palmistry' => ['ลายมือ', 29],
        'auspicious' => ['ฤกษ์ยาม', 19],
        'chat_message' => ['แชทกับแม่หมอ (ต่อข้อความ)', 2],
    ];

    public function mount(): void
    {
        $cfg = config('pricing');

        $fill = [
            // Master switch — off = every service free site-wide.
            'billing_enabled' => Setting::get('billing_enabled', '1') === '1',

            'promptpay_id' => Setting::get('promptpay_id', $cfg['promptpay_id']),
            'promptpay_name' => Setting::get('promptpay_name', $cfg['promptpay_name']),

            // เพดานข้อความฟรีต่อคนต่อวัน (0 = ไม่จำกัด) — เดิมต้องแก้ใน DB มือ
            'chat_daily_limit' => (int) Setting::get('chat_daily_limit', 0),

            // 🎁 ดูดวงฟรี 1 ใบ (ปลายทางปุ่ม "ดูดวงฟรี" จากบอท FB/LINE)
            'free_reading_enabled' => FreeReadingPolicy::enabled(),
            'free_reading_daily_cap' => FreeReadingPolicy::dailyCap(),
            'free_reading_max_chars' => FreeReadingPolicy::maxChars(),
            'free_reading_batch' => FreeReadingPolicy::batch(),
        ];

        // Tarot: one price + one charge-toggle per registered spread.
        foreach (TarotSpreads::keys() as $k) {
            $feature = "tarot_{$k}";
            $fill["pricing_{$feature}"] = Setting::get("pricing_{$feature}", $cfg[$feature] ?? 0);
            $fill["charge_{$feature}"] = Setting::get("pricing_{$feature}_enabled", '1') === '1';
        }

        // Other services.
        foreach (self::OTHER_SERVICES as $feature => [$label, $default]) {
            $fill["pricing_{$feature}"] = Setting::get("pricing_{$feature}", $cfg[$feature] ?? $default);
            $fill["charge_{$feature}"] = Setting::get("pricing_{$feature}_enabled", '1') === '1';
        }

        $this->form->fill($fill);
    }

    public function form(Form $form): Form
    {
        // Tarot rows: toggle + price for every spread in the registry.
        $tarotRows = collect(TarotSpreads::all())->map(fn ($meta, $k) => $this->chargeRow("tarot_{$k}", $meta['name_th'].' ('.count($meta['positions']).' ใบ)', (float) config("pricing.tarot_{$k}", 0))
        )->values()->all();

        $otherRows = collect(self::OTHER_SERVICES)->map(fn ($def, $feature) => $this->chargeRow($feature, $def[0], (float) config("pricing.$feature", $def[1]))
        )->values()->all();

        return $form->schema([
            Section::make('การเก็บค่าบริการ (สวิตช์หลัก)')
                ->description('ปิดสวิตช์นี้ = ทุกบริการฟรีทั้งเว็บทันที (ราคาที่ตั้งไว้ยังถูกเก็บ กลับมาเปิดได้ทุกเมื่อ) — เหมาะกับช่วงโปรโมชันหรือเปิดตัว')
                ->schema([
                    Toggle::make('billing_enabled')
                        ->label('เก็บค่าบริการจากผู้ใช้')
                        ->helperText('เปิด = คิดเงินตามราคาด้านล่าง · ปิด = ทุกบริการฟรี')
                        ->inline(false)
                        ->onColor('success')->offColor('danger'),
                ]),

            Section::make('ไพ่ยิปซี')
                ->description('เปิด/ปิดการเก็บเงินและตั้งราคาต่อการเปิดไพ่แต่ละรูปแบบ')
                ->schema($tarotRows),

            Section::make('บริการอื่น')
                ->description('เลขศาสตร์ · ลายมือ · ฤกษ์ยาม · แชท — สวิตช์แยกแต่ละบริการ')
                ->schema($otherRows),

            Section::make('เพดานคุยฟรีต่อวัน')
                ->description('ใช้เฉพาะตอนที่แชทตั้งเป็นฟรี (ปิดสวิตช์เก็บเงินของ "แชทกับแม่หมอ") — โหมดคิดเงินมีวอลเลตเป็นเบรกอยู่แล้ว')
                ->schema([
                    TextInput::make('chat_daily_limit')
                        ->label('จำนวนข้อความฟรีต่อคนต่อวัน')
                        ->helperText('0 = ไม่จำกัด (คุยฟรีเหมือนแชทกับบอทแม่หมอใน Facebook) · ใส่ตัวเลขเมื่อต้องการคุมต้นทุน AI — มีผลทั้งเว็บและแอพพร้อมกัน นับรวมกันทุกช่องทาง รีเซ็ตทุกเที่ยงคืน')
                        ->numeric()->minValue(0)->maxValue(1000)->default(0),
                ]),

            Section::make('🎁 ดูดวงฟรี 1 ใบ')
                ->description('ปลายทางของปุ่ม "ดูดวงฟรี" ที่บอทเฟซบุ๊ก/ไลน์ส่งลูกค้ามา — เปิดไพ่ 1 ใบ ทำนายสั้น ๆ ให้ฟรี แล้วค่อยชวนเปิดไพ่ชุดเต็ม')
                ->schema([
                    Toggle::make('free_reading_enabled')
                        ->label('เปิดรับดูดวงฟรี')
                        ->helperText('ปิด = หน้า /tarot/free จะบอกลูกค้าว่าปิดรอบชั่วคราว (ลิงก์ที่ส่งไปแล้วไม่พัง)')
                        ->inline(false)
                        ->onColor('success')->offColor('danger'),

                    TextInput::make('free_reading_daily_cap')
                        ->label('เพดานจำนวนครั้งต่อวัน (ทั้งระบบ)')
                        ->helperText('0 = ไม่จำกัด · ใส่ตัวเลขเพื่อคุมต้นทุน AI เวลาบอทส่งคนมาเยอะ — นับรวมทุกคน รีเซ็ตทุกเที่ยงคืน')
                        ->numeric()->minValue(0)->maxValue(100000)->default(0),

                    TextInput::make('free_reading_max_chars')
                        ->label('ความยาวคำทำนาย (ตัวอักษร)')
                        ->helperText('สั้น ๆ พอให้เห็นฝีมือแล้วอยากดูต่อ — แนะนำ 500 (ยิ่งยาวยิ่งเปลืองค่า AI และลดแรงจูงใจให้ซื้อ)')
                        ->numeric()
                        ->minValue(FreeReadingPolicy::MIN_CHARS)
                        ->maxValue(FreeReadingPolicy::MAX_CHARS)
                        ->default(500),

                    TextInput::make('free_reading_batch')
                        ->label('รอบการแจกสิทธิ์')
                        ->helperText('เปลี่ยนค่านี้ (เช่น v1 → v2) = แจกสิทธิ์ดูฟรีใหม่ให้ "ทุกคน" ทันที รวมคนที่เคยใช้ไปแล้ว — ไม่ต้องแตะข้อมูลลูกค้า')
                        ->maxLength(32)
                        ->default('v1'),
                ])->columns(2),

            Section::make('PromptPay (สำหรับเติมเงิน)')
                ->description('ข้อมูลที่จะแสดงในหน้าเติมเงินของผู้ใช้ — ใส่เบอร์ 10 หลัก หรือเลขบัตรประชาชน 13 หลัก (ตัวเลขล้วน)')
                ->schema([
                    TextInput::make('promptpay_id')->label('PromptPay ID/เบอร์')->placeholder('0812345678')
                        ->maxLength(20)
                        ->rule('nullable')
                        ->rule('regex:/^[0-9]{10}$|^[0-9]{13}$/')
                        ->validationMessages(['regex' => 'ต้องเป็นเบอร์ 10 หลัก หรือเลขบัตรประชาชน 13 หลัก (ตัวเลขล้วน)']),
                    TextInput::make('promptpay_name')->label('ชื่อบัญชี')->placeholder('นาย AB CD')->maxLength(120),
                ])->columns(2),
        ])->statePath('data');
    }

    /** One "[toggle: charge?] [price]" row for a billable feature. */
    private function chargeRow(string $feature, string $label, float $default): Grid
    {
        return Grid::make(12)->schema([
            Toggle::make("charge_{$feature}")
                ->label($label)
                ->helperText('เปิด = เก็บเงิน · ปิด = ฟรี')
                ->inline(false)
                ->onColor('success')
                ->columnSpan(['default' => 12, 'md' => 8]),
            TextInput::make("pricing_{$feature}")
                ->label('ราคา (฿)')
                ->prefix('฿')->numeric()->minValue(0)->maxValue(100000)
                ->default($default)
                ->columnSpan(['default' => 12, 'md' => 4]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            if ($key === 'billing_enabled') {
                Setting::put('billing_enabled', $value ? '1' : '0', 'pricing');
            } elseif (str_starts_with($key, 'charge_')) {
                // charge_<feature> → pricing_<feature>_enabled
                $feature = substr($key, strlen('charge_'));
                Setting::put("pricing_{$feature}_enabled", $value ? '1' : '0', 'pricing');
            } elseif ($key === 'chat_daily_limit') {
                // เก็บไว้กลุ่ม chat — ChatPolicy อ่านคีย์นี้ทั้งเว็บและ API มือถือ
                Setting::put('chat_daily_limit', (string) max(0, (int) $value), 'chat');
            } elseif ($key === 'free_reading_enabled') {
                Setting::put('free_reading_enabled', $value ? '1' : '0', 'free_reading');
            } elseif ($key === 'free_reading_daily_cap') {
                Setting::put('free_reading_daily_cap', (string) max(0, (int) $value), 'free_reading');
            } elseif ($key === 'free_reading_max_chars') {
                // clamp ให้อยู่ในช่วงที่ฝั่ง Thaiprompt ยอมรับ ไม่งั้น validate ตกแล้วได้ 422
                $chars = min(FreeReadingPolicy::MAX_CHARS, max(FreeReadingPolicy::MIN_CHARS, (int) $value));
                Setting::put('free_reading_max_chars', (string) $chars, 'free_reading');
            } elseif ($key === 'free_reading_batch') {
                $batch = trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 32) : 'v1';
                Setting::put('free_reading_batch', $batch, 'free_reading');
            } else {
                // pricing_* and promptpay_* stored as-is.
                Setting::put($key, (string) $value, 'pricing');
            }
        }

        Notification::make()->title('บันทึกการตั้งค่าวอลเลตเรียบร้อย')->success()->send();
    }
}
