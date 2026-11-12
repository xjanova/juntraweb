<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * ⭐ (2026-11-12) GET /v1/testimonials — รีวิวชุดเดียวกับหน้าแรกของเว็บ (Testimonial::onHomePage)
 *
 * ขึ้นได้เฉพาะรีวิวที่แอดมินอนุมัติ และถ้าลูกค้าเขียนเองต้องติ๊กยอมให้โชว์ด้วย (scopeApproved)
 * ส่งออกแค่ชื่อที่ตัดแล้ว/บริการ/ดาว/ข้อความ — ไม่มี user_id อีเมล หรือเลขคำทำนาย
 */
class TestimonialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->only('limit'), [
            'limit' => ['nullable', 'integer', 'min:1', 'max:12'],
        ], [
            'limit.integer' => 'จำนวนรีวิวต้องเป็นตัวเลข',
            'limit.min'     => 'ขอรีวิวได้อย่างน้อย :min รายการ',
            'limit.max'     => 'ขอรีวิวได้ครั้งละไม่เกิน :max รายการ',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message'     => $validator->errors()->first(),
                'reason_code' => 'validation_failed',
                'errors'      => $validator->errors(),
            ], 422);
        }

        $limit = (int) ($validator->validated()['limit'] ?? 3);

        $data = Testimonial::onHomePage()->limit($limit)->get()
            ->map(fn (Testimonial $t) => [
                'name'    => $t->name,
                'service' => $t->service,
                'rating'  => (int) $t->rating,
                'message' => $t->message,
            ])->values();

        return response()->json(['data' => $data])->header('Cache-Control', 'public, max-age=300');
    }
}
