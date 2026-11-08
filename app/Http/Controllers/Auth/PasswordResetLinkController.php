<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NotReservedEmailDomain;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password', [
            'lineUrl' => config('business.social.line.url'),
        ]);
    }

    /**
     * ส่งลิงก์ตั้งรหัสผ่านใหม่
     *
     * รับได้ทั้งอีเมลและเบอร์โทร (เหมือนหน้าเข้าสู่ระบบ) — เดิมรับแค่อีเมล
     * คนที่สมัครด้วยเบอร์จึงขอรีเซ็ตไม่ได้เลย
     *
     * บัญชีที่ไม่มีอีเมลจริง (สมัครด้วยเบอร์ / มาจากบอท Thaiprompt) และตอนที่ระบบส่งอีเมล
     * ยังไม่พร้อม จะบอกทางไปต่อแทนการเงียบหรือหน้า 500
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'max:255'],
        ], [], ['email' => 'อีเมลหรือเบอร์โทร']);

        $login = trim((string) $request->input('email'));
        $phone = PhoneNumber::normalise($login);

        if ($phone === null && ! filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return $this->failed($request, 'กรุณากรอกอีเมล หรือเบอร์โทรศัพท์ที่ใช้สมัคร เช่น 0812345678');
        }

        $user = $phone !== null
            ? User::where('phone', $phone)->first()
            : User::where('email', strtolower($login))->first();

        if (! $user) {
            return $this->failed($request, __('passwords.user'));
        }

        // ไม่มีอีเมลจริงให้ส่ง — อีเมลที่ระบบสร้างให้ส่งไม่ถึงใครจริง
        if (PhoneNumber::isPlaceholderEmail($user->email) || NotReservedEmailDomain::isReserved($user->email)) {
            $message = $user->thaiprompt_user_id
                ? 'บัญชีนี้เข้าใช้ผ่าน Facebook/LINE — กดปุ่ม "เข้าสู่ระบบด้วย Thaiprompt" ที่หน้าเข้าสู่ระบบได้เลย ไม่ต้องใช้รหัสผ่าน'
                : 'บัญชีนี้สมัครด้วยเบอร์โทร ไม่มีอีเมลให้ส่งลิงก์ — ทักแม่หมอทาง LINE ' . $this->lineLabel() . ' เพื่อตั้งรหัสผ่านใหม่ได้เลยค่ะ';

            return $this->failed($request, $message);
        }

        if (! $this->mailReady()) {
            Log::warning('Password reset requested but mail is not configured', ['user_id' => $user->id]);

            return $this->failed($request, 'ตอนนี้ระบบส่งอีเมลยังไม่พร้อม — ทักแม่หมอทาง LINE ' . $this->lineLabel() . ' เพื่อตั้งรหัสผ่านใหม่ได้เลยค่ะ');
        }

        try {
            $status = Password::sendResetLink(['email' => $user->email]);
        } catch (Throwable $e) {
            // SMTP ล่ม/ตั้งค่าผิด — ห้ามโยน 500 ใส่ลูกค้า และห้ามเอาข้อความดิบ (มี host/รหัส) ไปโชว์
            Log::error('Password reset mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->failed($request, 'ส่งอีเมลไม่สำเร็จ กรุณาลองใหม่ภายหลัง หรือทักแม่หมอทาง LINE ' . $this->lineLabel());
        }

        return $status == Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : $this->failed($request, __($status));
    }

    private function failed(Request $request, string $message): RedirectResponse
    {
        return back()->withInput($request->only('email'))->withErrors(['email' => $message]);
    }

    /**
     * ระบบส่งอีเมลตั้งค่าไว้จริงไหม — ค่าตัวอย่างจาก .env.example (smtp.example.com)
     * หรือ mailer ที่ไม่ส่งจริง (log/array) นับว่ายังไม่พร้อม ลูกค้าจะได้ไม่รอเมลที่ไม่มีวันมา
     */
    private function mailReady(): bool
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            return app()->environment('local', 'testing');
        }

        if ($mailer === 'smtp') {
            $host = strtolower((string) config('mail.mailers.smtp.host'));

            return $host !== '' && ! str_contains($host, 'example.com') && ! str_contains($host, 'example.org');
        }

        return true;
    }

    private function lineLabel(): string
    {
        return '@002dqcls';
    }
}
