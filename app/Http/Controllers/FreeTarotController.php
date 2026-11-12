<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use App\Support\FreeReadingPolicy;
use Illuminate\Http\Request;

/**
 * 🎁 (2026-07-26) ดูดวงฟรี 1 ใบ — ด่านแรกของลูกค้าที่บอทเฟซบุ๊กส่งมา
 *
 * ทำไมแยกคอนโทรลเลอร์ ไม่ยัดใน TarotController:
 *   flow ต่างกันคนละเรื่อง — ไม่มีขั้นเลือกสเปรด ไม่มีขั้นกางไพ่ ไม่มีคำถาม
 *   ไม่มีการหักเครดิต/คืนเครดิต และมีกติกาโควตาของตัวเอง
 *   (createReading ของตัวเดิมผูกกับ debit/refund/guardCharge ทั้งดุ้น)
 *
 * ทำไม GET ไม่ทำนายเลยทั้งที่อยากให้ "เปิดหน้าปุ๊บได้เลย":
 *   GET ที่มี side effect (สร้าง reading + ยิง AI) โดนรีเฟรช/prefetch ยิงซ้ำได้
 *   จึงให้ GET เรนเดอร์หน้ารอ แล้วให้หน้าเว็บยิง POST ให้เองครั้งเดียว (PRG)
 *   ลูกค้ายังรู้สึกว่า "เปิดปุ๊บแม่หมอเปิดไพ่ให้เลย" เหมือนเดิม
 *
 * กติกาสำคัญ: **AI ล้ม = คืนสิทธิ์ฟรี** ไม่ใช่เผาทิ้ง (ลูกค้ามีโอกาสเดียว)
 */
class FreeTarotController extends Controller
{
    /**
     * GET /tarot/free — หน้ารอ + สั่งเปิดไพ่ให้อัตโนมัติ
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $gate = FreeReadingPolicy::gate($user);

        // เคยรับไปแล้วและมีผลอยู่ → กลับห้องแม่หมอที่มีคำทำนายใบนั้น (🎁 2026-11-09 ไม่ไปหน้าผลไพ่แล้ว)
        if ($gate['code'] === 'used' && ! empty($gate['reading_id'])) {
            return redirect()->route('chat.from-free', $gate['reading_id']);
        }

        return view('pages.tarot.free', [
            'gate' => $gate,
            // ลูกค้าที่หลุด SSO มาแบบยังไม่ล็อกอิน — ให้กดกลับเข้าระบบแล้วเด้งมาที่นี่
            'loginUrl' => route('thaiprompt.redirect', ['to' => '/tarot/free']),
        ]);
    }

    /**
     * POST /tarot/free/cast — เปิดไพ่ 1 ใบ + ขอคำทำนายสั้นจากคลังความรู้แม่หมอ
     */
    public function cast(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('tarot.free');
        }

        $outcome = app(\App\Services\Tarot\FreeTarotReading::class)->cast($user, $request->ip());
        if (isset($outcome['reading'])) {
            return redirect()->route('chat.from-free', $outcome['reading']);
        }
        $response = redirect()->route(($outcome['code'] ?? '') === 'deck_unavailable' ? 'tarot.index' : 'tarot.free');
        if (! empty($outcome['retry'])) {
            $response->with('free_retry', true);
        }
        if (! empty($outcome['message'])) {
            $response->with('status', $outcome['message']);
        }
        return $response;
    }
}
