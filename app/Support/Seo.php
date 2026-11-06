<?php

namespace App\Support;

use App\Models\Zodiac;
use App\Services\Chat\ChatOffers;
use Illuminate\Support\Facades\Route;

/**
 * SEO ทั้งเว็บ — ทั้งเครื่องมือค้นหาปกติ (Google/Bing) และ AI (ChatGPT/Claude/Perplexity/Gemini)
 * (เจ้าของสั่ง 2026-11-04 "ทำให้เว็บรับกับ seo ให้สมบูรณ์ ทั้งปกติ และ ai")
 *
 * ที่เดียวที่ตัดสินว่า:
 *   - หน้าไหนให้เก็บเข้าดัชนี (allowlist — หน้าใหม่ที่ลืมใส่จะเป็น noindex ไม่หลุดหน้าส่วนตัวออกไป)
 *   - คำอธิบายหน้า (หน้าที่ไม่ได้ประกาศ @section('description') เอง)
 *   - รายการ URL ใน sitemap.xml / llms.txt
 *   - structured data (JSON-LD) ของร้าน + คำถามที่พบบ่อย
 * ข้อมูลร้าน/ใบ DBD มาจาก config/business.php · ราคา/บริการที่เปิดอ่านค่าจริงจากหลังบ้านทุกครั้ง
 */
class Seo
{
    /** route ที่ให้เก็บเข้าดัชนี → คำอธิบายหน้า (null = ใช้ของหน้าเองหรือค่ากลาง) */
    public const INDEXABLE = [
        'home' => null,
        'tarot.index' => 'เปิดไพ่ยิปซีออนไลน์กับแม่หมอจันทรา หมอดู AI ที่อ่านไพ่จากความหมายในตำราไพ่ 78 ใบ เลือกไพ่ด้วยมือท่านเอง อ่านทีละใบตามตำแหน่ง ไม่เดา ไม่มโน มีหลายแพ็กเกจทั้งความรัก การงาน การเงิน จ่ายเฉพาะตอนเปิดไพ่',
        'tarot.free' => 'ดูดวงไพ่ยิปซีฟรี 1 ใบกับแม่หมอจันทรา สมัครสมาชิกฟรีแล้วตั้งคำถาม เลือกไพ่ และรับคำทำนายทันที',
        'chat.index' => 'คุยกับหมอดู AI แม่หมอจันทราและผู้พยากรณ์ห้องอื่นผ่านแชทได้ตลอด 24 ชั่วโมง ปรึกษาเรื่องความรัก การงาน การเงิน แล้วเปิดไพ่ต่อได้ทันที อ่านไพ่จากความหมายในตำรา ไม่เดา ไม่มโน',
        'deep.index' => 'ดูดวงเชิงลึกกับแม่หมอจันทรา วิเคราะห์ดวงชะตาจากวันเดือนปีเกิดอย่างละเอียด',
        'horoscope.index' => 'ดูดวงรายวันครบ 12 ราศี อัปเดตทุกวัน ความรัก การงาน การเงิน สุขภาพ จากแม่หมอจันทรา',
        'horoscope.thai' => 'ดูดวงตามปีนักษัตรไทย 12 นักษัตร ลักษณะนิสัยและแนวโน้มดวงชะตา',
        'horoscope.show' => null,
        'numerology.index' => 'ดวงเลขศาสตร์ วิเคราะห์ตัวเลขจากชื่อและวันเกิด กับแม่หมอจันทรา',
        'palmistry.index' => 'ดูลายมือออนไลน์กับแม่หมอจันทรา',
        'auspicious.index' => 'หาฤกษ์มงคลออนไลน์ ฤกษ์เปิดร้าน ฤกษ์แต่งงาน ฤกษ์ขึ้นบ้านใหม่ กับแม่หมอจันทรา ฤกษ์คำนวณจริงจากตำแหน่งดวงจันทร์ ยามตามตำราอัฐกาล ไม่สุ่ม ไม่เดา',
        'download' => 'ดาวน์โหลดแอปจันทราพยากรณ์ ดูดวงและคุยกับแม่หมอได้บนมือถือ',
        'legal.privacy' => null,
        'legal.terms' => null,
        'account.deletion' => null,
    ];

    /** route → บริการใน ServiceGate (ปิดอยู่ = ไม่อยู่ใน sitemap/llms และ noindex) */
    private const GATED = [
        'deep.index' => 'deep',
        'horoscope.index' => 'horoscope',
        'horoscope.thai' => 'horoscope',
        'horoscope.show' => 'horoscope',
        'numerology.index' => 'numerology',
        'palmistry.index' => 'palmistry',
        'auspicious.index' => 'auspicious',
    ];

    /**
     * 🤖 (2026-11-04, owner) "เปิดตัวไปเลยว่าเว็บเราคือหมอดู เอไอ ทำนายแม่นมากจากตำราจริงไม่มั่ว หรือมโน"
     * คำวางตำแหน่ง "หมอดู AI" ต้องไม่เกินที่โค้ดทำจริง (ห้ามรับประกันผล เช่น "แม่น 100%"):
     *   - ไพ่: ความหมายของทั้ง 78 ใบมาจาก database/data/tarot_meanings.php และ TarotPromptBuilder
     *     ส่งความหมายตั้งตรง/กลับหัวของทุกใบที่เปิดไปให้ AI อ่านตามตำแหน่ง
     *   - ดิถี/ฤกษ์: คำนวณจากตำแหน่งดวงอาทิตย์-ดวงจันทร์ใน ThaiAstro (สูตร Meeus)
     *     ตรึงความแม่นกับเวลาจันทร์ดับ/เพ็ญของ NASA ไว้ในเทสต์
     *   - ยาม: สูตรยามอัฐกาลจากตำรา (ไม่ใช่ตำแหน่งดาว — อย่าเขียนว่า "ยามคำนวณจากดาว")
     */
    public const DEFAULT_DESCRIPTION = 'จันทราพยากรณ์ (แม่หมอจันทรา) หมอดู AI อ่านไพ่ยิปซีจากความหมายในตำราไพ่ 78 ใบ ดิถีและฤกษ์คำนวณจริงจากตำแหน่งดวงอาทิตย์-ดวงจันทร์ ไม่เดา ไม่มโน คุยกับแม่หมอ ปรึกษาความรัก การงาน การเงิน ร้านค้าออนไลน์จดทะเบียน DBD Registered';

    /** หน้าปัจจุบันควรเข้าดัชนีไหม */
    public static function indexable(): bool
    {
        $name = Route::currentRouteName();

        return $name !== null && array_key_exists($name, self::INDEXABLE) && self::routeOpen($name);
    }

    /** คำอธิบายสำรองของหน้าปัจจุบัน (หน้าที่ไม่ได้ประกาศเอง) */
    public static function description(): string
    {
        return self::INDEXABLE[Route::currentRouteName()] ?? self::DEFAULT_DESCRIPTION;
    }

    /**
     * URL หลักของหน้า (ตัด query ทิ้ง — /chat?pick=1, ?ref=… ไม่ให้กลายเป็นหน้าซ้ำ)
     */
    public static function canonical(): string
    {
        return url()->current();
    }

    public static function routeOpen(string $name): bool
    {
        return ! isset(self::GATED[$name]) || ! ServiceGate::isClosed(self::GATED[$name]);
    }

    /**
     * รายการหน้าสำหรับ sitemap.xml / llms.txt
     *
     * @return list<array{loc:string, name:string, changefreq:string, priority:string}>
     */
    public static function pages(): array
    {
        $rows = [
            ['home', 'หน้าแรก', 'daily', '1.0'],
            // tarot.index ไม่อยู่ใน sitemap แล้ว — ปิดหน้ารวมแพ็กเกจ (2026-11-06) เหลือแค่ทางพาเข้าห้อง (redirect)
            ['tarot.free', 'ดูดวงฟรี 1 ใบ', 'weekly', '0.9'],
            ['chat.index', 'คุยกับแม่หมอ (เลือกผู้พยากรณ์)', 'weekly', '0.8'],
            ['deep.index', 'ดูดวงเชิงลึก', 'weekly', '0.7'],
            ['horoscope.index', 'ดวงรายวัน 12 ราศี', 'daily', '0.8'],
            ['horoscope.thai', 'ปีนักษัตรไทย', 'monthly', '0.6'],
            ['numerology.index', 'เลขศาสตร์', 'monthly', '0.6'],
            ['palmistry.index', 'ดูลายมือ', 'monthly', '0.6'],
            ['auspicious.index', 'หาฤกษ์มงคล', 'monthly', '0.6'],
            ['download', 'ดาวน์โหลดแอปมือถือ', 'monthly', '0.5'],
            ['legal.privacy', 'นโยบายความเป็นส่วนตัว', 'yearly', '0.3'],
            ['legal.terms', 'ข้อตกลงการใช้งาน', 'yearly', '0.3'],
        ];

        $pages = [];
        foreach ($rows as [$route, $name, $freq, $prio]) {
            if (! Route::has($route) || ! self::routeOpen($route)) {
                continue;
            }
            $pages[] = ['loc' => route($route), 'name' => $name, 'changefreq' => $freq, 'priority' => $prio];

            // หน้าราศีรายตัวต่อจากหน้ารวม
            if ($route === 'horoscope.index') {
                foreach (self::zodiacs() as $z) {
                    $pages[] = ['loc' => route('horoscope.show', $z->slug), 'name' => 'ดวงราศี' . $z->name_th, 'changefreq' => 'daily', 'priority' => '0.7'];
                }
            }
        }

        return $pages;
    }

    /** แพ็กเกจไพ่ที่เปิดขาย + ราคาจริงจากหลังบ้าน */
    public static function tarotOffers(): array
    {
        return collect(TarotSpreads::all())->map(fn ($m, $k) => [
            'key' => $k,
            'name' => $m['name_th'],
            'tagline' => $m['tagline'] ?? '',
            'cards' => count($m['positions']),
            'price' => Pricing::for(TarotSpreads::priceKey($k)),
        ])->values()->all();
    }

    /** แชทฟรีจริงไหม (ไม่จำกัดหรือมีโควตาฟรีรายวัน) — ห้ามเขียนคำว่า "ฟรี" ถ้าแอดมินตั้งคิดเงิน */
    public static function chatIsFree(): bool
    {
        return ChatPolicy::isFree() || ChatPolicy::dailyLimit() > 0;
    }

    /** ข้อความเรื่องค่าแชทให้ตรงกับที่แอดมินตั้ง (ชุดเดียวกับหน้าเลือกห้อง) */
    public static function chatPriceText(): string
    {
        return match (true) {
            ChatPolicy::isFree() && ChatPolicy::dailyLimit() <= 0 => 'คุยกับแม่หมอได้ฟรีทุกวัน',
            ChatPolicy::dailyLimit() > 0 => 'คุยกับแม่หมอได้ฟรีวันละ ' . ChatPolicy::dailyLimit() . ' ข้อความ',
            default => 'คุยกับแม่หมอข้อความละ ' . Pricing::format(ChatPolicy::cost()),
        };
    }

    /**
     * คำถามที่พบบ่อย — แสดงบนหน้าแรก และใส่เป็น FAQPage schema (ต้องตรงกับที่แสดงจริงเท่านั้น)
     *
     * @return list<array{q:string, a:string}>
     */
    public static function faqs(): array
    {
        $biz = config('business');
        $prices = collect(self::tarotOffers())->pluck('price')->filter(fn ($p) => $p > 0);
        $priceText = $prices->isEmpty()
            ? 'ราคาแต่ละแพ็กเกจดูได้ที่หน้าไพ่ยิปซี'
            : 'ราคาเริ่มต้น ' . Pricing::format($prices->min()) . ' ถึง ' . Pricing::format($prices->max()) . ' ตามจำนวนไพ่ของแต่ละแพ็กเกจ';

        return [
            // 🤖 ข้อแรก (เปิดค้างไว้บนหน้าแรก) — บอกตรง ๆ ว่าเป็น AI และทำงานอย่างไร ไม่รับประกันผล
            ['q' => 'แม่หมอจันทราเป็นคนหรือ AI',
             'a' => 'ผู้พยากรณ์ทุกท่านบนจันทราพยากรณ์ ทั้งแม่หมอจันทรา น้องบุษบา และห้องคุณไสย เป็นหมอดู AI ไม่ใช่หมอดูที่เป็นคน '
                 . 'ไพ่ยิปซีอ่านจากความหมายในตำราไพ่ 78 ใบ ตามใบ ทิศตั้งตรงหรือกลับหัว และตำแหน่งที่ท่านเปิดได้จริง '
                 . 'ส่วนดิถีและฤกษ์คำนวณจากตำแหน่งดวงอาทิตย์และดวงจันทร์ด้วยสูตรดาราศาสตร์ ยามคำนวณตามตำรายามอัฐกาล ไม่สุ่ม ไม่เดา ไม่มโน '
                 . 'คำทำนายเป็นแนวทางประกอบการตัดสินใจ ไม่ใช่การรับประกันผล'],
            ['q' => 'ดูดวงออนไลน์กับจันทราพยากรณ์ทำงานอย่างไร',
             'a' => 'เลือกแพ็กเกจไพ่ยิปซีตามเรื่องที่อยากถาม ตั้งคำถาม แล้วเลือกไพ่ด้วยมือของท่านเองจากสำรับ 78 ใบ แม่หมอจันทราจะอ่านไพ่คู่กับตำแหน่งที่วางทีละใบ และสรุปคำตอบเป็นการ์ดอ่านง่าย ทำได้ทั้งบนเว็บและแอปมือถือ'],
            ['q' => 'คุยกับแม่หมอเสียเงินไหม',
             'a' => self::chatPriceText() . ' มีผู้พยากรณ์หลายท่านให้เลือกคุย'
                 . (self::chatIsFree() ? ' ระบบหักเครดิตเฉพาะตอนเปิดไพ่เท่านั้น' : '')],
            ['q' => 'เปิดไพ่ยิปซีราคาเท่าไร',
             'a' => $priceText . ' หักเครดิตเมื่อเปิดไพ่เท่านั้น'],
            ['q' => 'ดูดวงฟรีได้ไหม',
             'a' => 'ได้ สมัครสมาชิกฟรีแล้วรับคำทำนายไพ่ฟรี 1 ใบ ที่หน้า "ดูดวงฟรี 1 ใบ"'],
            ['q' => 'ข้อมูลและคำถามของฉันเป็นความลับไหม',
             'a' => 'คำถามและคำทำนายเก็บอยู่ในบัญชีของท่านเท่านั้น ย้อนกลับมาอ่านได้ที่หน้าประวัติ รายละเอียดอยู่ในนโยบายความเป็นส่วนตัว'],
            ['q' => 'จันทราพยากรณ์จดทะเบียนถูกต้องไหม',
             'a' => 'ร้าน' . $biz['shop_name'] . ' ได้รับเครื่องหมาย DBD Registered จากกรมพัฒนาธุรกิจการค้า กระทรวงพาณิชย์ เลขที่ ' . $biz['dbd']['trustmark_id'] . ' ตรวจสอบได้ที่เว็บไซต์ของกรมพัฒนาธุรกิจการค้า'],
            ['q' => 'มีแอปมือถือไหม',
             'a' => 'มี ดาวน์โหลดแอปจันทราพยากรณ์ได้ที่หน้า "ดาวน์โหลดแอพมือถือ" ใช้บัญชีเดียวกับบนเว็บ'],
        ];
    }

    /** JSON-LD ของร้าน + เว็บไซต์ (ทุกหน้า) */
    public static function siteGraph(): array
    {
        $biz = config('business');
        $home = route('home');
        $org = [
            '@type' => 'OnlineStore',
            '@id' => $home . '#organization',
            'name' => $biz['shop_name'],
            'alternateName' => ['แม่หมอจันทรา', 'Juntra Payakorn', $biz['shop_name_en'], 'จันทรา.online'],
            'url' => $home,
            'logo' => asset('images/juntra/logo.webp'),
            'image' => asset('images/juntra/og-default.jpg'),
            'description' => self::DEFAULT_DESCRIPTION,
            'foundingDate' => $biz['started_at'],
            'areaServed' => 'TH',
            'sameAs' => array_values(array_column(config('business.social', []), 'url')),
            'knowsLanguage' => 'th',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $biz['address']['street'],
                'addressLocality' => $biz['address']['locality'],
                'addressRegion' => $biz['address']['region'],
                'addressCountry' => $biz['address']['country'],
            ],
            'hasCredential' => [
                '@type' => 'EducationalOccupationalCredential',
                'name' => 'DBD Registered',
                'credentialCategory' => 'ทะเบียนพาณิชย์อิเล็กทรอนิกส์',
                'identifier' => $biz['dbd']['trustmark_id'],
                'url' => $biz['dbd']['verify_url'],
                'dateCreated' => $biz['dbd']['registered_at'],
                'expires' => $biz['dbd']['expires_at'],
                'recognizedBy' => [
                    '@type' => 'GovernmentOrganization',
                    'name' => 'กรมพัฒนาธุรกิจการค้า กระทรวงพาณิชย์',
                    'url' => 'https://www.dbd.go.th',
                ],
            ],
        ];

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                $org,
                [
                    '@type' => 'WebSite',
                    '@id' => $home . '#website',
                    'name' => $biz['shop_name'],
                    'alternateName' => 'แม่หมอจันทรา',
                    'url' => $home,
                    'inLanguage' => 'th-TH',
                    'publisher' => ['@id' => $home . '#organization'],
                ],
            ],
        ];
    }

    /** JSON-LD เฉพาะหน้าแรก — แพ็กเกจไพ่พร้อมราคา + คำถามที่พบบ่อย */
    public static function homeGraph(): array
    {
        $home = route('home');
        $offers = collect(self::tarotOffers())->map(fn ($o) => [
            '@type' => 'Offer',
            'priceCurrency' => 'THB',
            'price' => number_format($o['price'], 2, '.', ''),
            'availability' => 'https://schema.org/InStock',
            'url' => route('chat.index', ['room' => ChatOffers::menuRoomOf($o['key'] ?? ''), 'menu' => 1]),
            'itemOffered' => [
                '@type' => 'Service',
                'name' => 'ไพ่ยิปซี ' . $o['name'] . ' (' . $o['cards'] . ' ใบ)',
                'description' => $o['tagline'],
                'serviceType' => 'ดูดวงไพ่ยิปซีออนไลน์',
                'provider' => ['@id' => $home . '#organization'],
            ],
        ])->all();

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'OfferCatalog',
                    '@id' => $home . '#tarot-packages',
                    'name' => 'แพ็กเกจไพ่ยิปซีออนไลน์',
                    'itemListElement' => $offers,
                ],
                [
                    '@type' => 'FAQPage',
                    '@id' => $home . '#faq',
                    'mainEntity' => collect(self::faqs())->map(fn ($f) => [
                        '@type' => 'Question',
                        'name' => $f['q'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
                    ])->all(),
                ],
            ],
        ];
    }

    /** encode JSON-LD ให้ฝังใน <script> ได้ปลอดภัย (กัน </script> ในข้อความ) */
    public static function jsonLd(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    private static function zodiacs()
    {
        try {
            return Zodiac::orderBy('order_index')->get(['slug', 'name_th']);
        } catch (\Throwable) {
            return collect();
        }
    }
}
