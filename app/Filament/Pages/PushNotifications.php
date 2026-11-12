<?php

namespace App\Filament\Pages;

use App\Models\PushDelivery;
use App\Models\Setting;
use App\Services\Push\FcmSender;
use App\Services\Push\PushNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * 🔔 แจ้งเตือนแอป (Web Push) ถึงสมาชิก — เปิด/ปิดรายหมวด + ตั้งเวลาเงียบ + ทดสอบส่งถึงเครื่องตัวเอง
 *
 * เจ้าของสั่ง (2026-11-11) "ทำให้แจ้งเตือนได้เมื่อติดตั้งแล้ว เช่น แจ้งเตือนยอดค้าง หรือตามมาดูดวงได้"
 * กุญแจ VAPID อยู่ใน .env (php artisan webpush:vapid) — หน้านี้ไม่แสดง/ไม่แก้กุญแจลับ
 * แอดมินเท่านั้น: ตัดสินว่าสมาชิกทุกคนจะถูกเตือนเรื่องอะไร
 */
class PushNotifications extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationLabel = 'แจ้งเตือนแอป (มือถือ)';

    protected static ?string $title = 'แจ้งเตือนแอปถึงสมาชิก (Web Push)';

    protected static ?string $navigationGroup = 'ตั้งค่า';

    protected static ?int $navigationSort = 21;

    protected static string $view = 'filament.pages.push-notifications';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    public function mount(): void
    {
        [$start, $end] = PushNotifier::quietHours();
        $fill = ['quiet_start' => $start, 'quiet_end' => $end];
        foreach (array_keys(PushNotifier::TYPES) as $type) {
            $fill["type_$type"] = PushNotifier::typeEnabled($type);
        }
        $this->form->fill($fill);
    }

    public function form(Form $form): Form
    {
        $toggles = [];
        foreach (PushNotifier::TYPES as $type => [$label, $desc]) {
            $toggles[] = Toggle::make("type_$type")->label($label)->helperText($desc)->onColor('success');
        }

        return $form->schema([
            Section::make('หมวดที่ส่งถึงสมาชิก')
                ->description('ปิดหมวดไหน = ไม่ส่งหมวดนั้นถึงใครเลย (สมาชิกปิดรายหมวดเองได้อีกชั้นในหน้าบัญชี) · ข้อความเรียกสมาชิกว่า "คุณ" น้ำเสียงนุ่ม ไม่เร่งขาย')
                ->schema($toggles),
            Section::make('เวลาเงียบ (เวลาไทย)')
                ->description('ช่วงนี้ไม่ส่งยอดค้างและดวงประจำวัน — รอบแรกหลังหมดเวลาเงียบจะส่งให้เอง · "คำทำนายพร้อมแล้ว" ส่งทันทีเสมอ เพราะสมาชิกเพิ่งสั่งเอง · ตั้งเท่ากัน = ไม่มีเวลาเงียบ')
                ->schema([
                    TimePicker::make('quiet_start')->label('เริ่มเงียบ')->seconds(false)->required(),
                    TimePicker::make('quiet_end')->label('หมดเวลาเงียบ')->seconds(false)->required(),
                ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (array_keys(PushNotifier::TYPES) as $type) {
            Setting::put("push_type_{$type}_enabled", ! empty($data["type_$type"]) ? '1' : '0', 'push');
        }
        Setting::put('push_quiet_start', substr((string) $data['quiet_start'], 0, 5), 'push');
        Setting::put('push_quiet_end', substr((string) $data['quiet_end'], 0, 5), 'push');

        Notification::make()->title('บันทึกการตั้งค่าแจ้งเตือนแอปแล้ว')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('ทดสอบส่งถึงเครื่องของฉัน')
                ->icon('heroicon-o-paper-airplane')
                ->disabled(fn () => ! PushNotifier::ready())
                ->action(function () {
                    $r = app(PushNotifier::class)->test(auth()->user());
                    if ($r['devices'] === 0) {
                        Notification::make()->title('บัญชีแอดมินนี้ยังไม่ได้เปิดแจ้งเตือนบนเครื่องไหน')
                            ->body('เปิดหน้า บัญชีของฉัน → แจ้งเตือนบนเครื่องนี้ → เปิดแจ้งเตือน ก่อนนะคะ')->warning()->send();

                        return;
                    }
                    $r['delivered'] > 0
                        ? Notification::make()->title("ส่งแล้ว {$r['delivered']}/{$r['devices']} เครื่อง")->success()->send()
                        : Notification::make()->title('ส่งไม่ถึงเครื่องไหนเลย')->body('ดู storage/logs — push service อาจปฏิเสธกุญแจ VAPID')->danger()->send();
                }),
        ];
    }

    public function getStatusProperty(): array
    {
        return [
            'ready' => PushNotifier::ready(),
            'web' => PushNotifier::webReady(),
            'fcm' => FcmSender::configured(),
            'quiet' => PushNotifier::isQuiet(),
        ] + PushNotifier::stats();
    }

    public function getRecentProperty()
    {
        return PushDelivery::query()->latest('id')->limit(15)->get();
    }
}
