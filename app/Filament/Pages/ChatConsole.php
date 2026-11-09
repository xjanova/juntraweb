<?php

namespace App\Filament\Pages;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Support\ChatRooms;
use App\Support\ChatTakeover;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Str;

/**
 * 🧑‍💼 (2026-11-09) แชทผู้พยากรณ์ — แอดมินคุยกับลูกค้าได้ทุกห้อง (แม่หมอ · บุษบา · คุณไส)
 *
 * เจ้าของสั่ง (ด่วน): "ระบบแชทของแอดมิน สำหรับแม่หมอแต่ละห้อง ไว้หลังบ้าน ตอบโต้กับผู้ใช้ได้ทุกห้อง
 *   เวลาแอดมินพูดแชท บอทแม่หมอต้องหยุดก่อน และมีปุ่มเทคบอทเช่นกัน"
 *   + "หน้าที่ลูกค้าเห็น เราก็เห็นแบบเดียวกับลูกค้าได้ยิ่งดี แล้วเราจะมีปุ่มพิเศษคุยแทนบอทแม่หมอได้"
 *
 * ซ้าย = รายชื่อห้อง (ลูกค้าเปิดห้องอยู่ · ยังไม่อ่าน · แอดมินคุมอยู่) รีเฟรชเองทุก 5 วิ
 * ขวา  = หน้าห้องแบบเดียวกับที่ลูกค้าเห็น (AdminChatController::room) พร้อมปุ่มเทคบอท/คืนบอท/คุยแทน
 */
class ChatConsole extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'แชทผู้พยากรณ์ (สด)';

    protected static ?string $title = 'แชทผู้พยากรณ์ — แอดมินคุยกับลูกค้า';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.chat-console';

    /** ห้องที่เปิดดูอยู่ */
    public ?int $openId = null;

    /** กรองห้องผู้พยากรณ์ ('' = ทุกห้อง) */
    public string $room = '';

    /** all | unread | held | live */
    public string $show = 'all';

    public string $q = '';

    /** เงียบกี่นาทีคืนบอทเอง (0 = ไม่คืนเอง) */
    public int $idle = ChatTakeover::DEFAULT_IDLE_MINUTES;

    /** เต็มความกว้างจอ — ห้องของลูกค้าต้องกว้างพอให้เห็นแบบเดียวกับที่ลูกค้าเห็นบนจอคอม */
    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    /** ตัวเลขบนเมนู = ห้องที่ลูกค้าพิมพ์มาแต่แอดมินยังไม่อ่าน ระหว่างที่แอดมินคุมห้องอยู่ (รอคนตอบจริง) */
    public static function getNavigationBadge(): ?string
    {
        $n = ChatConversation::query()
            ->whereNotNull('bot_paused_at')
            ->whereHas('messages', fn ($q) => $q->where('role', 'user')
                ->whereRaw('chat_messages.created_at > COALESCE(chat_conversations.admin_read_at, chat_conversations.bot_paused_at)'))
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        $this->idle = ChatTakeover::idleMinutes();
        $open = request()->integer('c');
        if ($open > 0 && ChatConversation::whereKey($open)->exists()) {
            $this->openId = $open;
        }
    }

    public function open(int $id): void
    {
        $this->guard();
        $this->openId = ChatConversation::whereKey($id)->exists() ? $id : null;
    }

    public function closeRoom(): void
    {
        $this->openId = null;
    }

    public function updatedIdle(): void
    {
        $this->guard();
        $choice = in_array((int) $this->idle, ChatTakeover::IDLE_CHOICES, true) ? (int) $this->idle : ChatTakeover::DEFAULT_IDLE_MINUTES;
        ChatTakeover::setIdleMinutes($choice);
        $this->idle = $choice;
    }

    /** @return list<array> */
    public function getRowsProperty(): array
    {
        $recent = now()->subDays(7);
        $query = ChatConversation::query()
            ->with('user:id,name,email')
            ->whereHas('messages', fn ($q) => $q->where('role', 'user'))
            ->withMax('messages as last_at', 'created_at')
            ->withCount(['messages as unread' => fn ($q) => $q->where('role', 'user')
                ->where('chat_messages.created_at', '>=', $recent)
                ->whereRaw('chat_messages.created_at > COALESCE(chat_conversations.admin_read_at, ?)', [$recent])])
            ->addSelect([
                'last_text' => ChatMessage::select('content')
                    ->whereColumn('chat_conversation_id', 'chat_conversations.id')
                    ->latest('id')->limit(1),
                'last_role' => ChatMessage::select('role')
                    ->whereColumn('chat_conversation_id', 'chat_conversations.id')
                    ->latest('id')->limit(1),
            ]);

        if ($this->room !== '' && ChatRooms::get($this->room) !== null) {
            $query->where('persona', $this->room);
        }
        if ($this->show === 'held') {
            $query->whereNotNull('bot_paused_at');
        }
        $term = trim($this->q);
        if ($term !== '') {
            $query->where(function ($w) use ($term) {
                $w->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"));
                if (ctype_digit($term)) {
                    $w->orWhere('chat_conversations.id', (int) $term);
                }
            });
        }

        // แอดมินเงียบเกินเวลาที่ตั้ง = บอทกลับมาแล้ว (ChatTakeover คืนให้ตอนลูกค้าส่งข้อความถัดไป) — ป้ายต้องตรงความจริง
        $idle = ChatTakeover::idleMinutes();
        $heldSince = $idle > 0 ? now()->subMinutes($idle) : null;

        $rows = $query->orderByDesc('last_at')->limit(80)->get()->map(function (ChatConversation $c) use ($heldSince) {
            return [
                'id'      => $c->id,
                'user'    => $c->user?->name ?: ('ผู้ใช้ #' . ($c->user_id ?? '-')),
                'email'   => $c->user?->email,
                'room'    => ChatRooms::short((string) $c->persona),
                'persona' => (string) $c->persona,
                'reading' => $c->reading_id !== null,
                'last'    => Str::limit(preg_replace('/\s+/u', ' ', strip_tags((string) $c->last_text)), 70),
                'lastBy'  => $c->last_role,
                'at'      => $c->last_at ? \Illuminate\Support\Carbon::parse($c->last_at)->diffForHumans(short: true) : '',
                'unread'  => (int) $c->unread,
                'held'    => $c->bot_paused_at !== null && ($heldSince === null || $c->bot_paused_at->gt($heldSince)),
                'live'    => ChatTakeover::isLive($c->id),
            ];
        });

        if ($this->show === 'unread') {
            $rows = $rows->where('unread', '>', 0);
        } elseif ($this->show === 'live') {
            $rows = $rows->where('live', true);
        }

        return $rows->values()->all();
    }

    public function getRoomOptionsProperty(): array
    {
        return collect(ChatRooms::keys())->mapWithKeys(fn ($k) => [$k => ChatRooms::name($k)])->all();
    }

    private function guard(): void
    {
        abort_unless(static::canAccess(), 403);
    }
}
