<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ThaipromptClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

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

    /**
     * Mobile bootstrap for the OAuth flow.
     *
     * The Juntra Flutter app can't share its Sanctum bearer with the web
     * browser. PREFERRED: the app calls POST /api/v1/auth/handoff (bearer in
     * the header) and passes the returned short-lived single-use `?code=` here.
     * We resolve the code to a user, log them into the web session (so the
     * upstream Thaiprompt callback identifies the same user), tag the session
     * as "started from mobile", then forward into the normal /redirect flow.
     *
     * DEPRECATED fallback: `?bearer=<sanctum token>` for older app builds.
     * This leaks a long-lived token into URL/history — remove once all
     * installs use the code path.
     *
     * Security:
     *   - The handoff code is single-use (Cache::pull) and expires in 120s, so
     *     a logged/leaked URL can't be replayed.
     *   - The bearer fallback only resolves valid Sanctum tokens (rotated/
     *     revoked fail closed); we never login-as a different user.
     */
    public function mobileStart(Request $request, ThaipromptClient $client): RedirectResponse
    {
        if (!$client->isEnabled()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'ระบบเข้าสู่ระบบ Thaiprompt ยังไม่ได้เปิดใช้งาน']);
        }

        $user = $this->resolveHandoffUser($request);
        if (!$user) {
            return redirect()->route('login')
                ->withErrors(['email' => 'ลิงก์เชื่อมต่อหมดอายุหรือไม่ถูกต้อง — กรุณากลับไปที่แอพแล้วลองใหม่']);
        }

        // Establish a web session for the same user so the OAuth callback
        // updates the right User row. `remember: false` — mobile-bootstrap
        // sessions shouldn't outlive the OAuth handshake.
        Auth::login($user, false);
        $request->session()->regenerate();

        // Tag the session so the callback shows the "back to app"
        // success page instead of redirecting to /dashboard.
        $request->session()->put('thaiprompt_oauth_origin', 'mobile');

        return redirect()->route('thaiprompt.redirect');
    }

    /**
     * Resolve the mobile-bootstrap user from a single-use `?code=` (preferred)
     * or a deprecated `?bearer=` Sanctum token. Returns null on miss/expiry.
     */
    private function resolveHandoffUser(Request $request): ?User
    {
        $code = (string) $request->query('code', '');
        if ($code !== '') {
            // Single-use: pull = get + forget, so a replayed URL fails.
            $userId = Cache::pull('mobile_handoff:' . hash('sha256', $code));
            return $userId ? User::find($userId) : null;
        }

        // Deprecated fallback — long-lived bearer in the URL (older app builds).
        $bearer = (string) $request->query('bearer', '');
        if ($bearer === '') {
            return null;
        }
        $sanctum = PersonalAccessToken::findToken($bearer);
        if (!$sanctum || !$sanctum->tokenable_id) {
            return null;
        }
        return User::find($sanctum->tokenable_id);
    }

    /**
     * @return RedirectResponse|View
     */
    public function callback(Request $request, ThaipromptClient $client)
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

        // FB/LINE link state from Thaiprompt — used to gate the AI chat.
        // Thaiprompt's User model carries `line_user_id` natively; FB users
        // are tracked indirectly via FortuneReading PSIDs (Thaiprompt may
        // surface a `facebook_user_id` field on /api/user once we expose it).
        $lineId  = (string) ($profile['line_user_id'] ?? '');
        $fbId    = (string) ($profile['facebook_user_id'] ?? $profile['fb_psid'] ?? '');
        $signup  = (string) ($profile['signup_via'] ?? '');

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

        // Refresh FB/LINE link from upstream — never blank out a previously
        // captured id (Thaiprompt may stop returning it on later refreshes).
        if ($lineId !== '') $user->line_user_id = $lineId;
        if ($fbId   !== '') $user->facebook_user_id = $fbId;

        // Derive signup_via if upstream didn't set one explicitly.
        if ($signup !== '') {
            $user->signup_via = $signup;
        } elseif (!$user->signup_via) {
            $user->signup_via = $fbId !== '' ? 'facebook'
                              : ($lineId !== '' ? 'line' : 'thaiprompt');
        }

        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->save();

        Auth::login($user, true);
        $request->session()->regenerate();

        // Mobile-initiated flow → render a "back to app" success page
        // instead of dropping the user into the web dashboard. The page
        // has no app deep link (mobile detects the link via the next
        // /auth/me call when the app foreground-resumes); we just need
        // to tell the user the handshake worked.
        if ($request->session()->pull('thaiprompt_oauth_origin') === 'mobile') {
            return view('pages.auth.oauth-mobile-success', [
                'user' => $user,
            ]);
        }

        return redirect()->intended(route('dashboard'));
    }
}
