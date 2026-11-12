<?php

namespace App\Support;

use App\Models\Reading;
use App\Models\TarotCard;
use App\Models\TarotReadingCard;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 🃏 (2026-11-12) สมุดสะสมไพ่ของลูกค้า — ชุดเดียวทั้งหน้าเว็บ /account/collection และแอพ (GET /v1/account/collection)
 *
 * ใบที่ "เจอแล้ว" = เคยเปิดได้จากคำทำนายของตัวเอง ไม่นับรายการที่อ่านไม่สำเร็จ (Reading::visibleInHistory)
 * ย้ายออกมาจาก AccountEngagementController ให้แอพนับด้วยกติกาเดียวกับเว็บ ไม่ใช่สำเนาที่สอง
 */
final class CardCollection
{
    /**
     * @return array{
     *     cards: Collection<int, TarotCard>,
     *     seen: Collection<int, TarotReadingCard>,
     *     seenCount: int,
     *     favorite: ?TarotReadingCard
     * }
     *   cards = ไพ่ที่เปิดใช้อยู่ทั้งสำรับ เรียงตาม id · seen = แถวรวมต่อใบ (times, reversed_times, last_at)
     *   คีย์ด้วย tarot_card_id · favorite = แถวของใบที่เปิดได้บ่อยที่สุด (null = ยังไม่เคยเปิด)
     */
    public static function for(User $user): array
    {
        $seen = TarotReadingCard::query()
            ->whereIn('reading_id', Reading::where('user_id', $user->id)->visibleInHistory()->select('id'))
            ->selectRaw('tarot_card_id, COUNT(*) as times, SUM(CASE WHEN reversed THEN 1 ELSE 0 END) as reversed_times, MAX(created_at) as last_at')
            ->groupBy('tarot_card_id')
            ->get()
            ->keyBy('tarot_card_id');

        $cards = TarotCard::where('active', true)->orderBy('id')->get();

        return [
            'cards' => $cards,
            'seen' => $seen,
            'seenCount' => $cards->filter(fn ($c) => $seen->has($c->id))->count(),
            'favorite' => $seen->sortByDesc('times')->first(),
        ];
    }
}
