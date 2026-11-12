<?php

namespace App\Services\Push;

use App\Models\PushDelivery;
use App\Models\PushDevice;
use App\Models\PushSubscription;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\DailyLuck;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🔔 นโยบายการแจ้งเตือนทั้งหมดอยู่ที่นี่ — ใครได้อะไร เมื่อไร กี่ครั้ง
 *
 * เจ้าของสั่ง (2026-11-11) "แจ้งเตือนยอดค้าง หรือตามมาดูดวงได้"
 *   ภาษา: เรียกผู้ใช้ว่า "คุณ" เสมอ น้ำเสียงนุ่ม ไม่ขู่ ไม่เร่งขาย
 *   web push ไม่ใช่ LINE push (ไม่มีโควตา 300/เดือน) แต่ส่งถี่ = คนกดบล็อกทั้งเว็บ จึงมีเพดาน:
 *     - ชวนกลับมาดูดวง (nudge) ไม่เกิน 1 ครั้ง/วัน/คน และไม่ส่งช่วงเวลาเงียบ
 *     - ยอดค้าง (reminder) ครั้งเดียวต่อบิล ไม่เกิน 1 ครั้งต่อ 20 ชม. และไม่ส่งช่วงเวลาเงียบ
 *     - คำทำนายพร้อม (transactional) ผู้ใช้เพิ่งสั่งเอง ส่งทันทีครั้งเดียวต่อรายการ ไม่ติดเวลาเงียบ
 *   แอดมินเปิด/ปิดแต่ละหมวดได้ที่ /admin → แจ้งเตือนแอป · ผู้ใช้ปิดรายหมวดได้ในหน้าบัญชี
 *
 * 📲 (2026-11-12) ส่งได้สองช่องทาง กติกาข้างบนชุดเดียวกัน:
 *   - Web Push (เบราว์เซอร์/เว็บที่ติดตั้งเป็นแอป) — เมื่อมีกุญแจ VAPID
 *   - FCM (แอป Android, ตาราง push_devices) — เมื่อตั้ง FCM_* ใน .env · ไม่ตั้ง = ปิดเงียบ ๆ
 *   แอปที่ติดตั้งจาก Google Play ไม่ได้รับ "ยอดค้าง" (ข้อความชวนโอน/แนบสลิป = ชี้ไปจ่ายนอก Play ผิดนโยบาย Payments)
 *
 * ทุกเมธอดสาธารณะไม่โยน exception — แจ้งเตือนพังห้ามทำให้งานหลัก (อ่านไพ่/เติมเงิน) พัง
 */
final class PushNotifier
{
    public const TZ = 'Asia/Bangkok';

    /** หมวด => [ชื่อ, คำอธิบาย, ชนิด] */
    public const TYPES = [
        'bill' => ['ยอดค้างชำระ', 'บิลเติมเงินที่สร้างแล้วยังไม่ได้โอน — เตือนครั้งเดียวต่อบิล หลังผ่านไป 30 นาที', 'reminder'],
        'reading_ready' => ['คำทำนายพร้อมแล้ว', 'แม่หมออ่านไพ่/ดูดวงเชิงลึกเสร็จ — ส่งทันทีที่ผลออก เผื่อผู้ใช้ปิดหน้าไปก่อน', 'transactional'],
        'daily' => ['ดวงประจำวัน', 'ชวนกลับมารับดวงวันนี้ (สีเสริมดวง/เลขนำโชค) ช่วงเช้า เฉพาะคนที่ยังไม่เช็กอิน — วันละครั้ง', 'nudge'],
    ];

    /** คำอธิบายหมวดที่สมาชิกเห็น (การ์ดในหน้าบัญชี + หน้าตั้งค่าในแอป) — คำอธิบายใน TYPES เป็นของแอดมิน */
    public const MEMBER_LABELS = [
        'bill' => 'บิลเติมเงินที่ยังไม่ได้โอน',
        'reading_ready' => 'คำทำนายอ่านเสร็จแล้ว',
        'daily' => 'ดวงประจำวันช่วงเช้า (วันละครั้ง)',
    ];

    /** หมวดที่ส่งถึงแอป Android ช่อง direct เท่านั้น — แอปจาก Play (หรือไม่รู้ช่อง) ห้ามชวนไปจ่ายนอก Google Play */
    public const APP_DIRECT_ONLY = ['bill'];

    public const DEFAULT_QUIET_START = '21:00';

    public const DEFAULT_QUIET_END = '08:00';

    /** หน้าต่างส่งดวงประจำวัน: ตั้งแต่หมดเวลาเงียบ ถึงเที่ยง (คนเปิดมือถือช่วงเช้า) */
    public const DAILY_UNTIL_HOUR = 12;

    public function __construct(private WebPushSender $sender, private FcmSender $fcm) {}

    /** มีช่องทางส่งอย่างน้อยหนึ่งช่อง (Web Push หรือ FCM) */
    public static function ready(): bool
    {
        return self::webReady() || FcmSender::configured();
    }

    /** ช่อง Web Push พร้อม (มีกุญแจ VAPID) — การ์ด/ปุ่มบนหน้าเว็บดูค่านี้ ไม่ใช่ ready() */
    public static function webReady(): bool
    {
        return Vapid::configured();
    }

    /** แอปช่องนี้ (play | direct | null = ไม่รู้ ถือเป็น play) รับหมวดนี้ได้ไหม */
    public static function appMayReceive(string $type, ?string $channel): bool
    {
        return ! in_array($type, self::APP_DIRECT_ONLY, true) || $channel === 'direct';
    }

    /**
     * จำกัดคิวรีให้เหลือผู้ใช้ที่มีเครื่องรับหมวดนี้ได้ ตามช่องทางที่เปิดอยู่ — คำสั่งตามเวลาใช้คัดคนก่อนวนส่ง
     *
     * @template T of \Illuminate\Contracts\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function whereReachable($query, string $column = 'user_id', ?string $type = null)
    {
        $web = self::webReady();
        $app = FcmSender::configured();

        return $query->where(function ($q) use ($column, $type, $web, $app) {
            if ($web) {
                $q->whereIn($column, PushSubscription::select('user_id'));
            }
            if ($app) {
                $q->orWhereIn($column, self::appDevices($type)->select('user_id'));
            }
            if (! $web && ! $app) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /** @return Builder<PushDevice> */
    private static function appDevices(?string $type): Builder
    {
        return PushDevice::query()->when(
            $type !== null && ! self::appMayReceive($type, null),
            fn ($q) => $q->where('channel', 'direct'),
        );
    }

    private static function hasDevice(int $userId, ?string $type): bool
    {
        return (self::webReady() && PushSubscription::where('user_id', $userId)->exists())
            || (FcmSender::configured() && self::appDevices($type)->where('user_id', $userId)->exists());
    }

    public static function typeEnabled(string $type): bool
    {
        return isset(self::TYPES[$type]) && Setting::get("push_type_{$type}_enabled", '1') === '1';
    }

    /** @return array{0:string,1:string} เวลาเงียบ [เริ่ม, จบ] แบบ HH:MM เวลาไทย */
    public static function quietHours(): array
    {
        $norm = fn ($v, $d) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : $d;

        return [
            $norm(Setting::get('push_quiet_start'), self::DEFAULT_QUIET_START),
            $norm(Setting::get('push_quiet_end'), self::DEFAULT_QUIET_END),
        ];
    }

    public static function isQuiet(?CarbonImmutable $at = null): bool
    {
        $now = ($at ?? CarbonImmutable::now())->setTimezone(self::TZ)->format('H:i');
        [$start, $end] = self::quietHours();
        if ($start === $end) {
            return false; // ตั้งเท่ากัน = ไม่มีเวลาเงียบ
        }

        // ข้ามเที่ยงคืน (21:00–08:00) หรือไม่ข้าม (13:00–14:00)
        return $start < $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }

    /** @return list<string> หมวดที่ผู้ใช้คนนี้ปิดเอง */
    public static function mutedTypes(User $user): array
    {
        $raw = $user->getAttribute('push_muted');
        $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return array_values(array_intersect(array_keys(self::TYPES), (array) $list));
    }

    /** @param  list<string>  $muted */
    public static function saveMutedTypes(User $user, array $muted): void
    {
        $muted = array_values(array_intersect(array_keys(self::TYPES), $muted));
        $user->forceFill(['push_muted' => $muted ? json_encode($muted) : null])->save();
    }

    // ── เรื่องที่ระบบส่ง ────────────────────────────────────────────────

    /** แม่หมออ่านเสร็จ — เรียกจาก TarotReadingFinisher / DeepReadingFinisher หลังบันทึกผลสำเร็จ */
    public function readingReady(int $readingId): string
    {
        try {
            $reading = Reading::find($readingId);
            if (! $reading || ! $reading->user_id || $reading->status !== null) {
                return 'skip';
            }
            $title = str_starts_with((string) $reading->type, 'tarot') ? 'แม่หมออ่านไพ่ของคุณเสร็จแล้ว 🔮' : 'คำทำนายของคุณพร้อมแล้ว 🌙';

            return $this->notify($reading->user_id, 'reading_ready', 'reading:'.$reading->id, [
                'title' => $title,
                'body' => 'แตะเพื่อเปิดอ่านคำทำนายได้เลยค่ะ ผลเก็บไว้ในประวัติดูดวงของคุณแล้ว',
                'url' => route('reading.show', $reading, false),
                'tag' => 'reading-'.$reading->id,
            ], respectQuiet: false, urgency: 'high');
        } catch (\Throwable $e) {
            Log::warning('Web push: readingReady failed', ['reading' => $readingId, 'err' => mb_substr($e->getMessage(), 0, 160)]);

            return 'error';
        }
    }

    /** บิลเติมเงินที่ยังไม่โอน — เรียกจากคำสั่ง push:remind-bills */
    public function billReminder(WalletTransaction $tx): string
    {
        try {
            $amount = number_format((float) $tx->amount, 2);

            return $this->notify($tx->user_id, 'bill', 'topup:'.$tx->id, [
                'title' => 'บิลเติมเงินของคุณยังรอชำระอยู่',
                'body' => "ยอด ฿{$amount} ยังรอการโอนค่ะ ถ้าโอนแล้วแนบสลิปไว้ได้เลย ระบบจะเติมเครดิตให้ทันที",
                'url' => route('wallet.topup.show', $tx, false),
                'tag' => 'bill-'.$tx->id,
            ], topic: 'bill-'.$tx->id);
        } catch (\Throwable $e) {
            Log::warning('Web push: billReminder failed', ['tx' => $tx->id, 'err' => mb_substr($e->getMessage(), 0, 160)]);

            return 'error';
        }
    }

    /** ชวนรับดวงวันนี้ — เรียกจากคำสั่ง push:daily-nudge */
    public function dailyNudge(User $user, ?CarbonImmutable $day = null): string
    {
        try {
            $day ??= DailyLuck::today();
            $luck = DailyLuck::reading($user, $day);

            return $this->notify($user->id, 'daily', 'daily:'.$day->toDateString(), [
                'title' => 'ดวงวันนี้ของคุณมาแล้ว 🌙',
                'body' => "สีเสริมดวงวันนี้คือ{$luck['lucky_color']['name']} เลขนำโชค {$luck['number']} — แตะเพื่อรับดวงประจำวันค่ะ",
                'url' => route('account.dashboard', [], false).'#daily-luck',
                'tag' => 'daily',
            ], urgency: 'low', topic: 'daily');
        } catch (\Throwable $e) {
            Log::warning('Web push: dailyNudge failed', ['user' => $user->id, 'err' => mb_substr($e->getMessage(), 0, 160)]);

            return 'error';
        }
    }

    /** ผู้ใช้กด "ลองส่งแจ้งเตือน" ในหน้าบัญชี / แอดมินกดทดสอบ — ข้ามเพดานและเวลาเงียบ */
    public function test(User $user): array
    {
        if (! self::ready()) {
            return ['devices' => 0, 'delivered' => 0];
        }

        return $this->deliver($user->id, [
            'title' => 'ทดสอบแจ้งเตือนจากจันทรา ✨',
            'body' => 'ถ้าคุณเห็นข้อความนี้ แปลว่าเครื่องนี้พร้อมรับข่าวจากแม่หมอแล้วค่ะ',
            'url' => route('account.dashboard', [], false),
            'tag' => 'test',
        ], 'normal', null);
    }

    // ── แกนกลาง ────────────────────────────────────────────────────────

    /**
     * ส่งเรื่องหนึ่งถึงผู้ใช้หนึ่งคน ผ่านทุกด่าน (เปิดใช้ไหม/ผู้ใช้ปิดไหม/เวลาเงียบ/เพดาน/ซ้ำ)
     *
     * @param  array{title:string, body:string, url:string, tag?:string}  $message
     * @return string sent | off | disabled | muted | quiet | throttled | duplicate | no_device | undelivered
     */
    public function notify(int $userId, string $type, string $ref, array $message, bool $respectQuiet = true, string $urgency = 'normal', ?string $topic = null): string
    {
        if (! self::ready()) {
            return 'off';
        }
        if (! self::typeEnabled($type)) {
            return 'disabled';
        }
        $user = User::find($userId);
        if (! $user || in_array($type, self::mutedTypes($user), true)) {
            return 'muted';
        }
        if ($respectQuiet && self::isQuiet()) {
            return 'quiet';
        }
        if ($this->throttled($userId, $type)) {
            return 'throttled';
        }
        if (! self::hasDevice($userId, $type)) {
            return 'no_device';
        }

        // จดก่อนส่ง — สองโปรเซสชนกัน (คำสั่งตามเวลาซ้อน/กดซ้ำ) ได้แถวเดียว คนเดียวได้ข้อความเดียว
        try {
            $row = PushDelivery::create(['user_id' => $userId, 'type' => $type, 'ref' => mb_substr($ref, 0, 64)]);
        } catch (QueryException $e) {
            if (PushDelivery::where(['user_id' => $userId, 'type' => $type, 'ref' => mb_substr($ref, 0, 64)])->exists()) {
                return 'duplicate';
            }
            throw $e;
        }

        $result = $this->deliver($userId, $message + ['type' => $type], $urgency, $topic);
        $row->forceFill($result)->save();

        return $result['delivered'] > 0 ? 'sent' : 'undelivered';
    }

    /** @return array{devices:int, delivered:int} นับรวมทุกช่องทาง */
    private function deliver(int $userId, array $message, string $urgency, ?string $topic): array
    {
        $message += [
            'icon' => asset('images/pwa/icon-192.png'),
            'badge' => asset('images/pwa/badge-96.png'),
            'ts' => now()->getTimestampMs(),
        ];
        $devices = 0;
        $delivered = 0;

        if (self::webReady()) {
            $subs = PushSubscription::where('user_id', $userId)->orderByDesc('id')->limit((int) config('webpush.max_per_user', 10))->get();
            foreach ($subs as $sub) {
                if ($this->sender->send($sub, $message, $urgency, $topic) === 'ok') {
                    $delivered++;
                }
            }
            $devices += $subs->count();
        }

        if (FcmSender::configured()) {
            $type = isset($message['type']) ? (string) $message['type'] : null;
            $apps = self::appDevices($type)->where('user_id', $userId)
                ->orderByDesc('updated_at')->orderByDesc('id')->limit((int) config('fcm.max_per_user', 10))->get();
            // แอปเปิดลิงก์จากแจ้งเตือนเอง — ต้องเป็น URL เต็ม (Web Push ยังได้ path ของเว็บตามเดิม)
            $appMessage = ['url' => self::absoluteUrl((string) ($message['url'] ?? '/'))] + $message;
            foreach ($apps as $device) {
                if ($this->fcm->send($device, $appMessage, $urgency, $topic) === 'ok') {
                    $delivered++;
                }
            }
            $devices += $apps->count();
        }

        return ['devices' => min(255, $devices), 'delivered' => min(255, $delivered)];
    }

    private static function absoluteUrl(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : url($url);
    }

    private function throttled(int $userId, string $type): bool
    {
        $kind = self::TYPES[$type][2] ?? 'nudge';
        if ($kind === 'transactional') {
            return false;
        }
        $q = PushDelivery::where('user_id', $userId);
        if ($kind === 'nudge') {
            $nudges = array_keys(array_filter(self::TYPES, fn ($t) => $t[2] === 'nudge'));
            // created_at เก็บตามเขตเวลาของแอป — แปลงขอบวันไทยเป็นเขตนั้นก่อนเทียบ
            $startOfDay = CarbonImmutable::now(self::TZ)->startOfDay()->setTimezone(config('app.timezone'));

            return $q->whereIn('type', $nudges)->where('created_at', '>=', $startOfDay)->exists();
        }

        return $q->where('type', $type)->where('created_at', '>=', now()->subHours(20))->exists();
    }

    /** ตัวเลขสั้น ๆ ให้หน้าแอดมิน */
    public static function stats(): array
    {
        return [
            'devices' => PushSubscription::count(),
            'users' => (int) PushSubscription::query()->distinct()->count('user_id'),
            'app_devices' => PushDevice::count(),
            'app_users' => (int) PushDevice::query()->distinct()->count('user_id'),
            'sent_24h' => PushDelivery::where('created_at', '>=', now()->subDay())->sum('delivered'),
            'by_type_24h' => PushDelivery::where('created_at', '>=', now()->subDay())
                ->select('type', DB::raw('SUM(delivered) as n'))->groupBy('type')->pluck('n', 'type')->all(),
        ];
    }
}
