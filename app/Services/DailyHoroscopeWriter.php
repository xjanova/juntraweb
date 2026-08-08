<?php

namespace App\Services;

use App\Models\Zodiac;
use App\Services\FortuneBot\FortuneBotClient;
use App\Support\ThaiAstro;
use App\Support\ThaipromptServiceAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ดวงรายวันของแต่ละราศี — คีย์พูล Thaiprompt ก่อน แล้วค่อยเขียนเองจากดาราศาสตร์
 *
 * 🔴 (2026-07-26) ก่อนหน้านี้ทั้ง 12 ราศีได้ข้อความ **ชุดเดียวกันเป๊ะ** ทุกวัน
 * เพราะ AiOracle::generateDailyHoroscope() ตกไป fallback ที่ hardcode ไว้เสมอ
 * (`ai_api_key` บนโปรดักชันเป็นค่าว่างมาตลอด) ต่างกันแค่เลขนำโชค/สี/ไพ่ ที่สุ่มจาก crc32
 * ยืนยันบน prod แล้ว: /horoscope/aries คืนข้อความตรงกับ fallback ในโค้ดทุกบรรทัด
 *
 * ⚠️ **ไม่มี fallback ไปท่อแชท** โดยเจตนา (ต่างจากฤกษ์ยาม/ไพ่ที่ลูกค้าจ่ายเงิน)
 *    ดวงรายวันเป็นของฟรีและเปิดดูได้โดยไม่ต้องล็อกอิน ถ้าไหลไปท่อแชทจะไปกิน
 *    โควตาแชทของบัญชีที่เรายืม token มา ทั้งที่ไม่มีใครจ่ายอะไรเลย
 *    (หลักเดียวกับ FortuneBotClient::freeTarot)
 *
 * fallback จึงต้องดีพอด้วยตัวเอง — ประกอบจากธาตุ/ดาวเจ้าเรือนของราศีนั้น
 * บวกกับดิถีและวารจริงของวันนั้น จึงต่างกันทั้งรายราศีและรายวันโดยไม่ต้องใช้ AI
 */
class DailyHoroscopeWriter
{
    public function __construct(private FortuneBotClient $bot) {}

    /**
     * ธีมตามธาตุ — ทุกช่องต้องมีของธาตุตัวเองอย่างน้อยหนึ่งอย่าง
     * ไม่งั้นจะกลับไปเป็นบั๊กเดิมคือ 12 ราศีอ่านได้ข้อความเดียวกัน
     */
    private const ELEMENTS = [
        'Fire' => [
            'th' => 'ไฟ', 'drive' => 'แรงผลักดันและความกล้า', 'watch' => 'ใจร้อนจนพลาดรายละเอียด',
            'work' => 'กล้าตัดสินใจเร็วกว่าคนอื่นครึ่งก้าว',
            'money_up' => 'รายได้มาจากงานที่คุณลงมือก่อนใคร แต่ระวังจ่ายตามอารมณ์ในวันที่รู้สึกว่าตัวเองทำได้ดี',
            'money_down' => 'เป็นช่วงที่ควรหยุดเปิดหน้างานใหม่ที่ต้องลงทุน แล้วเก็บแรงกับของที่ทำค้างไว้ให้จบก่อน',
        ],
        'Earth' => [
            'th' => 'ดิน', 'drive' => 'ความมั่นคงและความอดทน', 'watch' => 'ยึดของเดิมจนพลาดโอกาสใหม่',
            'work' => 'ทำของยากให้เสร็จได้ด้วยความสม่ำเสมอ',
            'money_up' => 'เหมาะกับการวางระบบเก็บเงินระยะยาวและต่อยอดจากสิ่งที่มีอยู่แล้ว มากกว่าไล่ตามของใหม่',
            'money_down' => 'เหมาะกับการปิดหนี้และตัดรายจ่ายประจำที่จ่ายไปโดยไม่ได้ใช้จริง',
        ],
        'Air' => [
            'th' => 'ลม', 'drive' => 'ไหวพริบและการสื่อสาร', 'watch' => 'คิดมากจนไม่ได้ลงมือ',
            'work' => 'เชื่อมคนและหาทางลัดที่คนอื่นมองไม่เห็น',
            'money_up' => 'เงินเข้าทางการเจรจา งานฝีมือความคิด หรือคนรู้จักแนะนำมา — ตอบให้ไวกว่าปกติ',
            'money_down' => 'ระวังจ่ายเพราะคำชวนของคนรอบตัว ลองพักไว้หนึ่งคืนก่อนตัดสินใจทุกก้อน',
        ],
        'Water' => [
            'th' => 'น้ำ', 'drive' => 'สัญชาตญาณและความเห็นใจ', 'watch' => 'เก็บความรู้สึกไว้จนอึดอัด',
            'work' => 'อ่านใจคนและจับบรรยากาศได้ก่อนใคร',
            'money_up' => 'มีเกณฑ์ได้จากคนใกล้ตัวหรือช่องทางที่ไม่ได้คาดไว้ แต่อย่าให้ยืมเกินที่ตัวเองรับไหว',
            'money_down' => 'ระวังจ่ายเพราะสงสารหรือเกรงใจ ตั้งเพดานไว้ในใจก่อนคุยเรื่องเงินกับใคร',
        ],
    ];

    private const COLORS = ['ทองอ่อน', 'ม่วงเข้ม', 'ฟ้าคราม', 'เขียวมรกต', 'ขาวงาช้าง', 'ชมพูกุหลาบ', 'แดงทับทิม', 'ส้มอิฐ', 'น้ำเงินหมึก'];
    private const CARDS = ['The Magician', 'The Sun', 'The Star', 'The World', 'The Empress', 'The Lovers', 'Wheel of Fortune', 'The Chariot', 'Temperance'];

    /**
     * @return array<string,mixed> คีย์ตรงกับคอลัมน์ของตาราง daily_horoscopes
     */
    public function write(Zodiac $zodiac, CarbonInterface $date): array
    {
        $remote = $this->fromPool($zodiac, $date);
        if ($remote) {
            return $remote;
        }

        return $this->fromSkyAndSign($zodiac, $date);
    }

    /* ============================================================ */

    private function fromPool(Zodiac $zodiac, CarbonInterface $date): ?array
    {
        $user = ThaipromptServiceAccount::resolve();
        if (! $user) {
            return null;
        }

        $data = $this->bot->dailyHoroscope($user, [
            'zodiac'    => $zodiac->slug,
            'zodiac_th' => $zodiac->name_th,
            'date'      => $date->format('Y-m-d'),
        ]);

        if (! $data || empty($data['summary'])) {
            return null;
        }

        return $this->clamp([
            'summary'      => (string) $data['summary'],
            'love'         => (string) ($data['love'] ?? ''),
            'career'       => (string) ($data['career'] ?? ''),
            'money'        => (string) ($data['money'] ?? ''),
            'health'       => (string) ($data['health'] ?? ''),
            'lucky_number' => (string) ($data['lucky_number'] ?? ''),
            'lucky_color'  => (string) ($data['lucky_color'] ?? ''),
            'lucky_card'   => (string) ($data['lucky_card'] ?? ''),
            'ai_generated' => true,
        ]);
    }

    /**
     * เขียนจากท้องฟ้าจริง + ธาตุของราศี — ไม่ใช้ AI แต่ไม่ซ้ำกันทั้ง 12 ราศี
     * และเปลี่ยนทุกวันตามดิถี/วาร ซึ่งเป็นข้อมูลจริงไม่ใช่การสุ่ม
     */
    private function fromSkyAndSign(Zodiac $zodiac, CarbonInterface $date): array
    {
        $day     = Carbon::parse($date->format('Y-m-d'), ThaiAstro::TZ);
        $tithi   = ThaiAstro::tithi($day);
        $weekday = ThaiAstro::WEEKDAY[$day->dayOfWeek];
        $el      = self::ELEMENTS[$zodiac->element] ?? self::ELEMENTS['Fire'];
        $seed    = crc32($zodiac->slug.$day->toDateString());

        $waxing = $tithi['side'] === 'waxing';
        $phase = match (true) {
            $tithi['tithi'] === 15 => 'จันทร์เพ็ญเต็มดวง',
            $tithi['tithi'] === 30 => 'จันทร์ดับ',
            $waxing                => 'จันทร์ข้างขึ้น',
            default                => 'จันทร์ข้างแรม',
        };
        $tide = $waxing ? 'เป็นจังหวะของการเริ่มและการต่อยอด' : 'เป็นจังหวะของการสะสาง ตัดทิ้ง และเก็บแรง';

        $summary = sprintf(
            '%s — %sหนุนราศี%sธาตุ%s ประกอบกับวาร%sที่มี%sเป็นดาวเจ้าเรือน %s',
            $tithi['label'], $phase, $zodiac->name_th, $el['th'],
            $weekday['name'], $weekday['planet'], $tide,
        );

        // ทุกช่องต้องมี "ของเฉพาะราศีนั้น" ไม่ใช่ของเฉพาะธาตุ ไม่งั้นเมษ/สิงห์/ธนู
        // (ธาตุไฟเหมือนกัน) จะอ่านได้ข้อความเดียวกัน 3 ใน 5 ช่อง — เป็นบั๊กเดิม
        // เวอร์ชันย่อส่วน (ยืนยันบน prod 2026-07-26: career/money/health distinct 2/3)
        //
        // 🔴 (2026-08-08) ช่อง "ความรัก" ยังหลุดอยู่ครึ่งเดือน: ฝั่งข้างขึ้นเอ่ยชื่อราศี
        // แต่ฝั่งข้างแรมประกอบจาก "ดิถี + $el['watch']" ล้วน ๆ ซึ่งเป็นของรายธาตุ
        // ทุกวันข้างแรม (ราวครึ่งปี) ทั้ง 12 ราศีจึงเหลือข้อความแค่ 4 แบบตามธาตุ
        // กติกาที่ใช้กันไว้: ทุกช่องต้องแทรก $zodiac->name_th เสมอ ไม่ว่ากิ่ง match ไหน
        $traits = trim((string) $zodiac->traits_th);

        $love = $waxing
            ? sprintf(
                '%sของชาวราศี%sเปิดทางให้คนเข้าหาได้ง่ายกว่าปกติ ชาวราศีนี้%s ถ้ามีเรื่องค้างใจกับใคร %s เป็นวันที่พูดแล้วอีกฝ่ายรับฟัง',
                $el['drive'], $zodiac->name_th, $this->loveTheme($zodiac->slug), $tithi['label'],
            )
            : sprintf(
                'ความรักช่วง%sไม่ต้องเร่ง เหมาะกับการทบทวนมากกว่าการเริ่มใหม่ ชาวราศี%s%s ช่วงนี้จึงควรกลับมาดูจุดนี้ของตัวเองมากกว่าไปเร่งอีกฝ่าย สิ่งที่ต้องระวังคือ%s แล้วอีกฝ่ายเดาใจไม่ถูก',
                $tithi['label'], $zodiac->name_th, $this->loveTheme($zodiac->slug), $el['watch'],
            );

        $career = sprintf(
            'วาร%sหนุนงานแนว%s ซึ่งเข้าทางธาตุ%sที่%s จุดแข็งของชาวราศี%sคือ%s งานที่ค้างอยู่จะขยับได้ถ้าเริ่มจากชิ้นเล็กที่สุดก่อน ระวัง%s',
            $weekday['name'], $this->careerTheme($weekday['planet']),
            $el['th'], $el['work'],
            $zodiac->name_th, $traits !== '' ? $traits : 'ความเป็นตัวของตัวเอง',
            $el['watch'],
        );

        $money = sprintf(
            '%s %s สำหรับชาวราศี%sโดยเฉพาะ %s',
            $waxing ? 'การเงินอยู่ในจังหวะไหลเข้า' : 'จังหวะนี้เป็นช่วงเก็บมากกว่าช่วงใช้',
            $waxing ? $el['money_up'] : $el['money_down'],
            $zodiac->name_th,
            $waxing ? 'ควรจดไว้ว่าเงินเข้าทางไหนบ้างในเดือนนี้ แล้วต่อยอดทางนั้นให้ชัด' : 'ควรไล่ดูรายจ่ายอัตโนมัติที่ตัดบัญชีทุกเดือนว่ายังใช้จริงอยู่ไหม',
        );

        $health = sprintf(
            'ราศี%sครองอวัยวะส่วน%s เป็นจุดที่ควรดูแลก่อนใคร ประกอบกับธาตุ%sที่มักมีเรื่อง%s พักผ่อนให้พอ ดื่มน้ำ และขยับร่างกายสักช่วงสั้น ๆ ระหว่างวัน',
            $zodiac->name_th, $this->bodyPart($zodiac->slug),
            $el['th'], $this->healthWatch($zodiac->element),
        );

        return $this->clamp([
            'summary'      => $summary,
            'love'         => $love,
            'career'       => $career,
            'money'        => $money,
            'health'       => $health,
            'lucky_number' => (string) (($seed % 99) + 1),
            'lucky_color'  => self::COLORS[$seed % count(self::COLORS)],
            'lucky_card'   => self::CARDS[($seed >> 3) % count(self::CARDS)],
            'ai_generated' => false,
        ]);
    }

    private function careerTheme(string $planet): string
    {
        return match ($planet) {
            'อาทิตย์'  => 'ที่ต้องออกหน้าและตัดสินใจ',
            'จันทร์'   => 'ที่ต้องประสานคนและดูแลรายละเอียด',
            'อังคาร'   => 'ที่ต้องลงแรงและแข่งขัน',
            'พุธ'      => 'ที่ต้องเจรจา ติดต่อ และค้าขาย',
            'พฤหัสบดี' => 'ที่ต้องพึ่งผู้ใหญ่ ครูบาอาจารย์ หรือความรู้',
            'ศุกร์'    => 'ที่ต้องใช้ความคิดสร้างสรรค์และภาพลักษณ์',
            default    => 'ที่ต้องใช้ความอดทนและความละเอียด',
        };
    }

    /**
     * โจทย์เรื่องคู่ของแต่ละราศี — วางต่อท้าย "ชาวราศีX" ได้เลย
     * ต้องครบทั้ง 12 ราศีเหมือน bodyPart() ไม่ใช่ 4 กลุ่มตามธาตุ
     * (ใช้ดาวเจ้าเรือนอย่างเดียวไม่พอ — จะเหลือ 7 กลุ่ม เพราะเมษ/พิจิกใช้อังคารร่วมกัน)
     */
    private function loveTheme(string $slug): string
    {
        return match ($slug) {
            'aries'       => 'ชอบเป็นฝ่ายเปิดก่อนและเบื่อการรอ',
            'taurus'      => 'ต้องการความแน่นอนและการแสดงออกที่จับต้องได้',
            'gemini'      => 'ผูกใจกันด้วยบทสนทนา ถ้าหยุดคุยเมื่อไหร่คือเริ่มห่าง',
            'cancer'      => 'รักแบบผูกพันลึกและเก็บคำพูดของอีกฝ่ายไว้นานกว่าที่คิด',
            'leo'         => 'ต้องการคำชมและการถูกเลือกอย่างเปิดเผย',
            'virgo'       => 'แสดงความรักด้วยการดูแลรายละเอียดเล็ก ๆ มากกว่าคำหวาน',
            'libra'       => 'ยอมเพื่อรักษาบรรยากาศจนลืมบอกความต้องการของตัวเอง',
            'scorpio'     => 'รักลึกและจริงจัง แต่มักเก็บความสงสัยไว้คนเดียว',
            'sagittarius' => 'ต้องการพื้นที่ของตัวเองพอ ๆ กับต้องการคนอยู่ข้าง ๆ',
            'capricorn'   => 'วัดความรักจากความรับผิดชอบที่ทำจริง ไม่ใช่คำสัญญา',
            'aquarius'    => 'เริ่มจากความเป็นเพื่อนและอึดอัดเมื่อถูกเร่งให้ผูกมัด',
            'pisces'      => 'ให้ใจง่ายและมักวาดภาพอีกฝ่ายไว้สวยกว่าความเป็นจริง',
            default       => 'ให้ค่ากับความจริงใจมากกว่ารูปแบบ',
        };
    }

    /**
     * อวัยวะที่แต่ละราศีครองตามโหราศาสตร์คลาสสิก (เมษครองศีรษะ ไล่ลงไปจนมีนครองเท้า)
     * ให้ผลต่างกันครบทั้ง 12 ราศี ไม่ใช่ 4 กลุ่มตามธาตุ
     */
    private function bodyPart(string $slug): string
    {
        return match ($slug) {
            'aries'       => 'ศีรษะและใบหน้า',
            'taurus'      => 'ลำคอและต่อมไทรอยด์',
            'gemini'      => 'ไหล่ แขน และปอด',
            'cancer'      => 'ทรวงอกและกระเพาะอาหาร',
            'leo'         => 'หัวใจและกลางหลัง',
            'virgo'       => 'ลำไส้และระบบย่อยอาหาร',
            'libra'       => 'ไตและบั้นเอว',
            'scorpio'     => 'ระบบขับถ่ายและอุ้งเชิงกราน',
            'sagittarius' => 'สะโพกและต้นขา',
            'capricorn'   => 'เข่า ข้อต่อ และกระดูก',
            'aquarius'    => 'น่อง ข้อเท้า และระบบไหลเวียนเลือด',
            'pisces'      => 'เท้าและระบบน้ำเหลือง',
            default       => 'ร่างกายโดยรวม',
        };
    }

    private function healthWatch(?string $element): string
    {
        return match ($element) {
            'Earth' => 'ระบบย่อยอาหารและอาการปวดหลังจากการนั่งนาน',
            'Air'   => 'ระบบหายใจ ภูมิแพ้ และการนอนไม่พอ',
            'Water' => 'ความเครียดสะสมและอาการบวมน้ำ',
            default => 'ความดัน อาการอักเสบ และการหักโหมเกินตัว',
        };
    }

    /** ตัดตามความกว้างคอลัมน์ (8/32/64) — ค่าที่ยาวเกินเคยทำหน้าราศี 500 มาแล้ว */
    private function clamp(array $row): array
    {
        $row['lucky_number'] = mb_substr($row['lucky_number'] !== '' ? $row['lucky_number'] : (string) random_int(1, 99), 0, 8);
        $row['lucky_color']  = mb_substr($row['lucky_color'] !== '' ? $row['lucky_color'] : 'ทองอ่อน', 0, 32);
        $row['lucky_card']   = mb_substr($row['lucky_card'] !== '' ? $row['lucky_card'] : 'The Star', 0, 64);

        return $row;
    }
}
