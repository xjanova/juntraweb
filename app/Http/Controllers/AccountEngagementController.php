<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use App\Models\TarotCard;
use App\Models\TarotReadingCard;
use App\Support\DailyLuck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 🌙 (2026-11-07) ลูกเล่นในบัญชี — เช็กอินรายวัน + สมุดสะสมไพ่
 *
 * ทั้งสองอย่างไม่เรียก AI และไม่ให้รางวัลเป็นเงิน (ดู DailyLuck)
 */
class AccountEngagementController extends Controller
{
    /** กดรับดวงวันนี้ — กดซ้ำได้ ไม่นับซ้ำ */
    public function checkin(Request $request): RedirectResponse
    {
        $before = DailyLuck::checkinToday($request->user());
        $row = DailyLuck::checkIn($request->user());

        return redirect()->to(route('account.dashboard').'#daily-luck')
            ->with('checkin_new', $before === null ? (int) $row->streak : null);
    }

    /** สมุดสะสมไพ่ 78 ใบ — ใบที่เคยเปิดได้จากคำทำนายของตัวเอง (ไม่นับรายการที่อ่านไม่สำเร็จ) */
    public function collection(Request $request)
    {
        $user = $request->user();

        $seen = TarotReadingCard::query()
            ->whereIn('reading_id', Reading::where('user_id', $user->id)->visibleInHistory()->select('id'))
            ->selectRaw('tarot_card_id, COUNT(*) as times, SUM(CASE WHEN reversed THEN 1 ELSE 0 END) as reversed_times, MAX(created_at) as last_at')
            ->groupBy('tarot_card_id')
            ->get()
            ->keyBy('tarot_card_id');

        $cards = TarotCard::where('active', true)->orderBy('id')->get();
        $groups = $cards->groupBy(fn (TarotCard $c) => $c->arcana === 'major' ? 'major' : ($c->suit ?: 'other'));

        return view('pages.account.collection', [
            'user' => $user,
            'cards' => $cards,
            'groups' => $groups,
            'seen' => $seen,
            'seenCount' => $cards->filter(fn ($c) => $seen->has($c->id))->count(),
            'favorite' => $seen->sortByDesc('times')->first(),
        ]);
    }
}
