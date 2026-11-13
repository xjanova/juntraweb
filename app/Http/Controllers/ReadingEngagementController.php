<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use App\Models\Testimonial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 🌟 (2026-11-07) หลังจบคำทำนาย — ลูกค้ารีวิว + เปิด/ปิดลิงก์แชร์
 *
 * เจ้าของสั่ง "ให้ลูกค้ารีวิว หลังจบทำนาย"
 *   - รีวิวได้เฉพาะเจ้าของคำทำนาย และคำทำนายต้องเสร็จจริง (ไม่ใช่กำลังอ่าน/อ่านไม่สำเร็จ)
 *   - หนึ่งคำทำนาย = หนึ่งรีวิว · แก้ได้ แต่แก้แล้วต้องรอแอดมินอนุมัติใหม่ (กันแก้ข้อความหลังขึ้นหน้าแรก)
 *   - ขึ้นหน้าแรกเมื่อ "ลูกค้ายอม" + "แอดมินอนุมัติ" ทั้งคู่ (ดู Testimonial::scopeApproved)
 */
class ReadingEngagementController extends Controller
{
    public function review(Request $request, Reading $reading): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $review = \App\Support\ReadingEngagement::review($request, $reading);

        // 🌙 การ์ดปิดท้ายในห้องแชทส่งแบบ fetch — ตอบ JSON ให้ขึ้นขอบคุณในห้องเดิม ไม่พาออกไปหน้าอื่น
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'rating' => (int) $review->rating]);
        }

        return redirect()->to(route('tarot.show', $reading).'#review')
            ->with('review_saved', true);
    }

    /** เปิด/ปิดให้คนที่มีลิงก์ดูคำทำนายนี้ได้ (ค่าเริ่มต้นปิด — เป็นของส่วนตัว) */
    public function share(Request $request, Reading $reading): RedirectResponse
    {
        \App\Support\ReadingEngagement::ownFinished($request, $reading);

        $public = $request->boolean('public');
        \App\Support\ReadingEngagement::share($reading, $public);

        return redirect()->to(route('tarot.show', $reading).'#share')
            ->with('share_saved', $public ? 'on' : 'off');
    }

}
