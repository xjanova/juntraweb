<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use App\Models\Testimonial;
use App\Support\TarotSpreads;
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
    public function review(Request $request, Reading $reading): RedirectResponse
    {
        $this->ownFinished($request, $reading);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'message' => ['nullable', 'string', 'max:500'],
            'allow_public' => ['nullable', 'boolean'],
        ], [
            'rating.required' => 'เลือกจำนวนดาวก่อนนะคะ',
            'rating.between' => 'ให้ดาวได้ 1 ถึง 5 ดวงค่ะ',
            'message.max' => 'รีวิวยาวได้ไม่เกิน 500 ตัวอักษรค่ะ',
        ]);

        $spread = TarotSpreads::get((string) TarotSpreads::keyFromType($reading->type));

        Testimonial::updateOrCreate(['reading_id' => $reading->id], [
            'user_id' => $request->user()->id,
            'name' => Testimonial::displayNameFor($request->user()),
            'service' => mb_substr((string) ($spread['name_th'] ?? 'ไพ่ยิปซี'), 0, 96),
            'rating' => (int) $data['rating'],
            'message' => trim(strip_tags((string) ($data['message'] ?? ''))),
            'allow_public' => (bool) ($data['allow_public'] ?? false),
            'approved' => false,
        ]);

        return redirect()->to(route('tarot.show', $reading).'#review')
            ->with('review_saved', true);
    }

    /** เปิด/ปิดให้คนที่มีลิงก์ดูคำทำนายนี้ได้ (ค่าเริ่มต้นปิด — เป็นของส่วนตัว) */
    public function share(Request $request, Reading $reading): RedirectResponse
    {
        $this->ownFinished($request, $reading);

        $public = $request->boolean('public');
        $reading->forceFill(['shared_public' => $public])->save();

        return redirect()->to(route('tarot.show', $reading).'#share')
            ->with('share_saved', $public ? 'on' : 'off');
    }

    private function ownFinished(Request $request, Reading $reading): void
    {
        abort_unless(TarotSpreads::isTarotType($reading->type), 404);
        abort_unless($reading->user_id === $request->user()->id, 403);
        // ยังอ่านไม่เสร็จ/อ่านไม่สำเร็จ = ไม่มีอะไรให้รีวิวหรือแชร์
        abort_if($reading->isInProgress() || $reading->isFailed(), 422, 'คำทำนายนี้ยังไม่เสร็จค่ะ');
    }
}
