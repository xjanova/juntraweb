<?php

namespace App\Support;

use App\Models\Reading;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 🔒 ล็อกห้องระหว่างทำนาย (เจ้าของสั่ง 2026-11-05)
 *
 * "ถ้าเริ่มทำนายแล้วจะเปลี่ยนห้องไม่ได้จนกว่าจะจบ หมดเวลา"
 *
 * เริ่มทำนาย = กด "เริ่มพิธี" (tarot/begin) แล้ว — สองช่วงที่ล็อก:
 *   1) ช่วงสับ/เลือกไพ่ (session tarot_pick) — ล็อกจนจ่ายเงินเปิดไพ่ หรือครบ pick_lock_minutes
 *   2) ช่วงแม่หมออ่านไพ่ (reading pending/working) — ล็อกจนเสร็จ/ล้มเหลว หรือครบ reading_lock_minutes
 *      (รายการค้างถูก readings:sweep-stuck คืนเงินที่ 5 นาที — เวลาล็อกจึงไม่ค้างลูกค้าตลอดไป)
 *
 * ห้องของการทำนาย = ห้องของผู้พยากรณ์ที่อ่านไพ่ชุดนั้น (OracleMedia::personaFor ตอนเริ่ม)
 * บังคับที่ฝั่งเซิร์ฟเวอร์ (ห้องแชท ?room= และการเริ่มดูไพ่ชุดของห้องอื่น) — ไม่ใช่แค่ซ่อนปุ่ม
 */
final class ReadingRoomLock
{
    /**
     * ล็อกที่มีผลอยู่ตอนนี้ หรือ null
     *
     * @return array{room: string, until: Carbon, reason: 'pick'|'reading', reading_id: int|null}|null
     */
    public static function active(Request $request): ?array
    {
        $locks = array_filter([self::fromReading($request), self::fromPick($request)]);
        if ($locks === []) {
            return null;
        }
        // สองช่วงพร้อมกัน (กำลังอ่านใบก่อน แล้วเริ่มสับใบใหม่ห้องเดียวกัน) — ถือตัวที่หมดช้ากว่า
        usort($locks, fn (array $a, array $b) => $b['until'] <=> $a['until']);

        return $locks[0];
    }

    /** ข้อความบอกลูกค้าว่าทำไมเปลี่ยนห้องไม่ได้ หรือ null ถ้าไปห้องนี้ได้ */
    public static function blockMessage(Request $request, string $targetRoom): ?string
    {
        $lock = self::active($request);
        if ($lock === null || $lock['room'] === ChatRooms::normalize($targetRoom)) {
            return null;
        }

        return self::message($lock);
    }

    /** @param  array{room: string, until: Carbon, reason: string}  $lock */
    public static function message(array $lock): string
    {
        $mins = max(1, (int) ceil(now()->diffInSeconds($lock['until'], false) / 60));
        $who = ChatRooms::name($lock['room']);

        return $lock['reason'] === 'reading'
            ? "{$who}กำลังอ่านไพ่ให้อยู่ — เปลี่ยนห้องได้เมื่อคำทำนายเสร็จ (หรืออีกราว {$mins} นาที)"
            : "เริ่มพิธีเปิดไพ่กับ{$who}แล้ว — เปลี่ยนห้องได้เมื่อเปิดไพ่เสร็จ หรืออีกราว {$mins} นาที";
    }

    /** ช่วงสับ/เลือกไพ่ — จาก session ที่ tarot/begin เขียนไว้ */
    private static function fromPick(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }
        $pick = $request->session()->get('tarot_pick');
        if (! is_array($pick) || empty($pick['started_at']) || empty($pick['room'])) {
            return null;
        }
        $until = Carbon::createFromTimestamp((int) $pick['started_at'])
            ->addMinutes(max(1, (int) config('card_theater.pick_lock_minutes', 15)));

        return $until->isFuture()
            ? ['room' => ChatRooms::normalize((string) $pick['room']), 'until' => $until, 'reason' => 'pick', 'reading_id' => null]
            : null;
    }

    /** ช่วงแม่หมออ่านไพ่ — รายการล่าสุดของลูกค้าที่ยังไม่เสร็จ (ภายในเวลาล็อก) */
    private static function fromReading(Request $request): ?array
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }
        $mins = max(1, (int) config('card_theater.reading_lock_minutes', 10));
        $reading = Reading::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Reading::STATUS_PENDING, Reading::STATUS_WORKING])
            ->where('created_at', '>', now()->subMinutes($mins))
            ->latest('id')
            ->first();
        if (! $reading || ! TarotSpreads::isTarotType((string) $reading->type)) {
            return null;
        }
        $room = data_get($reading->payload, 'room')
            ?: ChatRooms::personaForSpread(TarotSpreads::keyFromType((string) $reading->type));

        return [
            'room'       => ChatRooms::normalize((string) $room),
            'until'      => $reading->created_at->copy()->addMinutes($mins),
            'reason'     => 'reading',
            'reading_id' => $reading->id,
        ];
    }
}
