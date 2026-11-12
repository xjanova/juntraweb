<?php

namespace App\Http\Controllers;

use App\Support\BusabaTour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * 🌸 (2026-11-12) จำว่าเลือกแล้ว — "ดูดวงฟรีเลย" / "ให้บุษบาพาทัวร์" / ปิดหน้าเลือก / ทัวร์จบหรือข้าม
 *
 * ทุกทางจบที่เดียวกัน: cookie ปีนึง (เครื่องนี้) + tour_seen_at (บัญชีนี้ ถ้าล็อกอิน) → หน้าเลือกไม่ขึ้นอีก
 * เรียกซ้ำได้ไม่มีผลเพิ่ม (เวลาเดิมไม่ถูกเขียนทับ) · ไม่ต้องล็อกอิน (คนที่ยังไม่ล็อกอินก็เลือกได้)
 */
class BusabaTourController extends Controller
{
    private const CHOICES = ['free', 'tour', 'later', 'done', 'skip'];

    public function seen(Request $request): JsonResponse
    {
        $choice = (string) $request->input('choice', 'later');
        $choice = in_array($choice, self::CHOICES, true) ? $choice : 'later';

        BusabaTour::markSeen($request->user());

        return response()
            ->json(['ok' => true, 'choice' => $choice])
            // httpOnly=false: หน้าเว็บอ่านซ้ำได้ (ทัวร์ใช้เช็กก่อนเด้งหน้าเลือกในแท็บอื่น)
            ->withCookie(Cookie::make(BusabaTour::cookie(), $choice, 60 * 24 * 365, '/', null, null, false, false, 'lax'));
    }
}
