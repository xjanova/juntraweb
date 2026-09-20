<?php

namespace App\Filament\Pages\Auth;

use App\Support\LoginChallenge;
use App\Support\Turnstile;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\Login as BaseLogin;

/**
 * หน้าล็อกอินหลังบ้าน + Cloudflare Turnstile (บังคับทุกครั้ง)
 *
 * ของเดิมที่ Filament ให้มามีแค่สองด่าน: rateLimit(5) ต่อ IP ต่อนาที
 * และ User::canAccessPanel() ที่ปล่อยเฉพาะ role admin/editor — ไม่มีอะไร
 * แยกคนจากสคริปต์เลย บ็อตเน็ตที่มี IP เยอะ ๆ จึงไล่เดารหัสได้เรื่อย ๆ
 * เพราะเพดาน 5 ครั้งผูกกับ IP ของผู้ยิง ไม่ใช่บัญชีที่ถูกยิง
 *
 * ที่นี่บังคับ Turnstile ทุกครั้งไม่มีข้อยกเว้น ต่างจาก /login ของลูกค้า
 * ที่ท้าทายเฉพาะเมื่อกรอกผิดซ้ำ ({@see LoginChallenge}) —
 * เพราะหลังบ้านมีผู้ใช้ไม่กี่คน แรงเสียดทานจึงไม่ใช่ต้นทุนที่ต้องแคร์
 * แต่เป็นเป้าที่มีค่าที่สุดในระบบ (เห็นคำสั่งซื้อ/กระเป๋าเงินลูกค้าทั้งหมด)
 *
 * ปิดเองเมื่อยังไม่ได้ตั้งคีย์ — เครื่อง dev และเทสต์จึงล็อกอินได้ตามปกติ
 * โดยไม่ต้องต่อเน็ต แต่แปลว่า **บน production ต้องตั้ง TURNSTILE_SITE_KEY
 * กับ TURNSTILE_SECRET ก่อน ไม่งั้นไฟล์นี้ไม่ได้ป้องกันอะไรเลย**
 */
class AdminLogin extends BaseLogin
{
    /**
     * token ที่ widget ส่งกลับมา
     *
     * เป็น property ของ component ไม่ใช่ field ใน $data เพราะ Cloudflare
     * ยัดค่าให้ผ่าน JS callback ไม่ได้ผ่าน input ที่ Livewire ผูกไว้
     * (ดูเหตุผลเต็มใน resources/views/filament/auth/turnstile.blade.php)
     */
    public ?string $turnstileToken = null;

    /**
     * {@inheritDoc}
     *
     * คัดลอก schema ของ parent มาแล้วต่อท้ายด้วย Turnstile เพราะ Filament 3
     * ไม่มี hook ให้แทรก field เดี่ยว ๆ
     *
     * @upgrade เวลาอัป Filament ให้เทียบกับ Filament\Pages\Auth\Login::getForms()
     *          ว่ามี component เพิ่มมาไหม (ถ้ามี field ใหม่หายไปจะเห็นทันทีที่หน้าจอ
     *          ไม่ใช่ช่องโหว่เงียบ ๆ)
     *
     * @return array<int | string, Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getRememberFormComponent(),
                        $this->getTurnstileFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getTurnstileFormComponent(): Component
    {
        return ViewField::make('turnstile')
            ->view('filament.auth.turnstile')
            ->hiddenLabel()
            // ไม่ต้องติดไปกับ state ที่ส่งเข้า getCredentialsFromFormData()
            ->dehydrated(false)
            // ยังไม่ตั้งคีย์ = ไม่ต้องแสดงช่องว่างเปล่า ๆ ให้สับสน
            ->visible(Turnstile::enabled());
    }

    public function authenticate(): ?LoginResponse
    {
        // เพดานกว้าง ๆ แยกถังจาก rateLimit(5) ของ parent (คนละ $method = คนละคีย์)
        //
        // มีเพราะการเช็ค Turnstile ยิง HTTP ออกไป challenges.cloudflare.com
        // (timeout 8 วินาที) แปลว่าหน้าที่ไม่ต้องล็อกอินหน้านี้กลายเป็นที่ที่
        // คนยิงรัว ๆ ด้วย token ขยะจะจับ php-fpm worker ค้างได้ทีละ 8 วินาที
        // — ก่อนหน้านี้หน้านี้ไม่ยิงเน็ตออกเลย ช่องนี้เราสร้างขึ้นเองจึงต้องปิดเอง
        //
        // 30/นาที กว้างพอที่แอดมินตัวจริงไม่มีทางชน (พิมพ์รหัสพลาด 30 ครั้ง
        // ในหนึ่งนาทีคือชน rateLimit(5) ของ parent ไปนานแล้ว)
        try {
            $this->rateLimit(30, method: 'turnstile');
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        // เช็ค Turnstile ก่อน rateLimit(5) ของ parent โดยเจตนา:
        // token ว่าง (บ็อตส่วนใหญ่) ถูกปฏิเสธในเครื่องทันที ไม่ยิงเน็ตออกเลย
        // และไม่กิน budget 5 ครั้งทิ้ง — แอดมินตัวจริงที่พิมพ์รหัสพลาดจึงยังมี
        // โอกาสครบ 5 ครั้งของตัวเอง ไม่ใช่ถูกบ็อตใช้หมดไปก่อน
        if (! Turnstile::verify($this->turnstileToken, request()->ip())) {
            $this->resetTurnstile();

            Notification::make()
                ->title('ยืนยันว่าไม่ใช่บอทไม่ผ่าน')
                // เผื่อกรณีกดเร็วกว่า widget ทำงานเสร็จ (โหมด Managed ใช้เวลา
                // ~1 วินาที) ข้อความต้องบอกทางออกด้วย ไม่ใช่บอกแค่ว่าไม่ผ่าน
                ->body('รอให้ช่องยืนยันตัวตนขึ้นเครื่องหมายถูกก่อน แล้วกดเข้าสู่ระบบอีกครั้ง')
                ->danger()
                ->send();

            return null;
        }

        try {
            $response = parent::authenticate();
        } catch (\Throwable $e) {
            // รหัสผิด หรือ role เข้าหลังบ้านไม่ได้ — parent โยน ValidationException
            // ออกมา หน้าไม่ได้โหลดใหม่ ดังนั้น token ที่ใช้ไปแล้วยังค้างอยู่
            $this->resetTurnstile();

            throw $e;
        }

        // parent คืน null เมื่อชนเพดาน rate limit (ส่ง notification ไปแล้ว)
        if ($response === null) {
            $this->resetTurnstile();
        }

        return $response;
    }

    /**
     * ขอ token ใหม่จาก widget
     *
     * Cloudflare ให้ใช้ token ละครั้งเดียว ถ้าไม่รีเซ็ตหลังล็อกอินไม่สำเร็จ
     * รอบถัดไปจะถูกปฏิเสธด้วยเหตุผลผิด ๆ ว่า "ไม่ใช่บอทไม่ผ่าน"
     * แล้วแอดมินจะติดวนอยู่แบบนั้นจนกด refresh หน้าเอง
     */
    private function resetTurnstile(): void
    {
        $this->turnstileToken = null;

        $this->dispatch('turnstile-reset');
    }
}
