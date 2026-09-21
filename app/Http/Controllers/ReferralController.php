<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * Affiliate invite landing — `/r/{code}`.
 *
 * The Juntra app's affiliate screen shares `จันทรา.online/r/<code>`. Previously
 * that 404'd (no route). This captures the referral code in a 30-day cookie and
 * lands the visitor on the homepage so the link works.
 *
 * รหัสในคุกกี้ถูกย้ายไปเก็บฝั่งเซิร์ฟเวอร์ (users.pending_referral_code) ตอนสมัคร/ผูก Thaiprompt
 * แล้วผังแม่หมอเป็นผู้ต่อสายใต้ผู้เชิญ (MaeMorAffiliate::ensureMember) — ค่าแนะนำคำนวณที่นั่นทั้งหมด
 */
class ReferralController extends Controller
{
    public function show(string $code): RedirectResponse
    {
        // Codes are alphanumeric/dash/underscore; route constraint already
        // guards this, but sanitize defensively before persisting.
        $code = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $code), 0, 64);

        return redirect()->route('home')
            ->with('status', 'คุณเข้ามาผ่านลิงก์เชิญเพื่อน ✨ สมัครสมาชิกเพื่อรับสิทธิ์พิเศษได้เลย')
            ->withCookie(Cookie::make('juntra_ref', $code, 60 * 24 * 30)); // 30 days
    }
}
