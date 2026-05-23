<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChineseZodiac;
use App\Models\Reading;
use App\Models\WalletTransaction;
use App\Models\Zodiac;
use App\Services\Wallet\WalletService;
use App\Support\Astrology;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    public function dashboard(Request $request)
    {
        $user = $request->user();
        return view('pages.account.dashboard', [
            'user'    => $user,
            'recent'  => Reading::where('user_id', $user->id)->latest()->limit(10)->get(),
            'chats'   => ChatConversation::where('user_id', $user->id)
                ->withCount('messages')
                ->latest()
                ->limit(5)
                ->get(),
            'balance' => $this->wallet->balance($user),
            'profile' => $user->profile,
        ]);
    }

    public function history(Request $request)
    {
        return view('pages.account.history', [
            'user'     => $request->user(),
            'readings' => Reading::where('user_id', $request->user()->id)
                ->latest()
                ->paginate(20),
        ]);
    }

    public function chats(Request $request)
    {
        return view('pages.account.chats', [
            'user' => $request->user(),
            'conversations' => ChatConversation::where('user_id', $request->user()->id)
                ->withCount('messages')
                ->latest()
                ->paginate(20),
        ]);
    }

    /** Astrology profile editor — DOB / time / place / zodiacs / contact. */
    public function astrology(Request $request)
    {
        $user = $request->user();
        $profile = $user->profile ?? $user->profile()->create([]);

        return view('pages.account.astrology', [
            'user'           => $user,
            'profile'        => $profile,
            'zodiacs'        => Zodiac::orderBy('order_index')->get(),
            'chineseZodiacs' => ChineseZodiac::orderBy('order_index')->get(),
        ]);
    }

    public function astrologyUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'birth_date'          => ['nullable', 'date', 'before:tomorrow', 'after:1900-01-01'],
            'birth_time'          => ['nullable', 'date_format:H:i'],
            'birth_place'         => ['nullable', 'string', 'max:191'],
            'zodiac_slug'         => ['nullable', 'string', 'exists:zodiacs,slug'],
            'chinese_zodiac_slug' => ['nullable', 'string', 'exists:chinese_zodiacs,slug'],
            'phone'               => ['nullable', 'string', 'max:32'],
            'line_id'             => ['nullable', 'string', 'max:64'],
            'subscribed'          => ['nullable', 'boolean'],
            'auto_zodiac'         => ['nullable', 'boolean'],
        ]);

        $user    = $request->user();
        $profile = $user->profile ?? $user->profile()->create([]);

        // Auto-derive zodiacs from birth_date when user ticked "ใช้อัตโนมัติ"
        // (or when fields are blank but DOB exists). Manual override wins
        // when the user picked a slug explicitly.
        if (!empty($data['birth_date'])) {
            $birth = Carbon::parse($data['birth_date']);
            $autoDerive = !empty($data['auto_zodiac']);
            if ($autoDerive || empty($data['zodiac_slug'])) {
                $data['zodiac_slug'] = Astrology::westernZodiacSlug($birth);
            }
            if ($autoDerive || empty($data['chinese_zodiac_slug'])) {
                $data['chinese_zodiac_slug'] = Astrology::chineseZodiacSlug($birth->year);
            }
        }

        unset($data['auto_zodiac']);
        $data['subscribed'] = $request->boolean('subscribed');

        $profile->fill($data)->save();

        return redirect()->route('account.astrology')
            ->with('status', 'บันทึกข้อมูลโหราศาสตร์เรียบร้อย');
    }

    /** Security: list browser session + Sanctum personal access tokens. */
    public function security(Request $request)
    {
        $user = $request->user();

        return view('pages.account.security', [
            'user'   => $user,
            // Mobile app tokens (Sanctum) — name/last_used_at/created_at/abilities
            'tokens' => $user->tokens()->latest('created_at')->get(),
            // Current browser session id so we can highlight "this device"
            'currentSession' => $request->session()->getId(),
        ]);
    }

    public function revokeToken(Request $request, int $tokenId): RedirectResponse
    {
        $user = $request->user();
        $deleted = $user->tokens()->where('id', $tokenId)->delete();

        return redirect()->route('account.security')
            ->with('status', $deleted
                ? 'ยกเลิกอุปกรณ์เรียบร้อย — เซสชันมือถือนั้นเข้าใช้งานไม่ได้แล้ว'
                : 'ไม่พบอุปกรณ์ดังกล่าว');
    }

    public function revokeAllTokens(Request $request): RedirectResponse
    {
        $count = $request->user()->tokens()->count();
        $request->user()->tokens()->delete();

        return redirect()->route('account.security')
            ->with('status', "ออกจากระบบทุกอุปกรณ์มือถือเรียบร้อย — เพิกถอนแล้ว {$count} เซสชัน");
    }

    public function logoutOtherBrowsers(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ], [], ['password' => 'รหัสผ่าน']);

        Auth::logoutOtherDevices($request->input('password'));

        return redirect()->route('account.security')
            ->with('status', 'ออกจากระบบเบราว์เซอร์อื่นทั้งหมดเรียบร้อย');
    }
}
