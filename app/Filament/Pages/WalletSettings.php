<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class WalletSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'ตั้งค่าวอลเลต/ราคา';
    protected static ?string $title           = 'ตั้งค่าวอลเลตและราคาบริการ';
    protected static ?string $navigationGroup = 'การเงิน';
    protected static ?int    $navigationSort  = 9;
    protected static string  $view            = 'filament.pages.wallet-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $cfg = config('pricing');
        $this->form->fill([
            'pricing_tarot_three'  => Setting::get('pricing_tarot_three',  $cfg['tarot_three']),
            'pricing_tarot_celtic' => Setting::get('pricing_tarot_celtic', $cfg['tarot_celtic']),
            'pricing_numerology'   => Setting::get('pricing_numerology',   $cfg['numerology']),
            'pricing_palmistry'    => Setting::get('pricing_palmistry',    $cfg['palmistry']),
            'pricing_auspicious'   => Setting::get('pricing_auspicious',   $cfg['auspicious']),
            'pricing_chat_message' => Setting::get('pricing_chat_message', $cfg['chat_message']),
            'promptpay_id'         => Setting::get('promptpay_id',         $cfg['promptpay_id']),
            'promptpay_name'       => Setting::get('promptpay_name',       $cfg['promptpay_name']),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('ราคาต่อบริการ (THB)')
                ->description('แอดมินตั้งราคาที่นี่ — มีผลทันทีกับผู้ใช้ทุกคน. ตั้ง 0 เพื่อปิดการคิดเงิน (ใช้ฟรี)')
                ->schema([
                    TextInput::make('pricing_tarot_three')->label('ไพ่ 3 ใบ')->prefix('฿')->numeric()->minValue(0)->maxValue(100000)->default(19),
                    TextInput::make('pricing_tarot_celtic')->label('Celtic Cross 10 ใบ')->prefix('฿')->numeric()->minValue(0)->maxValue(100000)->default(99),
                    TextInput::make('pricing_numerology')->label('เลขศาสตร์')->prefix('฿')->numeric()->minValue(0)->maxValue(100000)->default(9),
                    TextInput::make('pricing_palmistry')->label('ลายมือ')->prefix('฿')->numeric()->minValue(0)->maxValue(100000)->default(29),
                    TextInput::make('pricing_auspicious')->label('ฤกษ์ยาม')->prefix('฿')->numeric()->minValue(0)->maxValue(100000)->default(19),
                    TextInput::make('pricing_chat_message')->label('แชท (ต่อข้อความ)')->prefix('฿')->numeric()->minValue(0)->maxValue(100000)->default(2),
                ])->columns(3),

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

    public function save(): void
    {
        $data = $this->form->getState();
        $groups = [
            'pricing_tarot_three'  => 'pricing',
            'pricing_tarot_celtic' => 'pricing',
            'pricing_numerology'   => 'pricing',
            'pricing_palmistry'    => 'pricing',
            'pricing_auspicious'   => 'pricing',
            'pricing_chat_message' => 'pricing',
            'promptpay_id'         => 'pricing',
            'promptpay_name'       => 'pricing',
        ];
        foreach ($data as $key => $value) {
            Setting::put($key, (string) $value, $groups[$key] ?? 'pricing');
        }
        Notification::make()->title('บันทึกการตั้งค่าวอลเลตเรียบร้อย')->success()->send();
    }
}
