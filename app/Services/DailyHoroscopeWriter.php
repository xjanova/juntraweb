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

        $love = $waxing
            ? sprintf('%sของชาวราศี%sเปิดทางให้คนเข้าหาได้ง่ายกว่าปกติ ถ้ามีเรื่องค้างใจกับใคร %s เป็นวันที่พูดแล้วอีกฝ่ายรับฟัง', $el['drive'], $zodiac->name_th, $tithi['label'])
            : sprintf('ความรักช่วง%sไม่ต้องเร่ง เหมาะกับการทบทวนมากกว่าการเริ่มใหม่ ระวัง%sจนอีกฝ่ายเดาใจไม่ถูก', $tithi['label'], $el['watch']);

        $career = sprintf(
            'วาร%sหนุนงานแนว%s ซึ่งเข้าทางธาตุ%sที่%s งานที่ค้างอยู่จะขยับได้ถ้าเริ่มจากชิ้นเล็กที่สุดก่อน ระวัง%s',
            $weekday['name'], $this->careerTheme($weekday['planet']),
            $el['th'], $el['work'], $el['watch'],
        );

        $money = $waxing
            ? 'การเงินอยู่ในจังหวะไหลเข้า '.$el['money_up']
            : 'จังหวะนี้เป็นช่วงเก็บมากกว่าช่วงใช้ '.$el['money_down'];

        $health = sprintf(
            'ธาตุ%sต้องระวังเรื่อง%s พักผ่อนให้พอ ดื่มน้ำ และขยับร่างกายสักช่วงสั้น ๆ ระหว่างวัน',
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
