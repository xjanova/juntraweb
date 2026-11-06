<?php

namespace App\Support;

use App\Models\FreeReadingClaim;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * กติกา "ดูดวงฟรี 1 ใบ" — แหล่งความจริงเดียวของเว็บ (และ API มือถือถ้าทำทีหลัง)
 *
 * เขียนตามแบบ App\Support\ChatPolicy ด้วยเหตุผลเดียวกัน: เคยมีบทเรียนว่าโควตา
 * ที่ก๊อปโค้ดไว้คนละที่จะ drift จนคนคนเดียวกันเจอกติกาคนละแบบในแต่ละช่องทาง
 *
 * บริบท (คำสั่งเจ้าของ 2026-07-26): ลูกค้าจากบอท Facebook จะถูกพามาที่เว็บ
 * เพื่อรับคำทำนายฟรี 1 ใบ สั้น ๆ แล้วค่อยขายต่อ — ของฟรีจึงต้อง
 *   1. แจกได้ครั้งเดียวต่อคน (ต่อรอบแจก)
 *   2. มีเพดานรวมต่อวันกันค่า AI บานปลาย
 *   3. "แจกใหม่ทุกคน" ได้ด้วยการเลื่อนรอบ (batch) ค่าเดียว ไม่ต้องแตะข้อมูลลูกค้า
 *
 * 🎁 (2026-11-06 เจ้าของสั่ง) ไพ่ 1 ใบฟรี **วันละใบต่อคน** ในห้องน้องบุษบา (ChatRooms::freeRoom)
 *   - สิทธิ์นับต่อ "รอบ + วัน (เวลาไทย)" → periodKey() · UNIQUE(user_id, batch) เดิมใช้ได้ทันที
 *   - "แม่นยำว่าใครเป็นใคร": นับต่อบัญชี **และ** ต่อเครื่อง (คุกกี้ที่เข้ารหัสไว้) — สมัครบัญชีใหม่
 *     บนเครื่องเดิมรับฟรีซ้ำในวันเดียวกันไม่ได้
 *   - ต้องมีวันเดือนปีเกิดในโปรไฟล์ก่อน (กรอกในแชทได้เลย ChatBirthController) แม้จะฟรี
 */
class FreeReadingPolicy
{
    /** ระยะเวลาที่ถือว่าแถวเคลมที่ยังไม่มีผล "กำลังดำเนินการอยู่" (นาที) */
    public const IN_FLIGHT_MINUTES = 10;

    /** ความยาวคำทำนายฟรีที่ยอมให้ตั้งได้ (ตัวอักษร) — ต้องตรงกับฝั่ง Thaiprompt */
    public const MIN_CHARS = 200;

    public const MAX_CHARS = 900;

    /** เปิดใช้งานระบบดูดวงฟรีหรือไม่ (default เปิด) */
    public static function enabled(): bool
    {
        return Setting::get('free_reading_enabled', '1') === '1';
    }

    /**
     * รอบการแจกสิทธิ์ปัจจุบัน — เลื่อนค่านี้ = ทุกคนได้ฟรีใหม่ทันที
     */
    public static function batch(): string
    {
        $batch = trim((string) Setting::get('free_reading_batch', 'v1'));

        return $batch !== '' ? mb_substr($batch, 0, 32) : 'v1';
    }

    /** คุกกี้ระบุเครื่อง (ค่าสุ่ม ไม่ใช่ข้อมูลส่วนตัว · Laravel เข้ารหัสคุกกี้ให้ ปลอมค่าเองไม่ได้) */
    public const DEVICE_COOKIE = 'jt_dev';

    /** วันของสิทธิ์ฟรี — วันตามเวลาไทย (เซิร์ฟเวอร์อาจตั้ง UTC: เที่ยงคืนไทย ≠ เที่ยงคืน UTC) */
    public static function today(): string
    {
        return now('Asia/Bangkok')->toDateString();
    }

    /**
     * ค่าที่เก็บในคอลัมน์ batch ของแถวเคลม = รอบแจก + วัน → ฟรีวันละใบต่อคน
     * (คอลัมน์ยาว 32: รอบตัดเหลือ 20 ตัว + "@YYYY-MM-DD")
     */
    public static function periodKey(): string
    {
        return mb_substr(self::batch(), 0, 20) . '@' . self::today();
    }

    /** id เครื่องจากคุกกี้ — ไม่มี = ออกให้ใหม่ (queue ไปกับคำตอบ) */
    public static function deviceId(Request $request): string
    {
        $id = $request->cookie(self::DEVICE_COOKIE);
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{32}$/', $id)) {
            $id = bin2hex(random_bytes(16));
            Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $id, 60 * 24 * 400, null, null, null, true, false, 'lax'));
        }

        return $id;
    }

    /** ผู้พยากรณ์ที่ดูแลไพ่ฟรี — ข้อความทุกบรรทัดของไพ่ฟรีพูดด้วยคำเรียกของคนนี้ */
    public static function oracleSelf(): string
    {
        return ChatRooms::selfName(ChatRooms::freeRoom());
    }

    /** ลูกค้าคนนี้มีวันเดือนปีเกิดในโปรไฟล์แล้วหรือยัง */
    public static function hasBirthDate($user): bool
    {
        return $user !== null && $user->profile?->birth_date !== null;
    }

    /** ความยาวคำทำนายฟรีที่ขอไปยัง Thaiprompt (ตัวอักษร) */
    public static function maxChars(): int
    {
        $val = Setting::get('free_reading_max_chars', '500');
        $val = is_numeric($val) ? (int) $val : 500;

        return max(self::MIN_CHARS, min(self::MAX_CHARS, $val));
    }

    /** เพดานจำนวนครั้งที่แจกได้ทั้งระบบต่อวัน (0 = ไม่จำกัด) */
    public static function dailyCap(): int
    {
        $val = Setting::get('free_reading_daily_cap');

        return is_numeric($val) ? max(0, (int) $val) : 0;
    }

    /**
     * ใช้สลอตไปแล้วกี่ครั้งวันนี้
     *
     * นับ "ทุกแถวที่เกิดวันนี้" รวมที่ล้มเหลวด้วย เพราะเพดานนี้คือเพดาน
     * **ต้นทุน AI** ไม่ใช่จำนวนคนที่ได้ของ — ครั้งที่ยิงไปแล้ว AI ตอบช้า/พัง
     * ก็จ่าย token ไปแล้วเหมือนกัน (ถ้าไม่นับ เพดานจะค้างที่ 0 ตลอดเวลาที่
     * อัปสตรีมมีปัญหา ซึ่งเป็นตอนที่เราต้องการเบรกมากที่สุด)
     *
     * ไม่กรองด้วย batch โดยตั้งใจ — เลื่อนรอบแจกกลางวันต้องไม่รีเซ็ตเพดานต่อวัน
     */
    public static function usedToday(): int
    {
        return FreeReadingClaim::where('created_at', '>=', now()->startOfDay())->count();
    }

    /** เพดานวันนี้เต็มหรือยัง */
    public static function capReached(): bool
    {
        $cap = self::dailyCap();

        return $cap > 0 && self::usedToday() >= $cap;
    }

    /**
     * แถวเคลมของผู้ใช้ในรอบปัจจุบัน (ถ้ามี)
     */
    public static function claimOf($user): ?FreeReadingClaim
    {
        if (! $user) {
            return null;
        }

        return FreeReadingClaim::where('user_id', $user->id)
            ->where('batch', self::periodKey())
            ->first();
    }

    /**
     * แถวเคลมนี้ "กำลังเปิดไพ่อยู่" หรือไม่ (กันกดซ้ำระหว่างรอ AI)
     */
    public static function claimInFlight(?FreeReadingClaim $claim): bool
    {
        if (! $claim || $claim->reading_id !== null || $claim->failed_at !== null) {
            return false;
        }

        return $claim->created_at !== null
            && $claim->created_at->gt(now()->subMinutes(self::IN_FLIGHT_MINUTES));
    }

    /**
     * แถวเคลมนี้ยัง "กันสิทธิ์" อยู่ไหม
     *
     * - มี reading แล้ว = ใช้สิทธิ์ไปแล้วจริง → กัน
     * - ล้มเหลว (failed_at) = คืนสิทธิ์ให้ลูกค้าลองใหม่ → ไม่กัน
     *   (แถวยังอยู่เพื่อให้เพดานต้นทุนต่อวันนับได้ถูก)
     * - ยังไม่มี reading แต่เพิ่งสร้าง = กำลังทำอยู่ → กันไว้ก่อน
     * - ยังไม่มี reading และเก่าเกิน IN_FLIGHT_MINUTES = โปรเซสตายกลางคัน
     *   → ปล่อยให้ลองใหม่ ไม่ยึดสิทธิ์เพราะระบบเราพัง
     */
    public static function claimBlocks(?FreeReadingClaim $claim): bool
    {
        if (! $claim) {
            return false;
        }

        if ($claim->reading_id !== null) {
            return true;
        }

        return self::claimInFlight($claim);
    }

    /** ผู้ใช้คนนี้เคยใช้สิทธิ์ฟรีของรอบนี้ไปแล้วหรือยัง */
    public static function alreadyUsed($user): bool
    {
        return self::claimBlocks(self::claimOf($user));
    }

    /** บัญชีอื่นบนเครื่องนี้รับไพ่ฟรีของวันนี้ไปแล้ว (หรือกำลังเปิดอยู่) */
    public static function deviceUsedToday($user, string $device): bool
    {
        return FreeReadingClaim::where('device', $device)
            ->where('batch', self::periodKey())
            ->where('user_id', '!=', $user->id)
            ->get()
            ->contains(fn (FreeReadingClaim $c) => self::claimBlocks($c));
    }

    /**
     * ผู้ใช้คนนี้ขอดูดวงฟรีได้ไหม
     *
     * @return array{allowed:bool,code:?string,reason:?string,reading_id:?int}
     */
    public static function gate($user, ?string $device = null): array
    {
        $self = self::oracleSelf();

        if (! self::enabled()) {
            return ['allowed' => false, 'code' => 'disabled', 'reading_id' => null,
                'reason' => "ตอนนี้{$self}ปิดรอบดูไพ่ฟรีชั่วคราวค่ะ"];
        }

        if (! $user) {
            return ['allowed' => false, 'code' => 'guest', 'reading_id' => null,
                'reason' => 'กรุณาเข้าสู่ระบบเพื่อรับไพ่ฟรีวันละใบค่ะ'];
        }

        // ไม่มีทางไป Thaiprompt เลย (ยังไม่ได้ตั้ง client ของเว็บ และลูกค้าไม่มี token) → ยิงไปก็ล้มแน่
        // 🔮 (2026-09-15) เดิมเช็คแค่ token → ลูกค้าเบอร์/อีเมลทุกคนเจอ "เซสชันหมดอายุ" ทั้งที่ไม่เคยมี
        //    ตอนนี้เว็บทำนายด้วยตัวตนของเว็บเองได้ (FortuneBotClient::canRead)
        if (! app(\App\Services\FortuneBot\FortuneBotClient::class)->canRead($user)) {
            return ['allowed' => false, 'code' => 'no_token', 'reading_id' => null,
                'reason' => 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่อีกครั้งค่ะ'];
        }

        $claim = self::claimOf($user);

        if (self::claimInFlight($claim)) {
            return ['allowed' => false, 'code' => 'in_flight', 'reading_id' => null,
                'reason' => "{$self}กำลังเปิดไพ่ให้อยู่ค่ะ รอสักครู่นะคะ"];
        }

        if (self::claimBlocks($claim)) {
            return ['allowed' => false, 'code' => 'used', 'reading_id' => $claim->reading_id,
                'reason' => 'วันนี้รับไพ่ฟรีไปแล้วค่ะ พรุ่งนี้มาเปิดใบใหม่ได้นะคะ'];
        }

        // 🎂 ฟรีก็ต้องรู้ว่าเป็นใคร — วันเกิดเข้าโปรไฟล์ก่อน (กรอกในแชทได้เลย)
        if (! self::hasBirthDate($user)) {
            return ['allowed' => false, 'code' => 'need_birth', 'reading_id' => null,
                'reason' => "ขอวันเดือนปีเกิดก่อนนะคะ {$self}จะได้รู้ว่าดูให้ใคร"];
        }

        // 📱 เครื่องเดียวกัน คนละบัญชี วันเดียวกัน = ไม่แจกซ้ำ (แถวที่ล้มเหลว = คืนสิทธิ์ ไม่นับ)
        if ($device !== null && self::deviceUsedToday($user, $device)) {
            return ['allowed' => false, 'code' => 'device', 'reading_id' => null,
                'reason' => 'วันนี้เครื่องนี้รับไพ่ฟรีไปแล้วค่ะ (หนึ่งคนหนึ่งใบต่อวัน) พรุ่งนี้มาใหม่นะคะ'];
        }

        if (self::capReached()) {
            return ['allowed' => false, 'code' => 'daily_cap', 'reading_id' => null,
                'reason' => "วันนี้{$self}เปิดไพ่ฟรีครบจำนวนแล้วค่ะ พรุ่งนี้มาใหม่นะคะ"];
        }

        return ['allowed' => true, 'code' => null, 'reason' => null, 'reading_id' => null];
    }
}
