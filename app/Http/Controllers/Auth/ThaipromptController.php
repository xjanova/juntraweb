<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mlm\MlmApiClient;
use App\Services\ThaipromptClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class ThaipromptController extends Controller
{
    public function redirect(Request $request, ThaipromptClient $client): RedirectResponse
    {
        if (!$client->isEnabled()) {
            return redirect()->route('login')->withErrors(['email' => 'ระบบเข้าสู่ระบบ Thaiprompt ยังไม่ได้เปิดใช้งาน']);
        }

        // Deep-link ปลายทางหลัง SSO สำเร็จ — ให้ลิงก์จากแดชบอร์ด Thaiprompt
        // (เช่น "คอมมิชชั่นดูดวง") วิ่งมาที่ /auth/thaiprompt/redirect?to=/mlm/commissions
        // แล้ว auto-login เสร็จ callback จะ redirect()->intended() ไปหน้านั้นเลย
        // กัน open-redirect: รับเฉพาะ path ภายในเว็บนี้ (ขึ้นต้น "/" เดี่ยว ไม่ใช่ "//" หรือมี scheme)
        $to = (string) $request->query('to', '');
        if ($to !== '' && str_starts_with($to, '/') && !str_starts_with($to, '//') && !str_contains($to, "\\")) {
            $request->session()->put('url.intended', url($to));
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
            // ห้ามตั้ง role เอง — คอลัมน์เป็น enum('admin','editor','member')
            // (2026_05_07_000003_add_role_to_users.php) และ default = 'member'
            // อยู่แล้ว ค่าเดิมที่เคยเขียนไว้คือ 'user' ซึ่ง **ไม่มีในเอนัม**
            // MySQL โหมด strict (config/database.php: 'strict' => true) จึงตีกลับ
            // ตอน save() → ผู้ใช้ใหม่ทุกคนที่เข้ามาทาง SSO สมัครไม่ผ่านเลย
            // ส่วนคนเก่าไม่เจอ เพราะ query ด้านบนหาเจอแล้วไม่แตะ role
        }

        $user->name = $name;
        $user->thaiprompt_user_id = $tpId !== '' ? $tpId : $user->thaiprompt_user_id;
        $user->thaiprompt_token   = $token['access_token'];
        // Keep the refresh token + expiry so a stale access token can be
        // renewed (ThaipromptTokenService) instead of forcing a re-link.
        $user->thaiprompt_refresh_token = $token['refresh_token'] ?? null;
        $user->thaiprompt_token_expires_at = isset($token['expires_in'])
            ? now()->addSeconds((int) $token['expires_in'])
            : null;
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

        // Referral attribution — the จันทรา.online/r/{code} landing stored the
        // inviter's member_code in a 30-day cookie. Now that this user has a
        // live thaiprompt_token, claim it upstream so they join the inviter's
        // downline. Best-effort: a definitive upstream answer (success OR
        // rejection) clears the cookie; a network failure keeps it so the
        // next login retries.
        $referralStatus = $this->claimPendingReferral($request, $user);

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

        $redirect = redirect()->intended(route('dashboard'));
        if ($referralStatus !== null) {
            $redirect->with('status', $referralStatus);
        }
        return $redirect;
    }

    /**
     * Claim the pending juntra_ref cookie (if any) against Thaiprompt.
     * Returns a flash message on success, null otherwise.
     */
    private function claimPendingReferral(Request $request, User $user): ?string
    {
        // โค้ดที่ผู้ใช้กรอกตอนสมัครในแอพมาก่อน (เก็บฝั่งเซิร์ฟเวอร์ ไม่ผูกเบราว์เซอร์)
        // แล้วค่อยตกไปที่คุกกี้ของเบราว์เซอร์ที่เปิดลิงก์เชิญ
        //
        // ลำดับนี้สำคัญ: คนที่กดลิงก์เชิญบนมือถือแล้วสมัครในแอพ จะไม่มีคุกกี้
        // เลย เพราะคุกกี้อยู่ในเบราว์เซอร์คนละตัวกับ webview ที่ทำ SSO
        $code = (string) ($user->pending_referral_code ?: '');
        if ($code === '') {
            $code = (string) $request->cookie('juntra_ref', '');
        }
        $code = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $code) ?? '', 0, 64);
        if ($code === '') {
            return null;
        }

        $result = app(MlmApiClient::class)->claimReferral($user, $code);

        // Network failure → keep the cookie; a later login retries the claim.
        if ($result['status'] === 0) {
            return null;
        }

        // Any definitive upstream answer consumes the cookie — success,
        // invalid code, self-referral, or already enrolled in a network.
        Cookie::queue(Cookie::forget('juntra_ref'));

        // เคลียร์โค้ดที่ค้างไว้ฝั่งเซิร์ฟเวอร์ด้วย ไม่งั้นจะพยายามเคลมซ้ำทุกครั้ง
        // ที่ผู้ใช้ทำ SSO ใหม่ ทั้งที่อัปสตรีมตอบชัดเจนไปแล้ว
        if ($user->pending_referral_code !== null) {
            $user->forceFill(['pending_referral_code' => null])->save();
        }

        if ($result['claimed']) {
            $sponsorName = $result['sponsor']['name'] ?? null;
            Log::info('Referral claimed on Thaiprompt', [
                'user_id' => $user->id,
                'code'    => $code,
            ]);
            return $sponsorName
                ? "🎉 เข้าร่วมสายงานของ {$sponsorName} เรียบร้อย — เริ่มสร้างทีมของคุณได้เลย"
                : '🎉 เข้าร่วมสายงานเรียบร้อย — เริ่มสร้างทีมของคุณได้เลย';
        }

        Log::info('Referral claim declined upstream', [
            'user_id'     => $user->id,
            'reason_code' => $result['reason_code'],
        ]);
        return null;
    }
}
