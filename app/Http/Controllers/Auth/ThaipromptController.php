<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NotReservedEmailDomain;
use App\Services\Affiliate\MaeMorAffiliate;
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
        $handoff = false;
        if ($to !== '' && str_starts_with($to, '/') && !str_starts_with($to, '//') && !str_contains($to, "\\")) {
            $request->session()->put('url.intended', url($to));
            $handoff = self::isHandoffPath($to);
        }

        // เส้น handoff จาก FB (magic link → /chat/continue/{h}) — ตัวตนที่แท้จริงคือเจ้าของบัญชี Thaiprompt
        // ที่ลิงก์พิสูจน์มาแล้ว ไม่ใช่ใครก็ตามที่ค้างล็อกอินอยู่ในเบราว์เซอร์นี้ (callback จะสลับบัญชีให้)
        if ($handoff) {
            $request->session()->put('thaiprompt_oauth_handoff', true);
        } else {
            $request->session()->forget('thaiprompt_oauth_handoff');
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
            return $this->fail($request, 'Thaiprompt SSO ปิดอยู่');
        }

        // CSRF — match state we put in session
        $expected = $request->session()->pull('thaiprompt_oauth_state');
        // ธง handoff ใช้ได้ครั้งเดียวต่อรอบ SSO — ดึงออกก่อนเช็ค state เพื่อไม่ให้ค้างไปรอบถัดไป
        $handoff = (bool) $request->session()->pull('thaiprompt_oauth_handoff', false);

        if (!$expected || !hash_equals($expected, (string) $request->query('state', ''))) {
            return $this->fail($request, 'state ไม่ตรง — กรุณาลองใหม่อีกครั้ง');
        }

        if ($err = $request->query('error')) {
            return $this->fail($request, 'Thaiprompt ปฏิเสธ: ' . $err);
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $this->fail($request, 'ไม่ได้รับ authorization code');
        }

        $token = $client->exchangeCode($code);
        if (!$token || empty($token['access_token'])) {
            return $this->fail($request, 'แลก token ไม่สำเร็จ');
        }

        $profile = $client->fetchUser($token['access_token']);
        if (!$profile || empty($profile['email'])) {
            return $this->fail($request, 'ดึงข้อมูลผู้ใช้จาก Thaiprompt ไม่สำเร็จ');
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

        // 🔗 (2026-09-23) ล็อกอินอยู่แล้ว (แอพส่งมาทาง mobile-start / ปุ่มในหน้าเว็บ) = ผูก Thaiprompt เข้าบัญชีนี้
        //   ห้ามหาจากอีเมลแล้วสลับคน: ลูกค้าที่สมัครด้วยเบอร์โทรมีอีเมลคนละอันกับ Thaiprompt — เดิมกดเชื่อมแล้ว
        //   ได้บัญชีจันทราใหม่อีกบัญชี ถูกสลับไปล็อกอินบัญชีนั้น บัญชีเดิมไม่ถูกผูก ผังแม่หมอจึงไม่รวมบัญชีเงา
        //   ของเขาเข้าบัญชี Thaiprompt และค่าแนะนำค้างในกระเป๋าที่ไม่มีใครเข้าได้
        $current = $request->user();
        $reservedEmail = false;

        // 🔀 เส้น handoff จาก FB + มีคนอื่นล็อกอินค้างอยู่ (เช่น remember cookie ของอีกบัญชีใน in-app browser)
        //   ห้ามผูก Thaiprompt เข้าบัญชีที่ค้างอยู่ และห้ามเด้งกลับแดชบอร์ดด้วย linkRefusal —
        //   ให้ออกจากระบบบัญชีเดิม แล้วเข้าเป็นเจ้าของบัญชี Thaiprompt (คนที่กดลิงก์จากแชทจริง ๆ) แทน
        //   "คนเดียวกัน" นับเฉพาะเมื่อบัญชีที่ค้างอยู่ผูก Thaiprompt id เดียวกันไว้แล้วเท่านั้น
        if ($current && $handoff && (string) $current->thaiprompt_user_id !== $tpId) {
            if ($tpId === '') {
                return $this->fail($request, 'ดึงข้อมูลบัญชี Thaiprompt ไม่ครบ — กรุณาลองใหม่อีกครั้ง');
            }

            $intended = $request->session()->get('url.intended');
            // 🔐 (รีวิว) บัญชีที่ค้างอยู่ยังไม่ผูก Thaiprompt = อาจเป็นคนเดียวกันที่สมัครเว็บไว้ก่อน
            //    สลับแล้วจะได้บัญชีคู่ (กระเป๋า/ประวัติดูดวงแยกกัน) → เตือนทีมพร้อมคู่ไอดีให้ตรวจ/รวมเอง
            $splitRisk = empty($current->thaiprompt_user_id) && ! User::where('thaiprompt_user_id', $tpId)->exists();
            Log::log($splitRisk ? 'warning' : 'info', 'Thaiprompt handoff switched account', [
                'from_user_id'       => $current->id,
                'thaiprompt_user_id' => $tpId,
                'split_risk'         => $splitRisk,
                'admin_action'       => $splitRisk ? 'ตรวจว่าเป็นคนเดียวกันไหม — ถ้าใช่ให้รวมบัญชี/ผูก Thaiprompt ให้บัญชีเดิม' : null,
            ]);

            // logout = ล้าง remember cookie ของบัญชีเดิมด้วย · invalidate = ไม่ให้ข้อมูลเซสชันของบัญชีเดิมติดไป
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($intended) {
                $request->session()->put('url.intended', $intended);
            }
            $current = null;
        }

        if ($current) {
            $refusal = $this->linkRefusal($current, $tpId);
            if ($refusal !== null) {
                Log::info('Thaiprompt link refused', ['user_id' => $current->id, 'thaiprompt_user_id' => $tpId]);

                return $this->fail($request, $refusal);
            }
            $user = $current;
        } else {
            // บัญชีที่ผูก Thaiprompt คนนี้ไว้แล้วมาก่อน — อีเมลซ้ำกับอีกบัญชีต้องไม่พาไปล็อกอินผิดคน
            $user = $tpId !== '' ? User::where('thaiprompt_user_id', $tpId)->first() : null;

            // 🔒 อีเมลสังเคราะห์ (@thaiprompt.local เช่น fb_{PSID}@thaiprompt.local) ห้ามใช้จับคู่บัญชีเด็ดขาด —
            //   PSID อ่านได้จากลิงก์ที่ถูกส่งต่อ ใครจองอีเมลนี้ไว้ก่อนจะได้บัญชีของลูกค้าตัวจริงไป
            //   จับคู่ได้เฉพาะด้วย thaiprompt_user_id เท่านั้น
            $reservedEmail = NotReservedEmailDomain::isReserved($email);
            if (! $user && $reservedEmail && $tpId === '') {
                return $this->fail($request, 'ดึงข้อมูลบัญชี Thaiprompt ไม่ครบ — กรุณาลองใหม่อีกครั้ง');
            }
            if (! $user && ! $reservedEmail) {
                $user = User::where('email', $email)->first();
            }
        }
        // 🔐 (รีวิว) บัญชีทีมงาน (admin/editor เข้าแผงหลังบ้านได้) ห้ามล็อกอินอัตโนมัติผ่าน SSO/ลิงก์จากแชท
        //    ลิงก์ handoff ถูกส่งต่อ/ดักได้ = ยึดสิทธิ์หลังบ้านได้ → ต้องเข้าด้วยรหัสผ่านที่หน้า /login เท่านั้น
        //    (ผูกบัญชีที่ล็อกอินอยู่เองแล้วยังทำได้ — ไม่ใช่การล็อกอินอัตโนมัติ)
        if (! $current && $user && $user->exists && in_array($user->role, ['admin', 'editor'], true)) {
            Log::warning('Thaiprompt SSO refused for staff account', [
                'user_id'            => $user->id,
                'thaiprompt_user_id' => $tpId,
                'handoff'            => $handoff,
            ]);

            return $this->fail($request, 'บัญชีทีมงานต้องเข้าสู่ระบบด้วยอีเมลและรหัสผ่านเท่านั้น');
        }

        if (!$user) {
            $user = new User();
            $user->email = $email;
            // อีเมลสังเคราะห์ชนกับแถวที่มีอยู่ (คนจองไว้ก่อนมีด่านกันโดเมนสงวน) = ไม่แตะแถวนั้น
            //   สร้างอีเมลภายในใหม่ให้เจ้าของตัวจริงแทน กันชน unique
            if ($reservedEmail && User::where('email', $email)->exists()) {
                Log::warning('Thaiprompt SSO reserved email collision', ['thaiprompt_user_id' => $tpId]);
                $user->email = 'tp' . preg_replace('/[^A-Za-z0-9]/', '', $tpId) . '-' . Str::lower(Str::random(8)) . '@thaiprompt.local';
            }
            $user->password = Hash::make(Str::random(40));
            // ห้ามตั้ง role เอง — คอลัมน์เป็น enum('admin','editor','member')
            // (2026_05_07_000003_add_role_to_users.php) และ default = 'member'
            // อยู่แล้ว ค่าเดิมที่เคยเขียนไว้คือ 'user' ซึ่ง **ไม่มีในเอนัม**
            // MySQL โหมด strict (config/database.php: 'strict' => true) จึงตีกลับ
            // ตอน save() → ผู้ใช้ใหม่ทุกคนที่เข้ามาทาง SSO สมัครไม่ผ่านเลย
            // ส่วนคนเก่าไม่เจอ เพราะ query ด้านบนหาเจอแล้วไม่แตะ role
        }

        // ผูกเข้าบัญชีที่ล็อกอินอยู่ = ชื่อและช่องทางสมัครเป็นของลูกค้าเอง ไม่เอาของ Thaiprompt มาทับ
        if (! $current) {
            $user->name = $name;
        }
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
        if ($signup !== '' && ! $current) {
            $user->signup_via = $signup;
        } elseif (!$user->signup_via) {
            $user->signup_via = $fbId !== '' ? 'facebook'
                              : ($lineId !== '' ? 'line' : 'thaiprompt');
        }

        // Thaiprompt ยืนยันอีเมลของบัญชีนั้นแล้ว — นับเป็นยืนยันเฉพาะเมื่อเป็นอีเมลเดียวกัน
        //   (บัญชีเบอร์โทรที่มาผูก มีอีเมลที่ระบบสร้างให้ ไม่ใช่อีเมลที่ Thaiprompt ยืนยัน)
        if (!$user->email_verified_at && strtolower((string) $user->email) === $email) {
            $user->email_verified_at = now();
        }
        $user->save();

        // ผูกเข้าบัญชีที่ล็อกอินอยู่ = ไม่ล็อกอินซ้ำ (เซสชันจากแอพตั้งใจให้สั้น — ห้ามติด remember)
        //   เส้น handoff จาก FB = ห้าม remember เช่นกัน (ลิงก์จากแชทเปิดใน in-app browser/เครื่องที่ใช้ร่วมกันได้)
        if (! $current) {
            Auth::login($user, ! $handoff);
            $request->session()->regenerate();
        }

        // Referral attribution — the จันทรา.online/r/{code} landing stored the
        // inviter's member_code in a 30-day cookie. Now that this user has a
        // live thaiprompt_token, claim it upstream so they join the inviter's
        // downline. Best-effort: a definitive upstream answer (success OR
        // rejection) clears the cookie; a network failure keeps it so the
        // next login retries.
        $referralStatus = $this->claimPendingReferral($request, $user, linkedNow: $user->wasChanged('thaiprompt_user_id'));

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
     * ปลายทางนี้คือเส้น handoff จาก FB (แชทเพจ → เว็บ คุยต่อกับแม่หมอ) หรือไม่
     */
    public static function isHandoffPath(string $to): bool
    {
        return str_starts_with($to, '/chat/continue/');
    }

    /**
     * SSO ไม่สำเร็จ — ล็อกอินอยู่ (กำลังเชื่อมบัญชี) กลับแดชบอร์ดพร้อมเหตุผล · ยังไม่ล็อกอิน ไปหน้าเข้าสู่ระบบแบบเดิม
     *
     * หน้าเข้าสู่ระบบไล่คนที่ล็อกอินอยู่ออกทันที (middleware guest) ข้อความจะหายไประหว่างทาง
     */
    private function fail(Request $request, string $message): RedirectResponse
    {
        // เดิมไม่มีร่องรอยเลย — ลูกค้าเข้าไม่ได้ซ้ำ ๆ แต่ log ว่างเปล่า ทีมจึงไม่รู้ว่าพังตรงไหน
        Log::info('Thaiprompt SSO failed', [
            'reason'    => $message,
            'user_id'   => $request->user()?->id,
            'has_state' => $request->query('state') !== null,
        ]);

        if ($request->user()) {
            $request->session()->forget('thaiprompt_oauth_origin');

            return redirect()->route('dashboard')->withErrors(['thaiprompt' => $message]);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    /**
     * ผูก Thaiprompt เข้าบัญชีที่ล็อกอินอยู่ได้ไหม — null = ได้ · ข้อความ = เหตุที่ไม่ให้ผูก
     *
     * ไม่ย้ายบัญชี Thaiprompt ข้ามบัญชีจันทรา และไม่สลับไปล็อกอินบัญชีอื่นเงียบ ๆ —
     * ผังแม่หมอผูกลูกค้าจันทรากับบัญชี Thaiprompt ที่ผูกครั้งแรกไว้ถาวร ย้ายเองแล้วสองฝั่งจะไม่ตรงกัน
     */
    private function linkRefusal(User $current, string $tpId): ?string
    {
        if ($tpId === '') {
            return 'ดึงข้อมูลบัญชี Thaiprompt ไม่ครบ — กรุณาลองใหม่อีกครั้ง';
        }

        if ($current->thaiprompt_user_id && (string) $current->thaiprompt_user_id !== $tpId) {
            return 'บัญชีนี้ผูกกับบัญชี Thaiprompt อื่นอยู่แล้ว — ใช้บัญชี Thaiprompt เดิมเข้าสู่ระบบ หรือติดต่อแอดมิน';
        }

        if (User::where('thaiprompt_user_id', $tpId)->whereKeyNot($current->id)->exists()) {
            return 'บัญชี Thaiprompt นี้ผูกกับบัญชีจันทราอื่นอยู่แล้ว — ถ้าจะใช้บัญชีนั้นให้ออกจากระบบแล้วเข้าด้วย Thaiprompt หรือติดต่อแอดมินเพื่อรวมบัญชี';
        }

        return null;
    }

    /**
     * ต่อสายงานแม่หมอหลังผูก Thaiprompt — ใช้รหัสเชิญที่ค้างอยู่ (ถ้ามี)
     * Returns a flash message when the customer just joined an inviter's line, null otherwise.
     */
    private function claimPendingReferral(Request $request, User $user, bool $linkedNow = false): ?string
    {
        // โค้ดที่ผู้ใช้กรอกตอนสมัครในแอพมาก่อน (เก็บฝั่งเซิร์ฟเวอร์ ไม่ผูกเบราว์เซอร์)
        // แล้วค่อยตกไปที่คุกกี้ของเบราว์เซอร์ที่เปิดลิงก์เชิญ
        //
        // ลำดับนี้สำคัญ: คนที่กดลิงก์เชิญบนมือถือแล้วสมัครในแอพ จะไม่มีคุกกี้
        // เลย เพราะคุกกี้อยู่ในเบราว์เซอร์คนละตัวกับ webview ที่ทำ SSO
        $cookieCode = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->cookie('juntra_ref', '')) ?? '', 0, 64);
        if (! $user->pending_referral_code && $cookieCode !== '' && ! $user->maemor_member_code) {
            $user->forceFill(['pending_referral_code' => $cookieCode])->save();
        }

        // ไม่มีรหัสเชิญ = ไม่ต้องรอ Thaiprompt ตอนล็อกอิน (เข้าผังเองตอนเปิดหน้าสายงาน/ส่งบิลแรก)
        if (! $user->pending_referral_code) {
            // เพิ่งผูก Thaiprompt ครั้งนี้ → บอกผังแม่หมอหลังตอบหน้าเว็บแล้ว ให้รวมบัญชีที่ระบบสร้างให้ตอนซื้อก่อนผูก
            //   เข้าบัญชี Thaiprompt ทันที (เจ้าของสั่ง: Thaiprompt เป็นตัวหลัก) — ไม่ต้องรอบิลถัดไป
            if ($linkedNow && $user->thaiprompt_user_id) {
                dispatch(fn () => app(MaeMorAffiliate::class)->ensureMember($user))->afterResponse();
            }

            return null;
        }

        // 🌙 (2026-09-21) ผังแม่หมอผ่านตัวตนของเซิร์ฟเวอร์ — ผูก Thaiprompt ครั้งแรกเมื่อไร
        //   Thaiprompt จะจับคู่ลูกค้าคนนี้กับบัญชีที่ผูก (ถ้ายังไม่เคยเข้าผังมาก่อน) แล้วต่อสายตามรหัสเชิญ
        //   ต่อไม่ได้ = รหัสยังค้างอยู่ affiliate:sync-bills เก็บตกให้ทุกนาที (เดิมรหัสหายเมื่อ 5xx/429)
        $result = app(MaeMorAffiliate::class)->ensureMember($user);
        if ($result['status'] === 'ok' || $result['status'] === 'rejected') {
            Cookie::queue(Cookie::forget('juntra_ref'));
        }

        $data = (array) ($result['data'] ?? []);
        if (($data['enrolled_now'] ?? false) && ($data['referral']['applied'] ?? false)) {
            $sponsorName = $data['sponsor']['name'] ?? null;
            Log::info('Referral applied on Mae Mor tree', ['user_id' => $user->id]);

            return $sponsorName
                ? "🎉 เข้าร่วมสายงานของ {$sponsorName} เรียบร้อย — เริ่มสร้างทีมของคุณได้เลย"
                : '🎉 เข้าร่วมสายงานเรียบร้อย — เริ่มสร้างทีมของคุณได้เลย';
        }

        return null;
    }
}
