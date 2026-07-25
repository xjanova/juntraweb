<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Turnstile;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * โดเมนสำหรับอีเมลที่ระบบสร้างให้คนที่สมัครด้วยเบอร์
     * ตั้งใจใช้โดเมนที่ส่งเมลไม่ได้จริง — เป็นแค่คีย์ในระบบ ไม่ใช่ช่องทางติดต่อ
     */
    private const PLACEHOLDER_EMAIL_DOMAIN = 'phone.juntra.local';

    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * สมัครสมาชิก
     *
     * เจ้าของกำหนด (2026-07-25): สมัครด้วย Facebook/LINE ก็ได้ ถ้าไม่มีให้กรอก
     * เบอร์โทรไว้ก่อนแล้วสุ่มอีเมลให้ — เพื่อลดแรงเสียดทานก่อนจ่ายเงิน
     * (ลูกค้าหลักเป็นผู้สูงอายุ หลายคนไม่มีอีเมลหรือจำไม่ได้)
     * แต่ต้องผ่าน Cloudflare Turnstile เพราะไม่มีอีเมลให้ยืนยันตัวตนอีกแล้ว
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            // อีเมลหรือเบอร์ อย่างน้อยอย่างใดอย่างหนึ่ง
            'email'    => ['nullable', 'required_without:phone', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'phone'    => ['nullable', 'required_without:email', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'email.required_without' => 'กรุณากรอกอีเมล หรือเบอร์โทรศัพท์อย่างใดอย่างหนึ่ง',
            'phone.required_without' => 'กรุณากรอกเบอร์โทรศัพท์ หรืออีเมลอย่างใดอย่างหนึ่ง',
        ], [
            'name' => 'ชื่อ', 'email' => 'อีเมล', 'phone' => 'เบอร์โทรศัพท์', 'password' => 'รหัสผ่าน',
        ]);

        if (! Turnstile::verify($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages([
                'name' => 'ระบบยืนยันว่าคุณไม่ใช่บอทไม่ผ่าน กรุณาลองใหม่อีกครั้ง',
            ]);
        }

        $phone = $this->normalisePhone($data['phone'] ?? null);
        if ($phone !== null && User::where('phone', $phone)->exists()) {
            // เบอร์คือตัวตนของลูกค้ากลุ่มนี้ — ห้ามซ้ำ ไม่งั้นสองคนใช้บัญชีทับกัน
            throw ValidationException::withMessages([
                'phone' => 'เบอร์นี้เคยสมัครไว้แล้ว — ลองเข้าสู่ระบบ หรือใช้เบอร์อื่นนะคะ',
            ]);
        }

        // ไม่มีอีเมล → สร้างให้จากเบอร์ (ไม่ซ้ำเพราะเบอร์ไม่ซ้ำ)
        $email = $data['email'] ?? ('p'.$phone.'@'.self::PLACEHOLDER_EMAIL_DOMAIN);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $email,
            'phone'    => $phone,
            'password' => Hash::make($data['password']),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

    /** เหลือเฉพาะตัวเลข + แปลง +66 ให้เป็นรูปเดียวกันเสมอ (08xxxxxxxx) */
    private function normalisePhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        // +66 8xxxxxxxx / 668xxxxxxxx → 08xxxxxxxx
        if (str_starts_with($digits, '66') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 2);
        }

        return substr($digits, 0, 32);
    }
}
