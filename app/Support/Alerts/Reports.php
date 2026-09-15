<?php

namespace App\Support\Alerts;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The numbers behind the daily card (and the watchdog's queue check). Kept apart from the command so
 * a test can build yesterday's card without Telegram.
 *
 * Money words used on the card:
 *   เติมเงินเข้า = top-ups that became `success` that day (real money received)
 *   ใช้บริการ    = debits that day (credit customers spent on readings/chat)
 */
final class Reports
{
    private const TZ = 'Asia/Bangkok';

    /** Thai name of a reading type (tarot spreads, deep, numerology…) for cards and bars. */
    public static function readingLabel(?string $type): string
    {
        $type = (string) $type;

        return Pricing::labels()[$type] ?? match (true) {
            $type === 'deep' => 'ดูดวงเชิงลึก',
            str_contains($type, 'free') => 'ดูดวงฟรี 1 ใบ',
            str_starts_with($type, 'tarot') => 'เปิดไพ่ยิปซี',
            $type === '' => 'ดูดวง',
            default => $type,
        };
    }

    /** How a top-up got credited — for the card and the "via" bars. */
    public static function topupVia(WalletTransaction $tx): string
    {
        $meta = (array) $tx->meta;
        $via = (string) ($meta['confirmed_via'] ?? $meta['source'] ?? '');

        return match (true) {
            $tx->approved_by !== null => 'แอดมินอนุมัติ',
            $via === 'slipok' => 'ตรวจสลิปอัตโนมัติ',
            in_array($via, ['sms', 'smschecker_app'], true) => 'SMS ธนาคาร',
            default => 'อัตโนมัติ',
        };
    }

    /**
     * Jobs waiting in the database queue and how long the oldest has waited (minutes). Other drivers
     * (sync, redis) report [0, null] — sync has no backlog by definition.
     *
     * @return array{0:int,1:?int}
     */
    public static function queueBacklog(): array
    {
        if (config('queue.default') !== 'database') {
            return [0, null];
        }
        try {
            $table = (string) config('queue.connections.database.table', 'jobs');
            $waiting = (int) DB::table($table)->whereNull('reserved_at')->count();
            $oldest = DB::table($table)->whereNull('reserved_at')->min('created_at');
        } catch (Throwable) {
            return [0, null];
        }

        return [$waiting, $oldest ? intdiv(time() - (int) $oldest, 60) : null];
    }

    /** One Thai calendar day on one card, with a 7-day top-up chart ending that day. */
    public static function day(CarbonImmutable $day): Alert
    {
        $day = $day->setTimezone(self::TZ)->startOfDay();
        [$from, $to] = self::bounds($day);

        $topups = WalletTransaction::where('type', 'topup')->where('status', 'success')
            ->whereBetween('approved_at', [$from, $to])->get();
        $topupSum = (float) $topups->sum(fn ($t) => (float) $t->amount);
        $via = $topups->groupBy(fn ($t) => self::topupVia($t))->map->count()->sortDesc()->all();

        $spent = (float) abs((float) WalletTransaction::where('type', 'debit')->where('status', 'success')
            ->whereBetween('created_at', [$from, $to])->sum('amount'));

        $readings = Reading::whereBetween('created_at', [$from, $to])->pluck('type');
        $byType = $readings->countBy(fn ($t) => self::readingLabel($t))->sortDesc()->all();

        $chats = ChatConversation::whereBetween('created_at', [$from, $to])->count();
        $members = User::whereBetween('created_at', [$from, $to])->count();
        $pending = WalletTransaction::where('type', 'topup')->where('status', 'pending')->whereNotNull('slip_path')->count();
        $float = (float) Wallet::sum('balance');

        // Seven days of money in, oldest first, grouped in PHP (MySQL on prod, SQLite in tests).
        $weekFrom = $day->subDays(6);
        [$wFrom] = self::bounds($weekFrom);
        $rows = WalletTransaction::where('type', 'topup')->where('status', 'success')
            ->whereBetween('approved_at', [$wFrom, $to])->get(['amount', 'approved_at']);
        $columns = [];
        for ($d = $weekFrom; $d->lte($day); $d = $d->addDay()) {
            $columns[$d->day . '/' . $d->month] = 0.0;
        }
        foreach ($rows as $r) {
            $k = CarbonImmutable::parse($r->approved_at)->setTimezone(self::TZ);
            $key = $k->day . '/' . $k->month;
            if (array_key_exists($key, $columns)) {
                $columns[$key] += (float) $r->amount;
            }
        }

        $lines = [];
        $lines[] = 'เติมเงินเข้า ' . $topups->count() . ' รายการ · ใช้บริการ ฿' . number_format($spent, 2);
        $lines[] = 'ดูดวง ' . number_format($readings->count()) . ' ครั้ง · ห้องแชทใหม่ ' . number_format($chats) . ' ห้อง';
        if ($pending > 0) {
            $lines[] = '⏳ สลิปรอแอดมินตรวจตอนนี้ ' . $pending . ' รายการ';
        }
        $lines[] = 'เครดิตคงค้างในวอลเลตทั้งหมด ฿' . number_format($float, 2);

        return new Alert(
            key: 'daily:' . $day->toDateString(),
            level: Alert::MONEY,
            title: 'สรุปวันที่ ' . $day->day . ' ' . ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][$day->month - 1] . ' ' . ($day->year + 543),
            body: implode("\n", $lines),
            facts: [
                'เงินเข้า' => '฿' . number_format($topupSum, 2),
                'ดูดวง' => number_format($readings->count()) . ' ครั้ง',
                'สมาชิกใหม่' => number_format($members) . ' คน',
                'รอตรวจสลิป' => number_format($pending) . ' รายการ',
            ],
            bars: array_slice($byType, 0, 6, true),
            url: url('/admin'),
            urlLabel: 'เปิดแดชบอร์ดแอดมิน',
            category: 'daily',
            barsLabel: $byType !== [] ? 'ดูดวงแยกตามบริการ' : '',
            columns: $columns,
            columnsLabel: 'เงินเข้า 7 วัน (บาท)',
            chips: collect($via)->mapWithKeys(fn ($n, $name) => [$name . ' ' . $n => true])->all(),
            chipsLabel: 'ช่องทางที่เงินเข้า',
        );
    }

    /** [start, end] of a Thai calendar day, expressed in the app timezone the rows are stored in. */
    private static function bounds(CarbonImmutable $day): array
    {
        $tz = (string) config('app.timezone', 'UTC');

        return [
            $day->startOfDay()->setTimezone($tz)->toDateTimeString(),
            $day->endOfDay()->setTimezone($tz)->toDateTimeString(),
        ];
    }
}
