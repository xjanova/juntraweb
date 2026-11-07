<?php

namespace App\Support;

use App\Models\DailyCheckin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 🌙 (2026-11-07) เช็กอินรายวัน + ดวงเสี่ยงทายประจำวัน
 *
 * เจ้าของสั่ง "เพิ่มลูกเล่นให้น่าเล่น" — ลูกค้ามีเหตุให้กลับมาทุกวัน โดยไม่เสียค่า AI สักบาท
 *   - ดวงเสี่ยงทาย = คำนวณจาก (ลูกค้า × วันที่ × วันเกิด) ล้วน ๆ → วันเดียวกันได้ผลเดิมเสมอ เปิดกี่ครั้งก็ไม่เปลี่ยน
 *   - สีประจำวัน = สีตามวันในสัปดาห์แบบไทย (ข้อเท็จจริง) ส่วนสี/เลข/ทิศ "เสริมดวง" เป็นเสี่ยงทาย (บอกลูกค้าตรง ๆ)
 *   - วันต่อเนื่อง (streak) = เช็กอินเมื่อวาน +1 · ขาดหนึ่งวัน = เริ่ม 1 ใหม่
 *   - ไม่มีรางวัลเป็นเงิน: เครดิตฟรีจะไปปนบิลค่าคอม MLM — รอเจ้าของตัดสิน
 */
final class DailyLuck
{
    public const TZ = 'Asia/Bangkok';

    /** ป้ายวันต่อเนื่อง — ของตกแต่งล้วน ไม่มีมูลค่าเงิน */
    public const MILESTONES = [3 => 'ดาวเริ่มส่องทาง', 7 => 'ครบหนึ่งรอบสัปดาห์', 14 => 'ดวงจันทร์ครึ่งดวง', 30 => 'ครบหนึ่งรอบจันทร์'];

    /** สีประจำวันแบบไทย (0 = อาทิตย์) */
    private const DAY_COLORS = [
        ['name' => 'แดง', 'hex' => '#d94b4b'],
        ['name' => 'เหลือง', 'hex' => '#e8c547'],
        ['name' => 'ชมพู', 'hex' => '#e57fb0'],
        ['name' => 'เขียว', 'hex' => '#4fae6a'],
        ['name' => 'ส้ม', 'hex' => '#e8893a'],
        ['name' => 'ฟ้า', 'hex' => '#5aa9e6'],
        ['name' => 'ม่วง', 'hex' => '#9b6ad6'],
    ];

    private const DAY_NAMES = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

    private const LUCKY_COLORS = [
        ['name' => 'ทองอร่าม', 'hex' => '#f4cf6a'], ['name' => 'ม่วงอเมทิสต์', 'hex' => '#9b6ad6'],
        ['name' => 'ขาวมุก', 'hex' => '#f3eee2'], ['name' => 'เขียวมรกต', 'hex' => '#2f9e6e'],
        ['name' => 'ชมพูกุหลาบ', 'hex' => '#e57fb0'], ['name' => 'น้ำเงินราตรี', 'hex' => '#3c5bd1'],
        ['name' => 'แดงทับทิม', 'hex' => '#c0394b'], ['name' => 'ส้มอำพัน', 'hex' => '#e8893a'],
        ['name' => 'ฟ้าครามอ่อน', 'hex' => '#6cc3e0'], ['name' => 'เงินจันทร์', 'hex' => '#c9cfd8'],
    ];

    private const DIRECTIONS = ['ทิศเหนือ', 'ทิศตะวันออกเฉียงเหนือ', 'ทิศตะวันออก', 'ทิศตะวันออกเฉียงใต้', 'ทิศใต้', 'ทิศตะวันตกเฉียงใต้', 'ทิศตะวันตก', 'ทิศตะวันตกเฉียงเหนือ'];

    private const BLESSINGS = [
        'วันนี้คำพูดของคุณมีน้ำหนัก พูดสิ่งที่ตั้งใจไว้ได้เลย',
        'มีข่าวดีเล็ก ๆ รออยู่ช่วงบ่าย อย่าเพิ่งปิดโทรศัพท์',
        'เรื่องที่ค้างใจจะคลายลงเมื่อคุณยอมถามตรง ๆ',
        'โชคมากับการเริ่มต้นใหม่ ลองทำสิ่งที่ผัดไว้สักอย่าง',
        'คนรอบตัวพร้อมช่วย แค่คุณเอ่ยปากขอ',
        'เงินไหลเข้าทางเล็ก ๆ ที่คุณไม่ได้คาดไว้',
        'วันนี้เหมาะกับการเก็บตัวทบทวน แล้วพรุ่งนี้จะชัดขึ้นมาก',
        'ใจที่นิ่งจะพาคุณผ่านเรื่องวุ่น ๆ ได้สบาย',
        'มีคนคิดถึงคุณอยู่ ข้อความเดียวอาจเปลี่ยนวันทั้งวัน',
        'ความพยายามที่ผ่านมาเริ่มออกผล ให้รางวัลตัวเองบ้าง',
        'ระวังใช้จ่ายตามอารมณ์ช่วงเย็น แล้วดวงการเงินจะดีต่อ',
        'สิ่งที่หายไปจะกลับมาในรูปแบบใหม่',
        'วันนี้คุณดูน่าเชื่อถือเป็นพิเศษ เหมาะกับการเจรจา',
        'ความรักต้องการความใส่ใจเล็ก ๆ มากกว่าคำหวาน',
        'อย่ารับปากเรื่องใหญ่ทันที ขอเวลาคิดหนึ่งคืน',
        'ดาวหนุนการเรียนรู้ อ่านหรือฟังอะไรใหม่ ๆ จะได้ไอเดียดี',
        'ช่วยคนอื่นวันนี้ บุญจะย้อนกลับมาเร็วกว่าที่คิด',
        'จัดโต๊ะหรือห้องให้โล่ง พลังงานดีจะไหลเข้ามา',
        'งานที่ดูยากจะง่ายลงครึ่งหนึ่งเมื่อลงมือจริง',
        'วันนี้เหมาะกับการขอบคุณใครสักคนที่เคยช่วยคุณ',
        'ความเงียบของใครบางคนไม่ใช่การปฏิเสธ ให้เวลาเขาหน่อย',
        'โชคชอบคนที่ตื่นเช้า ลองเริ่มวันให้เร็วขึ้นนิดหนึ่ง',
        'สัญชาตญาณคุณแม่นเป็นพิเศษ เชื่อความรู้สึกแรก',
        'อย่าเอาตัวเองไปเทียบกับใคร เส้นทางคุณกำลังมา',
        'เรื่องเล็กที่ทำด้วยใจ วันนี้มีคนเห็น',
        'พักสายตาจากจอบ้าง แล้วคำตอบจะมาเอง',
        'ความสัมพันธ์ที่ห่างไปมีโอกาสกลับมาอบอุ่น',
        'ทางออกอยู่ใกล้กว่าที่คิด ลองมองจากมุมคนอื่น',
        'วันนี้ยิ้มให้คนแปลกหน้าหนึ่งคน แล้วดูว่าจะเกิดอะไร',
        'สิ่งที่คุณขอไว้กำลังเดินทางมา อย่าเพิ่งถอดใจ',
    ];

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /** เช็กอินของวันนี้ (null = ยังไม่ได้กด) */
    public static function checkinToday(User $user): ?DailyCheckin
    {
        return DailyCheckin::where('user_id', $user->id)->whereDate('day', self::today()->toDateString())->first();
    }

    /**
     * กดเช็กอินวันนี้ — เรียกซ้ำได้ (ได้แถวเดิม) · สองแท็บกดพร้อมกันก็ได้แถวเดียว
     */
    public static function checkIn(User $user): DailyCheckin
    {
        $today = self::today();

        if ($existing = self::checkinToday($user)) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $today) {
                $yesterday = DailyCheckin::where('user_id', $user->id)
                    ->whereDate('day', $today->subDay()->toDateString())->value('streak');

                return DailyCheckin::create([
                    'user_id' => $user->id,
                    'day' => $today->toDateString(),
                    'streak' => $yesterday ? (int) $yesterday + 1 : 1,
                ]);
            });
        } catch (QueryException $e) {
            // ชน unique(user_id, day) = อีกแท็บกดไปก่อนเสี้ยววินาที → ใช้แถวนั้น
            return self::checkinToday($user) ?? throw $e;
        }
    }

    /** วันต่อเนื่องที่ยังนับอยู่ (เช็กอินล่าสุดคือวันนี้หรือเมื่อวาน) — 0 = ขาดไปแล้ว */
    public static function currentStreak(User $user): int
    {
        $last = DailyCheckin::where('user_id', $user->id)->orderByDesc('day')->first();
        if (! $last) {
            return 0;
        }
        // เทียบเป็นสตริงวันที่ — เขตเวลาแอปอาจเป็น UTC (คอลัมน์ day ถูกอ่านเป็นเที่ยงคืน UTC)
        // คำนวณ diffInDays ข้ามเขตเวลาจะนับ "สองวันก่อน" เป็น 1 วันได้
        $day = $last->day->toDateString();
        $alive = $day === self::today()->toDateString() || $day === self::today()->subDay()->toDateString();

        return $alive ? (int) $last->streak : 0;
    }

    /** ป้ายถัดไปที่ยังไม่ถึง → [วัน, ชื่อ] หรือ null ถ้าเกินป้ายสุดท้ายแล้ว */
    public static function nextMilestone(int $streak): ?array
    {
        foreach (self::MILESTONES as $days => $label) {
            if ($streak < $days) {
                return ['days' => $days, 'label' => $label, 'left' => $days - $streak];
            }
        }

        return null;
    }

    /** ป้ายที่ได้แล้วทั้งหมด */
    public static function earnedMilestones(int $streak): array
    {
        return array_filter(self::MILESTONES, fn ($label, $days) => $streak >= $days, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * ดวงเสี่ยงทายของวันนั้น — คำนวณล้วน ไม่สุ่มจริง: คนเดิม วันเดิม = ผลเดิม
     *
     * @return array{day_name:string, day_color:array, lucky_color:array, number:string, direction:string, blessing:string}
     */
    public static function reading(User $user, ?CarbonImmutable $day = null): array
    {
        $day ??= self::today();
        $birth = (string) $user->profile?->birth_date?->toDateString();
        $seed = crc32($user->id.'|'.$day->toDateString().'|'.$birth);
        // crc32 บน PHP 64 บิตเป็นบวกเสมอ (0..2^32) → ผลคูณ/xor ยังเป็นบวก ไม่ต้องกันค่าติดลบ
        $pick = fn (array $list, int $salt) => $list[(($seed >> $salt) ^ ($seed * ($salt + 7))) % count($list)];
        $dow = (int) $day->dayOfWeek;

        return [
            'day_name' => self::DAY_NAMES[$dow],
            'day_color' => self::DAY_COLORS[$dow],
            'lucky_color' => $pick(self::LUCKY_COLORS, 3),
            'number' => sprintf('%02d', ($seed >> 5) % 100),
            'direction' => $pick(self::DIRECTIONS, 7),
            'blessing' => $pick(self::BLESSINGS, 11),
        ];
    }
}
