<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dedicated admin page for the Thaiprompt SSO connection used by the
 * fortune-telling membership system. Lives under "ดูดวง" in the sidebar so
 * the operator can find it next to the tarot/horoscope content tools.
 */
class MembershipIntegration extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';
    protected static ?string $navigationLabel = 'เชื่อมต่อ Thaiprompt';
    protected static ?string $title = 'เชื่อมต่อระบบสมาชิก Thaiprompt';
    protected static ?string $navigationGroup = 'ดูดวง';
    protected static ?int $navigationSort = 90;
    protected static string $view = 'filament.pages.membership-integration';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'thaiprompt_enabled'   => Setting::get('thaiprompt_enabled', '0') === '1',
            'thaiprompt_base_url'  => Setting::get('thaiprompt_base_url'),
            'thaiprompt_client_id' => Setting::get('thaiprompt_client_id'),
            'thaiprompt_client_secret' => '', // never echo back the secret
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('OAuth2 — ปลายทางที่ระบบจะเรียก')
                ->description('juntra จะเรียก {Base URL}/oauth/authorize, /oauth/token, /api/user — ตรวจให้แน่ใจว่า Thaiprompt เปิด endpoint เหล่านี้แล้ว')
                ->schema([
                    Toggle::make('thaiprompt_enabled')
                        ->label('เปิดใช้งาน Thaiprompt SSO')
                        ->helperText('ปุ่ม "เข้าสู่ระบบด้วย Thaiprompt" จะโผล่ในหน้า /login เมื่อเปิด')
                        ->inline(false),
                    TextInput::make('thaiprompt_base_url')
                        ->label('Thaiprompt Base URL')
                        ->url()
                        ->placeholder('https://main.thaiprompt.online')
                        ->helperText('โดเมนหลักของ Thaiprompt ที่ทำหน้าที่เป็น OAuth provider'),
                    TextInput::make('thaiprompt_client_id')
                        ->label('Client ID')
                        ->helperText('ออกให้โดยผู้ดูแล Thaiprompt — ระบุว่า juntra เป็น client ตัวไหน'),
                    TextInput::make('thaiprompt_client_secret')
                        ->label('Client Secret (ลับ)')
                        ->password()->revealable()
                        ->helperText('เก็บแบบ encrypted ใน DB · เว้นว่างไว้ถ้าไม่ต้องการเปลี่ยน'),
                ])->columns(2),

            Section::make('Redirect URI ที่ต้องอนุญาตฝั่ง Thaiprompt')
                ->description('คัดลอกค่านี้ไปใส่ใน whitelist ของ OAuth client ฝั่ง Thaiprompt-Affiliate')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('redirect_uri')
                        ->label('Redirect URI')
                        ->content(fn () => route('thaiprompt.callback'))
                        ->extraAttributes(['style' => 'font-family:monospace;font-size:13px;background:rgba(255,255,255,.05);padding:10px 14px;border-radius:6px']),
                ]),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('บันทึก')
                ->action('save')
                ->color('primary'),

            \Filament\Actions\Action::make('test')
                ->label('ทดสอบเชื่อมต่อ')
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->action('testConnection'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::put('thaiprompt_enabled', !empty($data['thaiprompt_enabled']) ? '1' : '0', 'thaiprompt');
        Setting::put('thaiprompt_base_url', $data['thaiprompt_base_url'] ?? '', 'thaiprompt');
        Setting::put('thaiprompt_client_id', $data['thaiprompt_client_id'] ?? '', 'thaiprompt');

        // Only overwrite the secret if the operator typed something new.
        if (!empty($data['thaiprompt_client_secret'])) {
            Setting::put('thaiprompt_client_secret', $data['thaiprompt_client_secret'], 'thaiprompt', true);
        }

        Notification::make()->title('บันทึกการเชื่อมต่อแล้ว')->success()->send();
    }

    /**
     * Probe the Base URL — we don't have a credentialed test endpoint, so we
     * just verify that {base}/oauth/authorize responds (any 2xx/3xx is OK;
     * even a 4xx with a body that mentions "client" tells us OAuth is alive).
     *
     * Reads the URL from the FORM state first so the operator can probe a
     * value they just typed without needing to save it. Falls back to the
     * stored Setting if the form is empty (e.g. probing right after page load).
     */
    public function testConnection(): void
    {
        // Pull the form's current state — Filament keeps it on $this->data.
        // This also picks up unsaved edits, which is what the operator expects.
        $state = is_array($this->data ?? null) ? $this->data : [];
        $base  = $state['thaiprompt_base_url']  ?? Setting::get('thaiprompt_base_url');
        $cid   = $state['thaiprompt_client_id'] ?? Setting::get('thaiprompt_client_id') ?: 'probe';

        if (!$base) {
            Notification::make()->title('ยังไม่ได้กรอก Base URL')->danger()->send();
            return;
        }

        // Only allow http(s) — block file://, gopher://, dict:// etc. used by SSRF.
        if (!preg_match('#^https?://[^/]+#i', $base)) {
            Notification::make()
                ->title('Base URL ต้องขึ้นต้นด้วย http:// หรือ https://')
                ->body('โดยปกติ Thaiprompt ใช้ https — ลองพิมพ์ใหม่อีกครั้ง')
                ->danger()->send();
            return;
        }

        try {
            $resp = Http::timeout(8)->withoutRedirecting()
                ->get(rtrim($base, '/') . '/oauth/authorize', [
                    'client_id'     => $cid,
                    'response_type' => 'code',
                    'redirect_uri'  => route('thaiprompt.callback'),
                ]);
            $code = $resp->status();
            // 200/302 = endpoint exists. 404 = OAuth not implemented on Thaiprompt yet.
            if ($code === 404) {
                Notification::make()
                    ->title('Thaiprompt ยังไม่มี /oauth/authorize')
                    ->body("ปลายทาง $base/oauth/authorize ตอบ 404 — แอดมินฝั่ง Thaiprompt-Affiliate ต้องเพิ่ม OAuth provider ก่อน")
                    ->danger()->send();
            } elseif ($code >= 200 && $code < 400) {
                Notification::make()
                    ->title('Thaiprompt OAuth พร้อมใช้งาน')
                    ->body("$base ตอบ HTTP $code — สามารถใช้ Single Sign-On ได้")
                    ->success()->send();
            } else {
                Notification::make()
                    ->title("Thaiprompt ตอบ HTTP $code")
                    ->body('endpoint อยู่แต่ตอบสถานะที่ไม่คาดหวัง — ลองตรวจ client_id หรือสิทธิ์อีกครั้ง')
                    ->warning()->send();
            }
        } catch (\Throwable $e) {
            // Log the full message for the operator, show a clean line in the toast.
            Log::warning('thaiprompt.testConnection failed', [
                'base' => $base,
                'err'  => $e->getMessage(),
            ]);
            Notification::make()
                ->title('เชื่อมต่อ Thaiprompt ไม่ได้')
                ->body('ไม่สามารถเรียก endpoint ได้ — ดูรายละเอียดเพิ่มเติมที่ storage/logs/laravel.log')
                ->danger()->send();
        }
    }
}
