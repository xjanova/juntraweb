<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PhoneNumber;
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

        // ต้อง normalise ให้ผ่านก่อน ไม่งั้นเบอร์สั้น/ไม่ใช่เบอร์จะกลายเป็น
        // บัญชีที่ล็อกอินกลับเข้ามาไม่ได้ (ฝั่งล็อกอินไม่รับเบอร์ที่หลักไม่พอ)
        $rawPhone = $data['phone'] ?? null;
        $phone    = PhoneNumber::normalise($rawPhone);
        if ($rawPhone !== null && trim($rawPhone) !== '' && $phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'เบอร์โทรศัพท์ไม่ถูกต้อง กรุณากรอกให้ครบ เช่น 0812345678',
            ]);
        }

        if ($phone !== null && User::where('phone', $phone)->exists()) {
            // เบอร์คือตัวตนของลูกค้ากลุ่มนี้ — ห้ามซ้ำ ไม่งั้นสองคนใช้บัญชีทับกัน
            throw ValidationException::withMessages([
                'phone' => 'เบอร์นี้เคยสมัครไว้แล้ว — ลองเข้าสู่ระบบ หรือใช้เบอร์อื่นนะคะ',
            ]);
        }

        // ไม่มีอีเมล → สร้างให้จากเบอร์ (ไม่ซ้ำเพราะเบอร์ไม่ซ้ำ)
        $email = $data['email'] ?? PhoneNumber::placeholderEmail($phone);

        $user = User::create([
            'name'       => $data['name'],
            'email'      => $email,
            'phone'      => $phone,
            'password'   => Hash::make($data['password']),
            'signup_via' => 'web',
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

}
