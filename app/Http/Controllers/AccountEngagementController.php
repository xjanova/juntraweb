<?php

namespace App\Http\Controllers;

use App\Models\TarotCard;
use App\Support\CardCollection;
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

        // นับด้วยกติกาเดียวกับแอพ (GET /v1/account/collection) — ดู CardCollection
        $collection = CardCollection::for($user);
        $cards = $collection['cards'];
        $groups = $cards->groupBy(fn (TarotCard $c) => $c->arcana === 'major' ? 'major' : ($c->suit ?: 'other'));

        return view('pages.account.collection', [
            'user' => $user,
            'cards' => $cards,
            'groups' => $groups,
            'seen' => $collection['seen'],
            'seenCount' => $collection['seenCount'],
            'favorite' => $collection['favorite'],
        ]);
    }
}
