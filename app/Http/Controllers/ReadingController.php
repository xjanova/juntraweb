<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use App\Services\Numerology;
use Illuminate\Http\Request;

/**
 * ประตูเดียวสำหรับ "เปิดดูผลย้อนหลัง" ของทุกบริการ
 *
 * 🔴 (2026-07-26) ก่อนหน้านี้หน้าประวัติมีปุ่ม "ดูผล" **เฉพาะไพ่ยิปซี** —
 * เลขศาสตร์ / ลายมือ / ฤกษ์ยาม / ดูดวงเชิงลึก ถูกบันทึกลง readings ครบทุกครั้ง
 * แต่ไม่มีเส้นทางไหนเปิดอ่านได้เลย ลูกค้าจ่ายเงินแล้วเห็นผลได้ครั้งเดียวตอนซื้อ
 * ปิดแท็บ = หายถาวร (ทั้งที่หน้าประวัติเขียนว่า "ย้อนกลับไปอ่านได้ตลอด")
 *
 * ตอนนี้ทุกบริการ redirect มาที่นี่หลังซื้อเสร็จด้วย → หน้าที่เห็นตอนซื้อกับตอน
 * ย้อนดูเป็นหน้าเดียวกันเป๊ะ ไม่มีทางที่หน้าใดหน้าหนึ่งจะพังโดยไม่มีใครรู้
 */
class ReadingController extends Controller
{
    public function show(Request $request, Reading $reading, Numerology $numerology)
    {
        $this->authorizeView($request, $reading);

        // ไพ่ยิปซีมีหน้าผลของตัวเองที่ผูกกับ relation ไพ่อยู่แล้ว — ส่งต่อ ไม่ทำซ้ำ
        if (str_starts_with($reading->type, 'tarot')) {
            return redirect()->route('tarot.show', $reading);
        }

        return match ($reading->type) {
            'numerology' => view('pages.numerology.result', [
                'reading' => $reading,
                // เลขศาสตร์เป็นคณิตล้วนและ deterministic — คำนวณใหม่จาก payload
                // ได้ผลเท่าเดิมเป๊ะ จึงไม่ต้องเก็บ result ก้อนใหญ่ซ้ำใน DB
                'result'  => $numerology->analyze(
                    (string) ($reading->payload['name'] ?? $reading->question ?? ''),
                    (string) ($reading->payload['birth_date'] ?? ''),
                ),
                'replay'  => true,
            ]),

            'palmistry' => view('pages.palmistry.result', [
                'reading'   => $reading,
                'image_url' => ! empty($reading->payload['image_path'])
                    ? asset('storage/'.$reading->payload['image_path'])
                    : null,
                'replay'    => true,
            ]),

            'auspicious' => view('pages.auspicious.result', [
                'reading' => $reading,
                'replay'  => true,
            ]),

            'deep' => view('pages.deep.result', [
                'reading' => $reading,
                'replay'  => true,
            ]),

            default => abort(404),
        };
    }

    /**
     * เจ้าของ / แอดมิน เท่านั้น (หรือรายการที่ถูกตั้งเป็นสาธารณะ)
     *
     * ใช้ 404 กับคนอื่น ไม่ใช่ 403 — 403 ยืนยันกลาย ๆ ว่า id นี้มีอยู่จริง
     * ทำให้ไล่เดา id เพื่อดูว่าใครดูดวงอะไรไว้บ้างได้
     */
    private function authorizeView(Request $request, Reading $reading): void
    {
        if ($reading->shared_public) {
            return;
        }

        $user = $request->user();
        abort_if(! $user, 404);

        $isOwner = $reading->user_id === $user->id;
        $isAdmin = method_exists($user, 'isAdmin') && $user->isAdmin();
        abort_unless($isOwner || $isAdmin, 404);
    }
}
