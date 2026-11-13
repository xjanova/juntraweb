<?php

namespace App\Support;

use App\Models\Reading;
use App\Models\Testimonial;
use Illuminate\Http\Request;

final class ReadingEngagement
{
    public static function review(Request $request, Reading $reading): Testimonial
    {
        self::ownFinished($request, $reading);

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

        return Testimonial::updateOrCreate(['reading_id' => $reading->id], [
            'user_id' => $request->user()->id,
            'name' => Testimonial::displayNameFor($request->user()),
            'service' => mb_substr((string) ($spread['name_th'] ?? 'ไพ่ยิปซี'), 0, 96),
            'rating' => (int) $data['rating'],
            'message' => trim(strip_tags((string) ($data['message'] ?? ''))),
            'allow_public' => (bool) ($data['allow_public'] ?? false),
            'approved' => false,
        ]);

    }

    public static function share(Reading $reading, bool $public): void
    {
        $reading->forceFill(['shared_public' => $public])->save();
    }

    public static function ownFinished(Request $request, Reading $reading): void
    {
        abort_unless(TarotSpreads::isTarotType($reading->type), 404);
        abort_unless($reading->user_id === $request->user()->id, 403);
        // ยังอ่านไม่เสร็จ/อ่านไม่สำเร็จ = ไม่มีอะไรให้รีวิวหรือแชร์
        abort_if($reading->isInProgress() || $reading->isFailed() || blank($reading->result), 422, 'คำทำนายนี้ยังไม่เสร็จค่ะ');
    }
}
