<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SiteSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'ตั้งค่าเว็บไซต์';
    protected static ?string $title = 'ตั้งค่าเว็บไซต์';
    protected static ?string $navigationGroup = 'ระบบ';
    protected static ?int $navigationSort = 99;
    protected static string $view = 'filament.pages.site-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'site_name' => Setting::get('site_name'),
            'site_tagline' => Setting::get('site_tagline'),
            'hero_lede' => Setting::get('hero_lede'),
            'stat_readings' => Setting::get('stat_readings'),
            'stat_accuracy' => Setting::get('stat_accuracy'),
            'stat_cards' => Setting::get('stat_cards'),
            'contact_line' => Setting::get('contact_line'),
            'contact_facebook' => Setting::get('contact_facebook'),
            'contact_email' => Setting::get('contact_email'),
            'ai_provider' => Setting::get('ai_provider'),
            'ai_model' => Setting::get('ai_model'),
            'ai_api_key' => Setting::get('ai_api_key'),
            'ai_system_prompt' => Setting::get('ai_system_prompt'),
            'tarot_card_back_path' => Setting::get('tarot_card_back_path'),
            'play_store_url' => Setting::get('play_store_url'),
            'app_store_url' => Setting::get('app_store_url'),
            'app_version' => Setting::get('app_version'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('ข้อมูลเว็บไซต์')->schema([
                    TextInput::make('site_name')->label('ชื่อเว็บ'),
                    TextInput::make('site_tagline')->label('คำขวัญ'),
                    Textarea::make('hero_lede')->label('คำโปรยหน้าแรก')->rows(3),
                ])->columns(2),

                Section::make('สถิติหน้าแรก')->schema([
                    TextInput::make('stat_readings')->label('ครั้งที่ทำนาย'),
                    TextInput::make('stat_accuracy')->label('ความแม่นยำ'),
                    TextInput::make('stat_cards')->label('จำนวนไพ่'),
                ])->columns(3),

                Section::make('ติดต่อ')->schema([
                    TextInput::make('contact_line')->label('LINE ID'),
                    TextInput::make('contact_facebook')->label('Facebook URL'),
                    TextInput::make('contact_email')->label('Email')->email(),
                ])->columns(3),

                Section::make('แอปพลิเคชันมือถือ')
                    ->description('หน้า /download บนเว็บ — ลิงก์สโตร์ ปุ่มโหลด และ QR ใช้ค่าจากตรงนี้')
                    ->schema([
                        TextInput::make('play_store_url')->label('Google Play URL')->url()
                            ->placeholder('https://play.google.com/store/apps/details?id=com.xjanova.juntra')
                            ->helperText('เว้นว่าง = ใช้ลิงก์ default ของแอพ Juntra (com.xjanova.juntra)'),
                        TextInput::make('app_store_url')->label('App Store URL (iOS)')->url()
                            ->helperText('เว้นว่าง = ปุ่ม App Store ขึ้น "เร็ว ๆ นี้"'),
                        TextInput::make('app_version')->label('เวอร์ชันแอพที่โชว์บนหน้า')->placeholder('0.3.2'),
                        Placeholder::make('app_download_clicks')->label('ยอดคลิกปุ่มดาวน์โหลดสะสม')
                            ->content(fn () => number_format((int) Setting::get('app_download_clicks', 0)) . ' ครั้ง'),
                    ])->columns(2),

                Section::make('AI Configuration')->description('ตั้งค่า Gemini/OpenAI สำหรับระบบ AI ทำนายดวง')->schema([
                    TextInput::make('ai_provider')->label('Provider')->default('gemini'),
                    TextInput::make('ai_model')->label('Model')->default('gemini-2.0-flash-exp'),
                    TextInput::make('ai_api_key')->label('API Key')->password()->revealable()
                        ->helperText('Google AI Studio API key — เก็บเป็น encrypted ในฐานข้อมูล'),
                    Textarea::make('ai_system_prompt')->label('System Prompt')->rows(4),
                ])->columns(2),

                Section::make('ระบบไพ่ยิปซี')
                    ->description('หลังไพ่ที่ user เห็นตอนเลือก — อัปโหลดเองที่นี่ หรือใช้ปุ่ม "Import หลังไพ่ จาก Thaiprompt" ในหน้า /admin/tarot-cards')
                    ->schema([
                        FileUpload::make('tarot_card_back_path')
                            ->label('หลังไพ่')
                            ->image()
                            ->disk('public')
                            ->directory('tarot/card-backs')
                            ->visibility('public')
                            ->imagePreviewHeight('220')
                            ->maxSize(4096)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('แนะนำสัดส่วน 5:9 (ไพ่มาตรฐาน). หากไม่มีจะใช้ลาย SVG default'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $encryptedKeys = ['ai_api_key'];
        $groups = [
            'site_name' => 'general', 'site_tagline' => 'general',
            'hero_lede' => 'homepage',
            'stat_readings' => 'homepage', 'stat_accuracy' => 'homepage', 'stat_cards' => 'homepage',
            'contact_line' => 'contact', 'contact_facebook' => 'contact', 'contact_email' => 'contact',
            'ai_provider' => 'ai', 'ai_model' => 'ai', 'ai_api_key' => 'ai', 'ai_system_prompt' => 'ai',
            'tarot_card_back_path' => 'tarot',
            'play_store_url' => 'app', 'app_store_url' => 'app', 'app_version' => 'app',
        ];

        foreach ($data as $key => $value) {
            Setting::put($key, $value, $groups[$key] ?? 'general', in_array($key, $encryptedKeys, true));
        }

        Notification::make()->title('บันทึกการตั้งค่าแล้ว')->success()->send();
    }
}
