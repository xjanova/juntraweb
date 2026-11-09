<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Support\ChatPolicy;
use App\Support\ChatRooms;
use App\Support\ChatTakeover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🧑‍💼 (2026-11-09) แอดมินเข้าห้องผู้พยากรณ์ของลูกค้า — เห็นหน้าเดียวกับที่ลูกค้าเห็น + ปุ่มเทคบอท/คุยแทน
 *
 * เจ้าของสั่ง "หน้าที่ลูกค้าเห็น เราก็เห็นแบบเดียวกับลูกค้าได้ยิ่งดี แล้วเราจะมีปุ่มพิเศษคุยแทนบอทแม่หมอได้"
 * → ใช้วิว pages.chat.index ตัวเดียวกับลูกค้า (ฉากห้อง/ฟองข้อความเหมือนกัน) ในโหมดแอดมิน
 *   หลังบ้าน Filament (App\Filament\Pages\ChatConsole) ฝังหน้านี้ไว้ข้างรายชื่อห้อง
 *
 * ทุก route ผ่าน auth + EnsureAdmin (role admin เท่านั้น)
 */
class AdminChatController extends Controller
{
    /** ข้อความที่โชว์ในห้อง (ล่าสุด) — เท่าหน้าของลูกค้าโดยประมาณ */
    private const RENDER_LIMIT = 200;

    public function room(Request $request, ChatConversation $conversation)
    {
        $conversation->setRelation('messages', $conversation->messages()
            ->with('admin:id,name')
            ->reorder()
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RENDER_LIMIT)
            ->get()
            ->reverse()
            ->values());
        $conversation->loadMissing('user:id,name,email,phone');
        ChatTakeover::markRead($conversation);

        $room = ChatRooms::normalize($conversation->persona);
        $u    = $conversation->user;

        return view('pages.chat.index', [
            'conversation' => $conversation,
            'room'         => $room,
            'rooms'        => ChatRooms::cards(),
            'offerCards'   => ['id' => null, 'offers' => []],
            'tableOffers'  => [],
            'tableMenu'    => [],
            'menuOpen'     => false,
            'roomLock'     => null,
            'pendingBill'  => null,
            'aiSuggestions' => [],
            'gate'         => ['allowed' => true, 'reason' => null, 'code' => null],
            'channel'      => null,
            'cost'         => ChatPolicy::cost(),
            'balance'      => null,
            'dailyLimit'   => 0,
            'dailyLeft'    => 0,
            'readonly'     => false,
            'suggestions'  => [],
            'topics'       => [],
            'awaiting'     => false,
            'qa'           => null,
            'qaWrap'       => null,
            'fromReading'  => false,
            'autosend'     => null,
            'held'         => ChatTakeover::isPaused($conversation),
            'adminMode'    => [
                'me'         => $request->user()->name,
                'feedUrl'    => route('admin-chat.feed', $conversation),
                'sayUrl'     => route('admin-chat.say', $conversation),
                'takeUrl'    => route('admin-chat.take', $conversation),
                'releaseUrl' => route('admin-chat.release', $conversation),
                'state'      => ChatTakeover::state($conversation),
                'userLabel'  => 'ลูกค้า: ' . ($u?->name ?: 'ไม่ทราบชื่อ') . ($u?->email ? ' · ' . $u->email : '')
                    . ' · ห้อง' . ChatRooms::name($room) . ' · #' . $conversation->id,
            ],
        ]);
    }

    /** ทุกข้อความใหม่ของห้อง (ลูกค้า บอท แอดมิน) + สถานะห้อง — หน้าห้องของแอดมินถามทุก 3 วิ */
    public function feed(Request $request, ChatConversation $conversation): JsonResponse
    {
        $after = max(0, (int) $request->query('after', 0));
        $messages = ChatMessage::query()
            ->with('admin:id,name')
            ->where('chat_conversation_id', $conversation->id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(50)
            ->get();
        if ($messages->isNotEmpty()) {
            ChatTakeover::markRead($conversation);
        }

        return response()->json([
            'messages' => $messages->map(fn (ChatMessage $m) => ChatTakeover::payload($m, true))->values(),
            'state'    => ChatTakeover::state($conversation),
        ]);
    }

    /** แอดมินพิมพ์ = บอทหยุดทันที · as: oracle (คุยแทนผู้พยากรณ์) | admin (ป้ายแอดมิน) */
    public function say(Request $request, ChatConversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
            'as'      => 'nullable|in:oracle,admin',
        ]);

        $message = ChatTakeover::say($conversation, $request->user(), trim($data['message']), $data['as'] ?? ChatMessage::AS_ORACLE);
        $message->setRelation('admin', $request->user());

        return response()->json([
            'message' => ChatTakeover::payload($message, true),
            'state'   => ChatTakeover::state($conversation),
        ]);
    }

    /** ปุ่ม "เทคบอท" — บอทหยุดโดยแอดมินยังไม่ต้องพิมพ์ */
    public function take(Request $request, ChatConversation $conversation): JsonResponse
    {
        ChatTakeover::pause($conversation, $request->user());

        return response()->json(['state' => ChatTakeover::state($conversation)]);
    }

    /** ปุ่ม "คืนให้บอท" — บอทตอบข้อความถัดไปของลูกค้าเอง */
    public function release(ChatConversation $conversation): JsonResponse
    {
        ChatTakeover::release($conversation);

        return response()->json(['state' => ChatTakeover::state($conversation)]);
    }
}
