<?php

namespace App\Http\Requests\Auth;

use App\Support\LoginChallenge;
use App\Support\PhoneNumber;
use App\Support\Turnstile;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // รับได้ทั้งอีเมลและเบอร์โทร — คนที่สมัครด้วยเบอร์ไม่มีอีเมลจริง
            // ให้จำ (ระบบสร้างอีเมลภายในให้) ถ้าบังคับ rule 'email' ที่นี่
            // เขาจะสมัครได้แต่เข้าระบบไม่ได้เลย
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim((string) $this->input('email'));
        $password = (string) $this->input('password');
        $remember = $this->boolean('remember');

        // ด่านกันบอท — โผล่เฉพาะเมื่อ IP นี้หรือบัญชีนี้ถูกกรอกผิดซ้ำ ๆ
        //
        // ต้องอยู่ก่อน Auth::attempt เพื่อไม่ให้สคริปต์ได้ลองรหัสแม้แต่ครั้งเดียว
        // และต้องมีที่นี่ ไม่ใช่แค่ /admin/login เพราะล็อกอินสำเร็จที่หน้านี้
        // ก็ได้ session ของ guard `web` ตัวเดียวกัน แล้วเดินเข้า /admin ได้เลย
        // ถ้าแอดมินคนนั้น role ผ่าน canAccessPanel()
        if (LoginChallenge::required($this->ip(), $login)) {
            if (! Turnstile::verify($this->input('cf-turnstile-response'), $this->ip())) {
                // ปักธงไว้ให้ widget โผล่รอบถัดไปแน่ ๆ — กรณีที่สะดุดแกน "ต่อบัญชี"
                // หน้า GET ยังไม่รู้ว่าจะกรอกบัญชีไหน จึงยังไม่ได้แสดง widget มา
                // ถ้าไม่ปักธง เจ้าของบัญชีที่โดนบ็อตเน็ตรุมจะติดวนล็อกอินไม่ได้
                LoginChallenge::markRequired();

                // ไม่เรียก recordFailure ที่นี่ — นี่คือ captcha ไม่ผ่าน/หมดอายุ
                // ไม่ใช่การเดารหัสผิด นับรวมกันจะลงโทษคนที่นั่งกรอกนานเกินไป
                throw ValidationException::withMessages([
                    'email' => 'กรุณายืนยันว่าคุณไม่ใช่บอทก่อนเข้าสู่ระบบ แล้วลองอีกครั้งนะคะ',
                ]);
            }
        }

        // ลองด้วยอีเมลก่อน แล้วค่อยลองเป็นเบอร์โทร (normalise ให้ตรงกับที่เก็บ)
        $ok = Auth::attempt(['email' => $login, 'password' => $password], $remember);

        if (! $ok) {
            $phone = PhoneNumber::normalise($login);
            if ($phone !== null) {
                $ok = Auth::attempt(['phone' => $phone, 'password' => $password], $remember);
            }
        }

        if (! $ok) {
            RateLimiter::hit($this->throttleKey());
            LoginChallenge::recordFailure($this->ip(), $login);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        // ล้างทั้งสองแกน ไม่ใช่แค่แกน IP — คนที่ลืมรหัสแล้วกรอกถูกในที่สุด
        // ไม่ควรถูกท้าทายต่ออีกชั่วโมงจากทุกเครื่องที่เขาใช้
        LoginChallenge::clear($this->ip(), $login);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /**
     * แปลงเบอร์ให้เป็นรูปเดียวกับที่เก็บตอนสมัคร
     * คืน null เมื่อไม่ใช่เบอร์ (เช่นเป็นอีเมล) เพื่อไม่ให้ยิง attempt เปล่า ๆ
     *
     * @deprecated เรียก {@see PhoneNumber::normalise()} ตรง ๆ — เหลือไว้เพื่อ
     *             ความเข้ากันได้ของโค้ดเดิม/เทสต์ที่อ้างชื่อนี้อยู่
     */
    public static function normalisePhone(?string $raw): ?string
    {
        return PhoneNumber::normalise($raw);
    }
}
