<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\WalletTransaction;
use App\Services\Thaiprompt\JuntraServerClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 🔴 (2026-11-04) ตัวเลขสดหน้าห้องผู้พยากรณ์ — "ออนไลน์ตอนนี้ / ดูดวงไปแล้ว / คิวเปิดไพ่"
 *
 * เจ้าของสั่ง: ใช้คน DM จริงจากทุกช่องทางเป็น "กำลังสนทนา" · "ดูแล้วกี่คน" ใช้บิลจริงทั้งหมดตั้งแต่มีบิล
 * เว็บนี้ประกาศว่า "ไม่เดา ไม่มโน" ตัวเลขจึงต้องจริงทุกตัว:
 *
 *   - online : คนที่ทักแม่หมอใน 5 นาทีล่าสุด (FB/LINE/Telegram/เว็บ จาก Thaiprompt)
 *              ขั้นต่ำ 1 = คนที่กำลังเปิดหน้านี้อยู่เอง (เขาออนไลน์อยู่จริง) — ไม่บวกคนสมมติ
 *              ถ้ามีคนทักอยู่แล้วใช้เลขนั้นตรง ๆ (ไม่บวกตัวผู้ชมซ้อน เผื่อเขาเพิ่งทักมาเองและถูกนับไปแล้ว)
 *   - bills  : บิลจ่ายแล้วทั้งหมดทุกช่องทาง (Thaiprompt นับรวมบิลเว็บที่ส่งไปผังแม่หมอแล้ว — ห้ามบวกบิลเว็บซ้ำ)
 *   - queue  : บิลที่จ่ายแล้วและกำลังเปิดไพ่/ทำนายอยู่ตอนนี้ (ของบอท + ของเว็บ คนละระบบ ไม่ซ้ำกัน)
 *
 * Thaiprompt ยังไม่มี endpoint (deploy ไม่พร้อมกัน) / ต่อไม่ได้ → ใช้เลขของเว็บเองล้วน ๆ (source = web)
 * ซึ่งน้อยกว่าของจริงได้ แต่ไม่มีทางเกินของจริง
 */
class LiveRoomStats
{
    /** นับว่ากำลังคุยถ้าส่งข้อความมาภายในกี่นาที (ตรงกับฝั่ง Thaiprompt) */
    public const WINDOW_MINUTES = 5;

    /** หน้าเว็บดึงทุกกี่วินาที — แคชเท่ากัน ทุกหน้าที่เปิดอยู่ใช้ผลชุดเดียว */
    public const REFRESH_SECONDS = 15;

    /** คำทำนายของเว็บที่ค้าง pending/working เกินนี้ (นาที) ไม่ใช่คิวแล้ว */
    private const QUEUE_STALE_MINUTES = 30;

    /** ถาม Thaiprompt ไม่ได้ → พักถามกี่วินาที (กันทุกคำขอรอ timeout) */
    private const UPSTREAM_MISS_SECONDS = 60;

    public function __construct(private JuntraServerClient $server) {}

    /**
     * @return array{online:int, chatting:int, bills:?int, queue:int, source:string, refresh_seconds:int}
     */
    public function get(): array
    {
        return Cache::remember('chat:live-stats', self::REFRESH_SECONDS, function () {
            $up = $this->upstream();

            $chatting = isset($up['chatting_now']) ? (int) $up['chatting_now'] : $this->localChatting();
            $bills    = isset($up['bills_total']) ? (int) $up['bills_total'] : $this->localBills();
            $queue    = (int) ($up['reading_queue'] ?? 0) + $this->localQueue();

            return [
                'online'          => max($chatting, 1),
                'chatting'        => $chatting,
                'bills'           => $bills,
                'queue'           => $queue,
                'source'          => $up !== null ? 'all' : 'web',
                'refresh_seconds' => self::REFRESH_SECONDS,
            ];
        });
    }

    private function upstream(): ?array
    {
        if (Cache::has('chat:live-stats:upstream-miss')) {
            return null;
        }
        try {
            $data = $this->server->isConfigured() ? $this->server->liveStats() : null;
        } catch (\Throwable $e) {
            Log::debug('LiveRoomStats upstream threw', ['error' => $e->getMessage()]);
            $data = null;
        }
        if ($data === null) {
            Cache::put('chat:live-stats:upstream-miss', 1, self::UPSTREAM_MISS_SECONDS);
        }

        return $data;
    }

    /** ห้องแชทของเว็บที่ลูกค้าพิมพ์มาใน 5 นาทีล่าสุด */
    private function localChatting(): int
    {
        return $this->count(fn () => ChatMessage::query()
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->distinct()
            ->count('chat_conversation_id'));
    }

    /** บิลเปิดไพ่/ดูดวงของเว็บที่หักเงินสำเร็จ (คืนเงินแล้ว = status refunded ไม่นับ) */
    private function localBills(): ?int
    {
        try {
            return WalletTransaction::query()
                ->where('type', 'debit')
                ->where('reference_type', 'reading')
                ->where('status', 'success')
                ->count();
        } catch (\Throwable $e) {
            Log::warning('LiveRoomStats local bills failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** คำทำนายของเว็บที่จ่ายแล้วและแม่หมอกำลังอ่านอยู่ */
    private function localQueue(): int
    {
        return $this->count(fn () => Reading::query()
            ->whereIn('status', [Reading::STATUS_PENDING, Reading::STATUS_WORKING])
            ->where('updated_at', '>=', now()->subMinutes(self::QUEUE_STALE_MINUTES))
            ->count());
    }

    private function count(callable $q): int
    {
        try {
            return (int) $q();
        } catch (\Throwable $e) {
            Log::warning('LiveRoomStats local count failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
