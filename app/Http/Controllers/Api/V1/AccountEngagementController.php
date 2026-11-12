<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyCheckin;
use App\Models\TarotCard;
use App\Models\User;
use App\Support\CardCollection;
use App\Support\DailyLuck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🌙 (2026-11-12) ลูกเล่นในบัญชีสำหรับแอพ — เช็กอินรับดวงประจำวัน + สมุดสะสมไพ่
 *
 * กติกาเดียวกับหน้าเว็บทุกข้อ: เช็กอิน/วันต่อเนื่อง/ดวงเสี่ยงทาย = DailyLuck · สมุดสะสม = CardCollection
 * ไม่เรียก AI และไม่มีรางวัลเป็นเงิน (ดู DailyLuck)
 */
class AccountEngagementController extends Controller
{
    /** GET /v1/account/daily-luck — สถานะวันนี้ (ดวงโชว์เมื่อกดรับแล้วเท่านั้น เหมือนหน้า dashboard) */
    public function dailyLuck(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->luck($user, DailyLuck::checkinToday($user))]);
    }

    /** POST /v1/account/checkin — กดรับดวงวันนี้ กดซ้ำได้ ไม่นับซ้ำ (new = กดครั้งแรกของวัน) */
    public function checkin(Request $request): JsonResponse
    {
        $user = $request->user();
        $before = DailyLuck::checkinToday($user);
        $row = DailyLuck::checkIn($user);

        return response()->json(['data' => $this->luck($user, $row) + ['new' => $before === null]]);
    }

    /** GET /v1/account/collection — สมุดสะสมไพ่ เรียงตามสำรับ (id) เหมือนหน้าเว็บ */
    public function collection(Request $request): JsonResponse
    {
        $c = CardCollection::for($request->user());
        $seen = $c['seen'];
        // ใบโปรดต้องเป็นไพ่ที่ยังเปิดใช้อยู่ — หน้าเว็บก็หาใบจากสำรับที่เปิดใช้เช่นกัน
        $favCard = $c['favorite'] ? $c['cards']->firstWhere('id', $c['favorite']->tarot_card_id) : null;

        return response()->json(['data' => [
            'found'     => $c['seenCount'],
            'total'     => $c['cards']->count(),
            'favourite' => $favCard ? [
                'slug'    => $favCard->slug,
                'name_th' => $favCard->name_th,
                'count'   => (int) $c['favorite']->times,
            ] : null,
            'cards' => $c['cards']->map(function (TarotCard $card) use ($seen) {
                $s = $seen->get($card->id);

                return [
                    'slug'           => $card->slug,
                    'name_th'        => $card->name_th,
                    'name_en'        => $card->name_en,
                    // ภาพเดียวกับหน้าสมุดบนเว็บ (ไม่มีภาพหน้าไพ่ = หลังไพ่ที่แอดมินตั้ง) — URL เต็มเสมอ
                    'image_url'      => $card->imageUrl(),
                    'count'          => (int) ($s->times ?? 0),
                    'reversed_count' => (int) ($s->reversed_times ?? 0),
                    'found'          => $s !== null,
                ];
            })->values(),
        ]]);
    }

    private function luck(User $user, ?DailyCheckin $today): array
    {
        $streak = DailyLuck::currentStreak($user);

        return [
            'checked_in'     => $today !== null,
            'streak'         => $streak,
            'next_milestone' => DailyLuck::nextMilestone($streak),
            'earned'         => collect(DailyLuck::earnedMilestones($streak))
                ->map(fn (string $label, int $days) => ['days' => $days, 'label' => $label])
                ->values(),
            'reading'        => $today ? DailyLuck::reading($user) : null,
        ];
    }
}
