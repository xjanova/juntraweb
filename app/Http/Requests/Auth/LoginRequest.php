<?php

namespace App\Http\Requests\Auth;

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

        $login    = trim((string) $this->input('email'));
        $password = (string) $this->input('password');
        $remember = $this->boolean('remember');

        // ลองด้วยอีเมลก่อน แล้วค่อยลองเป็นเบอร์โทร (normalise ให้ตรงกับที่เก็บ)
        $ok = Auth::attempt(['email' => $login, 'password' => $password], $remember);

        if (! $ok) {
            $phone = self::normalisePhone($login);
            if ($phone !== null) {
                $ok = Auth::attempt(['phone' => $phone, 'password' => $password], $remember);
            }
        }

        if (! $ok) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
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
     * แปลงเบอร์ให้เป็นรูปเดียวกับที่เก็บตอนสมัคร (RegisteredUserController)
     * คืน null เมื่อไม่ใช่เบอร์ (เช่นเป็นอีเมล) เพื่อไม่ให้ยิง attempt เปล่า ๆ
     */
    public static function normalisePhone(?string $raw): ?string
    {
        $raw = (string) $raw;
        if ($raw === '' || str_contains($raw, '@')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($digits) < 9) {
            return null;
        }

        if (str_starts_with($digits, '66') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 2);
        }

        return substr($digits, 0, 32);
    }
}
