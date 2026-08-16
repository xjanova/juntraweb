<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Mobile auth — email/password Sanctum tokens for the juntra Flutter app.
 *
 * Web flow (Breeze + Thaiprompt SSO) is untouched. This controller adds
 * a parallel JSON path so mobile can authenticate without the SSO
 * redirect dance, then later (optionally) link the same user to
 * Thaiprompt by visiting the web auth/thaiprompt/redirect from inside
 * an in-app webview.
 */
class AuthController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    /**
     * เข้าสู่ระบบด้วย **อีเมลหรือเบอร์โทร** — ต้องรับได้ทั้งคู่เหมือนฝั่งเว็บ
     *
     * ลูกค้าหลักของแบรนด์เป็นผู้สูงอายุ ฝั่งเว็บ (RegisteredUserController)
     * จึงยอมให้สมัครด้วย "เบอร์อย่างเดียว" แล้วสร้างอีเมลภายในให้เงียบ ๆ
     * (`p08xxxxxxxx@phone.juntra.local`) ซึ่งเจ้าตัวไม่มีทางรู้ ถ้า API ของแอพ
     * ยังบังคับ `email` อย่างเดิม คนกลุ่มนี้ **สมัครทางเว็บแล้วเข้าแอพไม่ได้เลย**
     * — บัญชีเดียวกันแท้ ๆ แต่เข้าได้ทางเดียว
     *
     * รับคีย์ `login` เป็นหลัก และยังรับ `email` ต่อไปเพื่อให้ APK รุ่นเก่า
     * ที่ติดตั้งอยู่แล้วใช้งานได้เหมือนเดิม
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            // ไม่ผูก rule 'email' เด็ดขาด — ช่องนี้เป็นเบอร์ก็ได้
            'login'    => 'sometimes|string|max:255',
            'email'    => 'sometimes|string|max:255',
            'password' => 'required|string',
            'device'   => 'sometimes|string|max:64',
        ]);

        $login = trim((string) ($data['login'] ?? $data['email'] ?? ''));
        if ($login === '') {
            return response()->json([
                'message' => 'กรุณากรอกอีเมลหรือเบอร์โทรศัพท์',
                'errors'  => ['login' => ['กรุณากรอกอีเมลหรือเบอร์โทรศัพท์']],
            ], 422);
        }

        // อีเมลก่อน แล้วค่อยลองเป็นเบอร์ — ลำดับเดียวกับ LoginRequest ของเว็บ
        $user = User::where('email', $login)->first();
        if (!$user) {
            $phone = PhoneNumber::normalise($login);
            if ($phone !== null) {
                $user = User::where('phone', $phone)->first();
            }
        }

        // บัญชีที่ผูกผ่าน SSO อย่างเดียวจะไม่มีรหัสผ่าน (password = null) —
        // ต้องกันไว้ก่อนเรียก Hash::check ไม่งั้น PHP 8 เตือน strlen(null)
        // แล้วคืน false อยู่ดี แต่เขียนให้ชัดว่าตั้งใจปฏิเสธ
        if (!$user || $user->password === null || $user->password === ''
            || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'อีเมล/เบอร์โทร หรือรหัสผ่านไม่ถูกต้อง',
            ], 401);
        }

        $deviceName = $data['device'] ?? ($request->userAgent() ?: 'mobile');
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => $this->profile($user),
            ],
        ]);
    }

    /**
     * สมัครสมาชิกจากแอพ — เงื่อนไขเดียวกับฟอร์มสมัครของเว็บทุกข้อ
     *
     * "อีเมล หรือ เบอร์โทร อย่างน้อยอย่างใดอย่างหนึ่ง" คือข้อกำหนดของเจ้าของ
     * (2026-07-25) เพื่อลดแรงเสียดทานก่อนจ่ายเงิน บัญชีที่สมัครจากแอพจึงต้อง
     * หน้าตาเหมือนบัญชีที่สมัครจากเว็บเป๊ะ ไม่งั้นข้อมูลสองฝั่งเพี้ยนกัน
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:120',
            'email'    => 'nullable|required_without:phone|email|max:255|unique:users,email',
            'phone'    => 'nullable|required_without:email|string|max:32',
            'password' => ['required', 'confirmed', Password::min(8)],
            'device'   => 'sometimes|string|max:64',
            // โค้ดผู้แนะนำ — แอพส่งมาได้ทั้งจากช่องที่ผู้ใช้พิมพ์เอง และจาก
            // Play Install Referrer ในอนาคต เก็บไว้ก่อน เคลมตอนผูก Thaiprompt
            'referral_code' => 'sometimes|nullable|string|max:64',
        ], [
            'email.required_without' => 'กรุณากรอกอีเมล หรือเบอร์โทรศัพท์อย่างใดอย่างหนึ่ง',
            'phone.required_without' => 'กรุณากรอกเบอร์โทรศัพท์ หรืออีเมลอย่างใดอย่างหนึ่ง',
            'email.unique'           => 'อีเมลนี้เคยสมัครไว้แล้ว — ลองเข้าสู่ระบบนะคะ',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'ข้อมูลไม่ถูกต้อง',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data     = $validator->validated();
        $rawPhone = $data['phone'] ?? null;
        $phone    = PhoneNumber::normalise($rawPhone);

        if ($rawPhone !== null && trim($rawPhone) !== '' && $phone === null) {
            return response()->json([
                'message' => 'ข้อมูลไม่ถูกต้อง',
                'errors'  => ['phone' => ['เบอร์โทรศัพท์ไม่ถูกต้อง กรุณากรอกให้ครบ เช่น 0812345678']],
            ], 422);
        }

        // เบอร์คือตัวตนของลูกค้ากลุ่มนี้ — ห้ามซ้ำ ไม่งั้นสองคนใช้บัญชีทับกัน
        if ($phone !== null && User::where('phone', $phone)->exists()) {
            return response()->json([
                'message' => 'ข้อมูลไม่ถูกต้อง',
                'errors'  => ['phone' => ['เบอร์นี้เคยสมัครไว้แล้ว — ลองเข้าสู่ระบบ หรือใช้เบอร์อื่นนะคะ']],
            ], 422);
        }

        // sanitize ชุดเดียวกับ ReferralController / ThaipromptController
        // (โค้ดที่มีอักขระนอกชุดนี้ทำให้ลิงก์ /r/{code} 404 อยู่แล้ว)
        $referral = substr(
            preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($data['referral_code'] ?? '')) ?? '',
            0,
            64,
        );

        $attributes = [
            'name'       => $data['name'],
            'email'      => $data['email'] ?? PhoneNumber::placeholderEmail($phone),
            'phone'      => $phone,
            'password'   => Hash::make($data['password']),
            'signup_via' => 'mobile',
        ];

        // ใส่คีย์นี้เฉพาะเมื่อมีโค้ดจริง **และคอลัมน์มีอยู่แล้ว**
        //
        // auto-deploy ของเซิร์ฟเวอร์ pull โค้ดให้ทันทีตอน push แต่ไม่ได้รัน
        // migration ให้เสมอ ถ้าส่งคีย์นี้ไปตรง ๆ ในช่วงที่ยังไม่ได้ migrate
        // การสมัครจากแอพจะ 500 ทุกราย — ยอมเสียการผูกสายงานชั่วคราว
        // ดีกว่าปิดประตูสมัครทั้งบาน
        if ($referral !== '' && Schema::hasColumn('users', 'pending_referral_code')) {
            $attributes['pending_referral_code'] = $referral;
        }

        $user = User::create($attributes);

        // Pre-create wallet so the first GET /wallet doesn't lazy-create
        // mid-request and leak the latency to the user.
        $this->wallet->getOrCreate($user);

        $token = $user->createToken($data['device'] ?? 'mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => $this->profile($user->fresh()),
            ],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->profile($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revokes the *current* token only — other devices stay logged in.
        $request->user()->currentAccessToken()->delete();
        return response()->json(['data' => ['message' => 'ออกจากระบบเรียบร้อย']]);
    }

    /**
     * Mint a SHORT-LIVED, single-use handoff code for the web OAuth bootstrap
     * (Thaiprompt SSO link).
     *
     * The app must hand its session to the browser to finish the SSO link, but
     * putting the long-lived Sanctum bearer in the mobile-start URL leaks it
     * into browser history / server logs. Instead the app calls THIS with the
     * bearer in the Authorization header and passes the returned code (valid
     * 120s, usable once) in the URL. Even if the code is logged it's useless
     * after it expires and can't be replayed. The raw code is never stored —
     * only its SHA-256 — so a cache dump doesn't reveal usable codes.
     */
    public function handoff(Request $request): JsonResponse
    {
        $code = Str::random(48);
        Cache::put(
            'mobile_handoff:' . hash('sha256', $code),
            $request->user()->id,
            now()->addSeconds(120),
        );

        return response()->json([
            'data' => ['code' => $code, 'expires_in' => 120],
        ]);
    }

    /**
     * Compact user payload — same shape as /api/v1/auth/me so the Flutter
     * client can cache it from the login response without a second call.
     */
    private function profile(User $user): array
    {
        // อีเมลที่ระบบสร้างให้คนที่สมัครด้วยเบอร์ ห้ามโชว์เป็น "อีเมลของคุณ"
        // — มันไม่ใช่อีเมลจริง ส่งเมลไปก็ไม่ถึง และทำให้ผู้ใช้สับสน
        $placeholder = PhoneNumber::isPlaceholderEmail($user->email);

        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'display_name'      => $user->name,
            'email'             => $placeholder ? null : $user->email,
            'phone'             => $user->phone,
            'login_hint'        => $placeholder ? $user->phone : $user->email,
            'role'              => $user->role,
            'is_admin'          => $user->isAdmin(),
            'thaiprompt_linked' => $user->isThaipromptLinked(),
            'chat_link_channel' => $user->chatLinkChannel(),
            'wallet'            => [
                'balance'  => (float) $this->wallet->balance($user),
                'currency' => config('pricing.currency', 'THB'),
            ],
        ];
    }
}
