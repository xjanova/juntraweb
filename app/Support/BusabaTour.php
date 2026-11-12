<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * 🌸 (2026-11-12, owner) ครั้งแรกที่เข้าเว็บ: หน้าเลือกสองปุ่ม "ดูดวงฟรีเลย" / "ให้บุษบาพาทัวร์"
 *    และทัวร์ข้ามหน้าที่บุษบาพากย์เสียงเอง (ข้อความ + ไฟล์เสียงอยู่ที่ config/busaba_tour.php)
 *
 * ใครเห็นหน้าเลือก (offerable):
 *   - เฉพาะหน้าแรก และหน้าเลือกห้องพยากรณ์ — หน้าอื่นมีเจตนาชัดอยู่แล้ว (ลิงก์ดูดวงฟรีจากบอท
 *     ห้องที่กำลังเปิดไพ่ หน้าเติมเงิน) ห้ามมีอะไรมาบัง
 *   - เครื่องนี้ยังไม่เคยเลือก (cookie) และ ถ้าล็อกอิน: บัญชียังไม่เคยเลือก (tour_seen_at)
 *     และยังไม่เคยดูดวงสักครั้ง — คนที่ใช้เว็บเป็นแล้วไม่ต้องเห็น
 *   - เลือกปุ่มไหน หรือกดปิด = ไม่ขึ้นอีก (cookie + localStorage ฝั่งเครื่อง, tour_seen_at ฝั่งบัญชี)
 *
 * ทัวร์ที่กำลังเดิน (running): หน้าเว็บเก็บขั้นปัจจุบันไว้ใน cookie `{cookie}_run` → หน้าถัดไปเรนเดอร์ทัวร์ต่อ
 *   (เก็บใน cookie ไม่ใช่ localStorage อย่างเดียว เพราะเซิร์ฟเวอร์ต้องรู้ว่าต้องใส่สคริปต์ทัวร์ในหน้านั้น)
 */
final class BusabaTour
{
    /** หน้าที่ขึ้นหน้าเลือกได้ — หน้าเลือกห้องประกาศ @section('busaba-tour-offer') เอง (route เดียวกับห้องแชท) */
    private const OFFER_ROUTES = ['home'];

    public static function cookie(): string
    {
        return (string) config('busaba_tour.cookie', 'jw_tour_v1');
    }

    public static function runCookie(): string
    {
        return self::cookie() . '_run';
    }

    /** เครื่องนี้หรือบัญชีนี้เลือกไปแล้ว */
    public static function seen(Request $request, ?User $user = null): bool
    {
        return $request->cookies->has(self::cookie())
            || ($user !== null && $user->tour_seen_at !== null);
    }

    /** คนนี้คือ "ครั้งแรก" ไหม — ไม่สนว่าอยู่หน้าไหน */
    public static function firstVisit(Request $request, ?User $user = null): bool
    {
        if (self::seen($request, $user)) {
            return false;
        }
        if ($user === null) {
            return true;
        }

        // เคยดูดวงแล้ว (ฟรีหรือจ่าย) = ใช้เว็บเป็นแล้ว
        return ! $user->readings()->exists();
    }

    /** ขึ้นหน้าเลือกสองปุ่มบนหน้านี้ไหม */
    public static function offerable(Request $request, ?User $user = null, bool $pageOffers = false): bool
    {
        if (! $pageOffers && ! $request->routeIs(...self::OFFER_ROUTES)) {
            return false;
        }
        // หน้าเปิดจากลิงก์ที่มีงานต้องทำต่อ (โรงละคร, แชร์, แอปมือถือ) — ไม่บัง
        if ($request->has('theater') || $request->boolean('readonly') || $request->boolean('app')) {
            return false;
        }

        return self::firstVisit($request, $user);
    }

    /** ทัวร์กำลังเดินอยู่ (หรือขอดูซ้ำด้วย ?tour=1) */
    public static function running(Request $request): bool
    {
        return $request->query('tour') === '1' || $request->cookies->has(self::runCookie());
    }

    /** จำว่าบัญชีนี้เลือกแล้ว — ครั้งเดียว ไม่เขียนทับเวลาเดิม */
    public static function markSeen(?User $user): void
    {
        if ($user !== null && $user->tour_seen_at === null) {
            $user->forceFill(['tour_seen_at' => now()])->saveQuietly();
        }
    }

    /** ข้อความที่แสดงบนจอ = ข้อความที่พูด ตัดแท็กอารมณ์ของ ElevenLabs ([excited] ฯลฯ) */
    public static function caption(string $say): string
    {
        return trim((string) preg_replace('/\s*\[[a-z ]+\]\s*/u', ' ', $say));
    }

    /**
     * ข้อความที่ส่งไปอัดเสียง — แทนคำที่ ElevenLabs อ่านผิดด้วยตัวสะกดตามเสียง (config busaba_tour.pronounce)
     * เช่น "บุษบา" อ่านเป็น "บุดบา" → ส่ง "บุดสะบา" · บนจอยังใช้ caption($say) สะกดถูกเหมือนเดิม
     */
    public static function spoken(string $say): string
    {
        $map = (array) config('busaba_tour.pronounce', []);

        return $map ? strtr($say, $map) : $say;
    }

    /** path ของไฟล์เสียงใต้ public/ */
    public static function audioPath(string $key): string
    {
        return trim((string) config('busaba_tour.dir', 'media/oracle/busaba/tour'), '/') . "/tour_{$key}.mp3";
    }

    /**
     * ขั้นของทัวร์สำหรับหน้าเว็บ
     *
     * @return list<array{key:string,title:string,text:string,audio:?string,url:?string,path:?string,target:?string,auth:bool,menu:bool,pose:string,icon:string}>
     */
    public static function steps(): array
    {
        $out = [];
        foreach ((array) config('busaba_tour.steps', []) as $key => $s) {
            $url = null;
            if (! empty($s['route'])) {
                try {
                    $url = route($s['route'], (array) ($s['params'] ?? []));
                } catch (Throwable) {
                    $url = null;
                }
            }
            $audio = self::audioPath((string) $key);

            $out[] = [
                'key'    => (string) $key,
                'title'  => (string) ($s['title'] ?? ''),
                'text'   => self::caption((string) ($s['say'] ?? '')),
                'audio'  => is_file(public_path($audio)) ? OracleMedia::asset($audio) : null,
                'url'    => $url,
                // เทียบหน้าด้วย path (ไม่รวม query) — หน้าเลือกห้อง ?pick=1 กับ /chat ถือเป็นหน้าเดียวกัน
                'path'   => $url !== null ? '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/') : null,
                'target' => isset($s['target']) ? (string) $s['target'] : null,
                'auth'   => (bool) ($s['auth'] ?? false),
                'menu'   => (bool) ($s['menu'] ?? false),
                'pose'   => (string) ($s['pose'] ?? 'wait'),
                'icon'   => (string) ($s['icon'] ?? '✨'),
            ];
        }

        return $out;
    }

    /**
     * ปลายทางของปุ่ม "ดูดวงฟรีเลย" — สั้นที่สุดเท่าที่ทำได้
     *   ล็อกอินแล้ว → หน้าดูดวงฟรี (เปิดไพ่ให้เองทันที · ใช้สิทธิ์แล้ว = พากลับห้องที่มีคำทำนายใบนั้น)
     *   ยังไม่ล็อกอิน → เข้าระบบแล้วเด้งกลับมาหน้าดูดวงฟรีเลย (ข้ามหน้า "เข้าสู่ระบบเพื่อรับไพ่")
     *   ปิดรอบดูดวงฟรีอยู่ → ห้องบุษบา (ปุ่มเปลี่ยนชื่อเป็น "เข้าห้องบุษบา" — ห้ามพูดว่าฟรีทั้งที่ไม่ฟรี)
     */
    public static function freeUrl(?User $user): string
    {
        if (! FreeReadingPolicy::enabled()) {
            return route('chat.index', ['room' => 'busaba']);
        }

        return $user !== null
            ? route('tarot.free')
            : route('thaiprompt.redirect', ['to' => '/tarot/free']);
    }
}
