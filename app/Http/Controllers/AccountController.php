<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChineseZodiac;
use App\Models\Reading;
use App\Models\WalletTransaction;
use App\Models\Zodiac;
use App\Services\Wallet\WalletService;
use App\Support\ProfileAstrology;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    public function dashboard(Request $request)
    {
        $user = $request->user();
        return view('pages.account.dashboard', [
            'user'    => $user,
            'recent'  => Reading::where('user_id', $user->id)->visibleInHistory()->latest()->limit(10)->get(),
            'chats'   => ChatConversation::where('user_id', $user->id)
                ->withCount('messages')
                ->latest()
                ->limit(5)
                ->get(),
            'balance' => $this->wallet->balance($user),
            'profile' => $user->profile,
            // 🌙 (2026-11-07) เช็กอินรับดวงประจำวัน — ดวงคำนวณล้วน ไม่เรียก AI
            'checkin' => \App\Support\DailyLuck::checkinToday($user),
            'streak'  => \App\Support\DailyLuck::currentStreak($user),
            'luck'    => \App\Support\DailyLuck::reading($user),
        ]);
    }

    public function history(Request $request)
    {
        $query = Reading::where('user_id', $request->user()->id)->visibleInHistory();

        // ตัวกรองหมวด — 'tarot' ครอบทุกสเปรด (tarot_single, tarot_celtic, ...)
        // ไม่งั้นต้องมีปุ่มกรองแยกทีละสเปรดซึ่งยาวเกินหน้าจอมือถือ
        $type = (string) $request->input('type', '');
        if ($type === 'tarot') {
            $query->where('type', 'like', 'tarot%');
        } elseif (in_array($type, ['numerology', 'palmistry', 'auspicious', 'deep'], true)) {
            $query->where('type', $type);
        }

        return view('pages.account.history', [
            'user'     => $request->user(),
            'readings' => $query->latest()->paginate(20)->withQueryString(),
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
        $profile = ProfileAstrology::profileFor($user);

        return view('pages.account.astrology', [
            'user'           => $user,
            'profile'        => $profile,
            'zodiacs'        => Zodiac::orderBy('order_index')->get(),
            'chineseZodiacs' => ChineseZodiac::orderBy('order_index')->get(),
        ]);
    }

    public function astrologyUpdate(Request $request): RedirectResponse
    {
        // กติกาตรวจ + การคำนวณราศีอยู่ที่ ProfileAstrology — แอพ (PATCH /v1/account/profile) ใช้ชุดเดียวกัน
        $data = $request->validate(ProfileAstrology::rules());

        $user    = $request->user();
        $profile = ProfileAstrology::profileFor($user);

        $data = ProfileAstrology::withDerivedZodiacs($data, !empty($data['auto_zodiac']));

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
