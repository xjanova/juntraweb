<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ThaipromptClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ThaipromptController extends Controller
{
    public function redirect(Request $request, ThaipromptClient $client): RedirectResponse
    {
        if (!$client->isEnabled()) {
            return redirect()->route('login')->withErrors(['email' => 'ระบบเข้าสู่ระบบ Thaiprompt ยังไม่ได้เปิดใช้งาน']);
        }

        $state = Str::random(40);
        $request->session()->put('thaiprompt_oauth_state', $state);

        return redirect()->away($client->authorizeUrl($state));
    }

    public function callback(Request $request, ThaipromptClient $client): RedirectResponse
    {
        if (!$client->isEnabled()) {
            return redirect()->route('login')->withErrors(['email' => 'Thaiprompt SSO ปิดอยู่']);
        }

        // CSRF — match state we put in session
        $expected = $request->session()->pull('thaiprompt_oauth_state');
        if (!$expected || !hash_equals($expected, (string) $request->query('state', ''))) {
            return redirect()->route('login')->withErrors(['email' => 'state ไม่ตรง — กรุณาลองใหม่อีกครั้ง']);
        }

        if ($err = $request->query('error')) {
            return redirect()->route('login')->withErrors(['email' => 'Thaiprompt ปฏิเสธ: ' . $err]);
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('login')->withErrors(['email' => 'ไม่ได้รับ authorization code']);
        }

        $token = $client->exchangeCode($code);
        if (!$token || empty($token['access_token'])) {
            return redirect()->route('login')->withErrors(['email' => 'แลก token ไม่สำเร็จ']);
        }

        $profile = $client->fetchUser($token['access_token']);
        if (!$profile || empty($profile['email'])) {
            return redirect()->route('login')->withErrors(['email' => 'ดึงข้อมูลผู้ใช้จาก Thaiprompt ไม่สำเร็จ']);
        }

        $email = strtolower((string) $profile['email']);
        $name  = (string) ($profile['name'] ?? $profile['username'] ?? Str::before($email, '@'));
        $tpId  = (string) ($profile['id'] ?? $profile['user_id'] ?? '');

        $user = User::where('email', $email)->orWhere('thaiprompt_user_id', $tpId)->first();
        if (!$user) {
            $user = new User();
            $user->email = $email;
            $user->password = Hash::make(Str::random(40));
            $user->role = 'user';
        }

        $user->name = $name;
        $user->thaiprompt_user_id = $tpId !== '' ? $tpId : $user->thaiprompt_user_id;
        $user->thaiprompt_token   = $token['access_token'];
        $user->thaiprompt_synced_at = now();
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->save();

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
