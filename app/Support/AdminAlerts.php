<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use App\Support\Alerts\Alert;
use App\Support\Telegram\TelegramBot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * The one place the owner is told about things — money in, a slip waiting for a human, somebody
 * having a reading, someone attacking the site, the site itself breaking — delivered to the admin
 * Telegram chat as a drawn card (see [TelegramBot]). Telegram, because LINE OA pushes are metered.
 *
 * THROTTLING IS THE WHOLE DESIGN. A notifier that faithfully reports every repeat of the same
 * problem, or every attempt of someone brute-forcing the login, is a notifier the owner mutes by
 * lunchtime — after which it may as well not exist. So every alert carries a key and the same key is
 * silent for a cooling-off period; each category has an hourly ceiling past which alerts fold into
 * one digest; and high-volume events (signups) are always sent as a digest. The throttle is claimed
 * once, before sending, so two workers can't both fire.
 *
 * Ported from xmanstudio's AdminAlerts (from NetWix, live since 2026-09-13).
 */
final class AdminAlerts
{
    /** What an alert is about => [name, what falls under it]. The admin switches each on or off. */
    public const CATEGORIES = [
        'money' => ['เติมเงิน & การเงิน', 'เติมเงินสำเร็จ (SMS / สลิป / แอดมิน) · สลิปรอแอดมินตรวจ · สลิปซ้ำ/ใช้แล้วถูกบล็อก · เรียกคืน/ปรับยอด'],
        'readings' => ['ดูดวง', 'มีคนดูดวง / เปิดไพ่ / ดูลายมือ / เลขศาสตร์ / ฤกษ์ยาม · ดูดวงล้มเหลวแล้วคืนเครดิต'],
        'security' => ['ความปลอดภัย', 'เดารหัสผ่าน · ล็อกอินแอดมิน · อุปกรณ์ SMS ปลอม/ลายเซ็นผิด · สลิปปลอม/ซ้ำ'],
        'system' => ['เซิร์ฟเวอร์ & ระบบ', 'เว็บเกิด error · ดิสก์ใกล้เต็ม · ตัวตั้งเวลา (cron) หยุด · งานเบื้องหลังล้มเหลว · ยอดวอลเลตไม่ตรงบัญชี'],
        'members' => ['สมาชิกใหม่', 'มีคนสมัครสมาชิก — รวบยอดส่งชั่วโมงละครั้ง'],
        'daily' => ['รายงานประจำวัน', 'สรุปเติมเงิน รายได้ ดูดวง สมาชิก และเรื่องค้าง พร้อมกราฟ 7 วัน ทุกเช้า 09:00 น.'],
    ];

    /**
     * How many alerts of a category may go out in one hour before the rest fold into a digest. A
     * busy evening of readings must not bury a slip that is waiting for approval.
     */
    private const HOURLY_CAP = [
        'money' => 60,
        'readings' => 30,
        'security' => 12,
        'system' => 12,
        'members' => 6,
        'daily' => 6,
    ];

    /** The shared hourly ceiling for CRITICAL alerts and money waiting on a human. */
    private const PRIORITY_CAP = 40;

    /**
     * True while running work that was itself deferred to after the response (see
     * [self::afterResponse]). Deferring again from there would be lost — Laravel has already
     * walked its list of deferred callbacks — so everything sends at once instead.
     */
    public static bool $immediate = false;

    public static function enabled(): bool
    {
        return TelegramBot::enabled();
    }

    /**
     * Run $fn after the response has gone out (in a web request), or now (console). Inside it,
     * send() and refresh() deliver immediately. Never throws.
     */
    public static function afterResponse(callable $fn, string $name): void
    {
        $run = function () use ($fn) {
            $was = self::$immediate;
            self::$immediate = true;
            try {
                $fn();
            } catch (Throwable $e) {
                try {
                    Log::warning('admin-alert: deferred work failed', ['error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
                } catch (Throwable) {
                }
            } finally {
                self::$immediate = $was;
            }
        };

        self::sendNow() ? $run() : defer($run, $name . ':' . bin2hex(random_bytes(4)), always: true);
    }

    /**
     * Deliver now rather than after the response: in the console and in queue workers (there is no
     * response); inside work that is itself deferred; and once the response has already been sent
     * (headers out — a terminate() hook), when Laravel has already walked its deferred callbacks and
     * a new one would never run.
     */
    private static function sendNow(): bool
    {
        return self::$immediate || app()->runningInConsole() || headers_sent();
    }

    /** True when an alert of this category would go somewhere. */
    public static function wants(string $category): bool
    {
        try {
            return self::enabled() && in_array($category, self::categories(), true);
        } catch (Throwable) {
            return false;   // settings table unreachable — never let alerting break the caller
        }
    }

    /** @return array<int,string> the categories switched on (all of them until the admin chooses) */
    public static function categories(): array
    {
        $saved = json_decode((string) Setting::get('alert_categories', ''), true);
        if (! is_array($saved)) {
            return array_keys(self::CATEGORIES);
        }

        return array_values(array_intersect(array_keys(self::CATEGORIES), $saved));
    }

    /** @param array<int,string> $categories */
    public static function saveCategories(array $categories): void
    {
        Setting::put('alert_categories', json_encode(array_values(array_intersect(array_keys(self::CATEGORIES), $categories))), 'telegram');
    }

    /**
     * Send an alert, at most once per $throttleMinutes for the same key.
     *
     * In a web request the delivery happens AFTER the response is sent: drawing the card and the API
     * call cost about a second, and the customer who just paid should not wait for the owner's
     * phone. Pass $wait when the response is already gone (a terminate() hook runs after deferred
     * callbacks and would lose it).
     *
     * @param  string|null  $subject  what the alert is about ("topup:42") — lets a later action find
     *                                and edit this message
     * @return bool true when delivered (web: when queued for after the response)
     */
    public static function send(Alert $alert, int $throttleMinutes = 60, bool $wait = false, ?string $subject = null): bool
    {
        try {
            // Checked BEFORE the throttle is claimed: an alert nobody would receive must not use up
            // the cooling-off period of one the admin switches back on a minute later.
            if (! self::wants($alert->category)) {
                return false;
            }
            if (! Cache::add('alert:throttle:' . sha1($alert->key), 1, now()->addMinutes(max(1, $throttleMinutes)))) {
                return false;   // already reported recently — silence is the feature
            }
            if (self::overCap($alert)) {
                return false;   // folded into the overflow digest instead
            }
        } catch (Throwable) {
            return false;       // cache down: never let alerting break the request that noticed something
        }

        if (! $wait && ! self::sendNow()) {
            defer(fn () => self::deliver($alert, $subject), 'admin-alert:' . $alert->key, always: true);

            return true;
        }

        return self::deliver($alert, $subject);
    }

    /** Reopen a key's cooling-off period early — when the condition it reported has ended. */
    public static function forgetThrottle(string $key): void
    {
        try {
            Cache::forget('alert:throttle:' . sha1($key));
        } catch (Throwable) {
        }
    }

    /**
     * The admin page's "ทดสอบส่ง" button: no throttle, and it only needs the bot to be configured —
     * proving a token works is exactly what you do BEFORE switching alerts on.
     *
     * @return array{0:bool,1:?string} [delivered, reason]
     */
    public static function test(): array
    {
        if (! TelegramBot::configured()) {
            return [false, 'ยังไม่ได้ใส่ Bot Token หรือ Chat ID'];
        }

        $on = self::categories();
        $chips = [];
        foreach (self::CATEGORIES as $key => [$name]) {
            $chips[$name] = in_array($key, $on, true);
        }

        $alert = new Alert(
            key: 'test',
            level: Alert::OK,
            title: 'ทดสอบการแจ้งเตือนจากเว็บจันทรา',
            body: 'ถ้าเห็นการ์ดนี้ แปลว่าบอทพร้อมส่งเรื่องสำคัญให้แล้ว — เงินเข้า สลิปรอตรวจ การดูดวง และเหตุผิดปกติ',
            facts: [
                'หมวดที่เปิด' => count($on) . '/' . count(self::CATEGORIES),
                'สถานะ' => TelegramBot::enabled() ? 'เปิดแจ้งเตือนอยู่' : 'ยังไม่เปิดสวิตช์',
            ],
            chips: $chips,
            url: url('/admin'),
            urlLabel: 'เปิดหน้าแอดมิน',
            chipsLabel: 'หมวดที่แจ้งเตือน',
        );

        $sent = TelegramBot::sendAlert($alert);
        self::record($alert, $sent, null);

        return [$sent['error'] === null, $sent['error']];
    }

    /**
     * Deliver now, into the category's forum topic when the chat has them. Returns true on success.
     */
    public static function deliver(Alert $alert, ?string $subject = null): bool
    {
        $sent = TelegramBot::sendAlert($alert, null, TelegramBot::topic($alert->category));
        self::record($alert, $sent, $subject);

        return $sent['error'] === null;
    }

    /**
     * Past a ceiling, remember the alert for the overflow digest instead of sending it.
     *
     * CRITICAL alerts and money waiting on a human are never counted against their category: a
     * stranger can make security noise at will (forged SMS-gateway calls, failed logins from a
     * spoofable IP), and that noise must not be able to fold away a real break-in or a slip waiting
     * for approval. They share one separate, generous ceiling instead — generous, not unlimited.
     */
    private static function overCap(Alert $alert): bool
    {
        $priority = $alert->level === Alert::CRITICAL
            || ($alert->category === 'money' && $alert->level === Alert::WARNING);
        $cap = $priority ? self::PRIORITY_CAP : (self::HOURLY_CAP[$alert->category] ?? 12);
        $slot = 'alert:cap:' . ($priority ? 'priority' : $alert->category) . ':' . now()->format('YmdH');
        Cache::add($slot, 0, now()->addHours(2));
        $n = (int) Cache::increment($slot);
        if ($n <= $cap) {
            return false;
        }

        $bucket = Cache::get('alert:overflow', []);
        $bucket = is_array($bucket) ? $bucket : [];
        $bucket[$alert->category][] = mb_substr($alert->title, 0, 90);
        $bucket[$alert->category] = array_slice($bucket[$alert->category], -50);
        Cache::put('alert:overflow', $bucket, now()->addHours(6));

        return true;
    }

    /** Send what the hourly ceilings held back, as one alert. Returns how many were folded. */
    public static function flushOverflow(): int
    {
        try {
            $bucket = Cache::pull('alert:overflow', []);
        } catch (Throwable) {
            return 0;
        }
        if (! is_array($bucket) || $bucket === [] || ! self::enabled()) {
            return 0;
        }

        $total = 0;
        $lines = [];
        $bars = [];
        foreach ($bucket as $category => $titles) {
            $name = self::CATEGORIES[$category][0] ?? $category;
            $bars[$name] = count($titles);
            $total += count($titles);
            $lines[] = '• ' . $name . ' — ' . count($titles) . ' เรื่อง';
            foreach (array_slice($titles, -3) as $t) {
                $lines[] = '   - ' . $t;
            }
        }

        self::deliver(new Alert(
            key: 'digest-overflow',
            level: Alert::WARNING,
            title: 'มีแจ้งเตือนถี่ผิดปกติ รวบไว้ ' . number_format($total) . ' เรื่อง',
            body: "ส่งเกินเพดานต่อชั่วโมงของหมวด จึงรวบเป็นข้อความเดียว (ช่วงคนดูดวงเยอะ หรือมีคนยิงระบบ)\n" . implode("\n", $lines),
            facts: ['ทั้งหมด' => number_format($total) . ' เรื่อง', 'หมวด' => count($bucket) . ' หมวด'],
            bars: $bars,
            url: url('/admin'),
            urlLabel: 'เปิดหน้าแอดมิน',
            category: 'system',
            barsLabel: 'แยกตามหมวด',
        ));

        return $total;
    }

    // ---------------------------------------------------------------------------- history

    /**
     * Keep what we sent, so "what was that alert on my phone?" is answerable — and so an action taken
     * later (the slip was approved on the website) can find the Telegram message and update it.
     * Swallows its own errors: a bookkeeping failure must not turn into a lost alert.
     *
     * @param  array{error:?string,message_id:?int,chat:string}  $sent
     */
    private static function record(Alert $alert, array $sent, ?string $subject): void
    {
        try {
            DB::table('admin_alerts')->insert([
                'category' => mb_substr($alert->category, 0, 20),
                'level' => mb_substr($alert->level, 0, 12),
                'alert_key' => mb_substr($alert->key, 0, 120),
                'subject' => $subject !== null ? mb_substr($subject, 0, 64) : null,
                'title' => mb_substr($alert->title, 0, 255),
                'body' => mb_substr($alert->toText(), 0, 2000),
                'ok' => $sent['error'] === null,
                'error' => $sent['error'] !== null ? mb_substr($sent['error'], 0, 255) : null,
                'chat_id' => $sent['chat'] !== '' ? mb_substr($sent['chat'], 0, 64) : null,
                'message_id' => $sent['message_id'],
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // table missing (pre-migrate) or DB hiccup — never break alerting over its own log
        }
    }

    /** The most recent alerts, newest first — for the admin page. */
    public static function recent(int $limit = 30): array
    {
        try {
            return DB::table('admin_alerts')->orderByDesc('id')->limit($limit)->get()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The Telegram messages sent about $subject ("topup:42") in the last week — a top-up can have a
     * "slip waiting" card that should stop looking pending once an admin has decided.
     *
     * @return array<int,array{chat:string,message_id:int}>
     */
    public static function messagesFor(string $subject): array
    {
        try {
            return DB::table('admin_alerts')->where('subject', $subject)->where('ok', true)
                ->whereNotNull('message_id')->whereNotNull('chat_id')
                ->where('created_at', '>=', now()->subDays(7))
                ->orderByDesc('id')->limit(3)->get(['chat_id', 'message_id'])
                ->map(fn ($r) => ['chat' => (string) $r->chat_id, 'message_id' => (int) $r->message_id])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Update the cards about $subject in place (approved, rejected), so the chat shows the current
     * state and nobody handles the same slip twice. In a web request this runs after the response,
     * like sending does.
     */
    public static function refresh(string $subject, Alert $alert): void
    {
        if (! self::enabled()) {
            return;
        }
        self::afterResponse(function () use ($subject, $alert) {
            foreach (self::messagesFor($subject) as $msg) {
                TelegramBot::editAlert($msg['chat'], $msg['message_id'], $alert);
            }
        }, 'admin-alert-refresh:' . $subject);
    }

    // ---------------------------------------------------------------------------- digests

    /**
     * Note a new member. Collected, not pushed: one ping per signup is a delight at six members and
     * a muted chat at six hundred — so they go out hourly, together.
     */
    public static function noteSignup(int $userId, string $name, string $via): void
    {
        try {
            if (! self::wants('members')) {
                return;
            }
            $bucket = Cache::get('alert:digest:signups', []);
            $bucket = is_array($bucket) ? $bucket : [];
            $bucket[$userId] = ['name' => $name, 'via' => $via];
            Cache::put('alert:digest:signups', array_slice($bucket, -500, null, true), now()->addHours(6));
        } catch (Throwable) {
            // never break a signup over an alert
        }
    }

    /** Send the hour's signups as one alert. Returns how many were reported. */
    public static function flushSignups(): int
    {
        if (! self::wants('members')) {
            return 0;
        }
        try {
            $bucket = Cache::pull('alert:digest:signups', []);
        } catch (Throwable) {
            return 0;
        }
        if (! is_array($bucket) || $bucket === []) {
            return 0;
        }

        $via = array_count_values(array_map(fn ($r) => (string) ($r['via'] ?? 'อีเมล'), $bucket));
        arsort($via);
        $names = array_map(fn ($r) => '• ' . mb_substr((string) ($r['name'] ?? ''), 0, 40), array_slice($bucket, -8, null, true));

        try {
            $today = User::where('created_at', '>=', now('Asia/Bangkok')->startOfDay()->setTimezone(config('app.timezone')))->count();
            $total = User::count();
        } catch (Throwable) {
            $today = $total = null;
        }

        self::deliver(new Alert(
            key: 'digest-signups',
            level: Alert::OK,
            title: 'สมาชิกใหม่ ' . number_format(count($bucket)) . ' คนในชั่วโมงที่ผ่านมา',
            body: (count($bucket) > 8 ? 'ล่าสุด:' : 'ได้แก่:') . "\n" . implode("\n", array_reverse($names)),
            facts: array_filter([
                'ชั่วโมงนี้' => number_format(count($bucket)) . ' คน',
                'วันนี้ทั้งหมด' => $today !== null ? number_format($today) . ' คน' : null,
                'สมาชิกทั้งหมด' => $total !== null ? number_format($total) . ' คน' : null,
            ]),
            bars: count($via) > 1 ? $via : [],
            url: url('/admin/users'),
            urlLabel: 'ดูรายชื่อสมาชิก',
            category: 'members',
            barsLabel: 'สมัครผ่าน',
        ));

        return count($bucket);
    }
}
