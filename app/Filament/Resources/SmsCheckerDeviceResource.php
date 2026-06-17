<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SmsCheckerDeviceResource\Pages;
use App\Models\SmsCheckerDevice;
use App\Support\QrImage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class SmsCheckerDeviceResource extends Resource
{
    protected static ?string $model = SmsCheckerDevice::class;
    protected static ?string $navigationIcon  = 'heroicon-o-device-phone-mobile';
    protected static ?string $modelLabel      = 'อุปกรณ์ SMS Checker';
    protected static ?string $pluralModelLabel = 'อุปกรณ์ SMS Checker';
    protected static ?string $navigationGroup = 'การเงิน';
    protected static ?int    $navigationSort  = 3;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('device_name')->label('ชื่ออุปกรณ์')->required()->default('SMS Checker'),
            Forms\Components\Select::make('approval_mode')->label('โหมดอนุมัติ')
                ->options(['auto' => 'อัตโนมัติ (เครดิตทันทีเมื่อ SMS ตรง)', 'manual' => 'ตรวจเอง'])
                ->default('auto')->required(),
            Forms\Components\Select::make('status')->label('สถานะ')
                ->options(['active' => 'ใช้งาน', 'inactive' => 'ปิด', 'blocked' => 'บล็อก'])
                ->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('device_id')->label('Device ID')->copyable()->searchable(),
                Tables\Columns\TextColumn::make('device_name')->label('ชื่อ')->searchable(),
                Tables\Columns\BadgeColumn::make('status')->label('สถานะ')
                    ->colors(['success' => 'active', 'gray' => 'inactive', 'danger' => 'blocked']),
                Tables\Columns\BadgeColumn::make('approval_mode')->label('โหมด')
                    ->colors(['success' => 'auto', 'warning' => 'manual']),
                Tables\Columns\TextColumn::make('app_version')->label('เวอร์ชัน')->toggleable(),
                Tables\Columns\TextColumn::make('last_active_at')->label('ใช้งานล่าสุด')->dateTime('d/m H:i')->placeholder('—'),
            ])
            ->actions([
                Action::make('qr')
                    ->label('QR ตั้งค่า')
                    ->icon('heroicon-o-qr-code')
                    ->color('info')
                    ->modalHeading('ตั้งค่าแอป SMS Checker')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('ปิด')
                    ->modalContent(function (SmsCheckerDevice $record) {
                        $config = [
                            'type'       => 'smschecker_config',
                            'version'    => 1,
                            'url'        => rtrim((string) config('app.url'), '/'),
                            'apiKey'     => $record->api_key,
                            'secretKey'  => $record->secret_key,
                            'deviceName' => $record->device_name,
                        ];
                        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $qr = QrImage::svgDataUri($json);
                        $img = $qr
                            ? '<img src="' . e($qr) . '" style="width:240px;height:240px;background:#fff;padding:10px;border-radius:12px;margin:0 auto;display:block">'
                            : '<p style="color:#c2382e">ไม่สามารถสร้าง QR ได้</p>';

                        return new HtmlString(
                            '<div style="text-align:center">' . $img .
                            '<p style="margin-top:12px;font-size:12px;color:#888">สแกนด้วยแอป SMS Checker เพื่อเชื่อมต่อกับ ' . e($record->device_name) . '</p>' .
                            '<textarea readonly style="width:100%;height:90px;font-size:11px;margin-top:8px;font-family:monospace">' . e($json) . '</textarea>' .
                            '</div>'
                        );
                    }),
                Action::make('toggle')
                    ->label(fn (SmsCheckerDevice $r) => $r->status === 'active' ? 'บล็อก' : 'เปิดใช้')
                    ->icon('heroicon-o-power')
                    ->color(fn (SmsCheckerDevice $r) => $r->status === 'active' ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (SmsCheckerDevice $r) => $r->update(['status' => $r->status === 'active' ? 'blocked' : 'active'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSmsCheckerDevices::route('/'),
            'create' => Pages\CreateSmsCheckerDevice::route('/create'),
        ];
    }
}
