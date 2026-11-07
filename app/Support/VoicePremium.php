<?php

namespace App\Support;

use App\Models\Reading;
use App\Models\WalletTransaction;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ✨ (2026-11-05) พรีเมียม "เสียงผู้พยากรณ์" — ขาย/สถานะ/คืนเงิน (ดู config/reading_voice.php)
 *
 * สถานะเก็บใน readings.payload.premium:
 *   {status: paid|refunded, price, tx_id, at, refunded_at?, refund_reason?, chat_chars?}
 *
 * เงิน: ตัดเป็นรายการแยกจากค่าเปิดไพ่ (idempotency_key ขึ้นต้น voicepass:) — คืนเฉพาะ +฿100 ได้โดยไม่แตะค่าไพ่
 */
final class VoicePremium
{
    public const FEATURE = 'voice_premium';

    private const READY_CACHE = 'voice-premium:ready';

    public static function price(): float
    {
        return Pricing::for(self::FEATURE);
    }

    /**
     * ขายได้ตอนนี้ไหม — ระบบเสียงของ Thaiprompt ต้องพร้อมและโควต้ารายวันยังเหลือพอเล่าคำทำนาย 1 ชุด
     * ไม่พร้อม = ปุ่มพรีเมียมไม่โชว์ ไม่ตัดเงิน (ไม่ขายของที่ส่งมอบไม่ได้)
     */
    public static function offerable(): bool
    {
        if (! config('reading_voice.enabled', true)) {
            return false;
        }

        return (bool) Cache::remember(self::READY_CACHE, 60, function () {
            $h = app(JuntraServerClient::class)->voiceHealth();

            return is_array($h)
                && (bool) data_get($h, 'tts.available', false)
                && (int) data_get($h, 'tts.chars_left', 0) >= (int) config('reading_voice.min_chars_left', 3000);
        });
    }

    /** ซื้อพรีเมียมไว้และยังไม่ถูกคืนเงิน */
    public static function active(Reading $reading): bool
    {
        return data_get($reading->payload, 'premium.status') === 'paid';
    }

    /** ห้องถามต่อของคำทำนายนี้พูดออกเสียงได้อีกไหม (ยังไม่เกินเพดานตัวอักษรของคำทำนายนี้) */
    public static function canSpeak(Reading $reading, int $chars): bool
    {
        $cap = (int) config('reading_voice.chat_char_cap', 0);   // 0 = ไม่อั้น (เจ้าของสั่ง 2026-11-07)

        return self::active($reading)
            && ($cap <= 0 || (int) data_get($reading->payload, 'premium.chat_chars', 0) + $chars <= $cap);
    }

    /** นับตัวอักษรที่พูดไปแล้วในห้องถามต่อ (กันค่าเสียงบานปลาย) */
    public static function addChatChars(Reading $reading, int $chars): void
    {
        DB::transaction(function () use ($reading, $chars) {
            $r = Reading::whereKey($reading->id)->lockForUpdate()->first();
            if (! $r) {
                return;
            }
            $p = (array) $r->payload;
            $p['premium']['chat_chars'] = (int) ($p['premium']['chat_chars'] ?? 0) + $chars;
            $r->payload = $p;
            $r->save();
            $reading->setRawAttributes($r->getAttributes(), true);
        });
    }

    /**
     * คืนเงินพรีเมียม (เฉพาะ +฿100 — ค่าเปิดไพ่ไม่เกี่ยว) · ทำครั้งเดียวต่อคำทำนาย แม้เรียกพร้อมกันหลายทาง
     *
     * @return bool true = รอบนี้เป็นคนคืน
     */
    public static function refund(Reading $reading, string $reason): bool
    {
        $done = DB::transaction(function () use ($reading, $reason) {
            $r = Reading::whereKey($reading->id)->lockForUpdate()->first();
            if (! $r || data_get($r->payload, 'premium.status') !== 'paid') {
                return false;
            }
            $txId = data_get($r->payload, 'premium.tx_id');
            $tx = $txId ? WalletTransaction::whereKey($txId)->lockForUpdate()->first() : null;
            if ($tx && $tx->type === 'debit' && $tx->status === 'success') {
                app(WalletService::class)->refund($tx, $reason);
            }
            $p = (array) $r->payload;
            $p['premium']['status'] = 'refunded';
            $p['premium']['refunded_at'] = now()->toIso8601String();
            $p['premium']['refund_reason'] = $reason;
            $r->payload = $p;
            $r->save();

            return true;
        });

        if ($done) {
            $reading->refresh();
            Log::info('Voice premium refunded', ['reading' => $reading->id, 'reason' => $reason]);
        }

        return $done;
    }

    /** idempotency key ของการตัดเงินพรีเมียม — กดซ้ำ/รีโหลดด้วยฟอร์มเดิม = รายการเดิม ไม่ตัดซ้ำ */
    public static function idempotencyKey(int $userId, string $formToken): string
    {
        return 'voicepass:' . sha1($userId . '|' . $formToken);
    }

    /** ข้อมูลให้หน้าเลือกไพ่ (null = ไม่ขาย) */
    public static function offer(float $cost, ?float $balance): ?array
    {
        if (! self::offerable()) {
            return null;
        }
        $price = self::price();
        $total = $cost + $price;

        return [
            'price'     => $price,
            'priceText' => Pricing::format($price),
            'total'     => $total,
            'totalText' => Pricing::format($total),
            'minutes'   => (int) config('reading_voice.qa_minutes', 20),
            'enough'    => $balance === null
                || bccomp(number_format($balance, 2, '.', ''), number_format($total, 2, '.', ''), 2) >= 0,
        ];
    }
}
