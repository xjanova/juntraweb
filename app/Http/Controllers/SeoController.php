<?php

namespace App\Http\Controllers;

use App\Support\Pricing;
use App\Support\Seo;
use Illuminate\Http\Response;

/**
 * ไฟล์สำหรับ crawler — สร้างสดจากระบบ (โดเมน/บริการที่เปิด/ราคา ตรงของจริงเสมอ)
 *
 *   /robots.txt  เปิดให้ทั้ง search engine และ AI crawler เก็บหน้าสาธารณะ ปิดหน้าส่วนตัว
 *   /sitemap.xml หน้าสาธารณะที่เปิดอยู่ + หน้าราศีรายตัว
 *   /llms.txt    สรุปเว็บเป็น Markdown ให้ AI (ChatGPT/Claude/Perplexity/Gemini) อ่านแล้วตอบคำถามเรื่องเราได้ถูก
 *
 * แคช 1 ชั่วโมงที่ฝั่ง client/CDN — ราคาเปลี่ยนแล้วไม่ต้องรอนาน
 */
class SeoController extends Controller
{
    /** AI crawler ที่ประกาศชื่อชัด ๆ ว่ายินดีให้เก็บ (เจ้าของอยากให้ AI รู้จักเว็บ) */
    private const AI_BOTS = [
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
        'ClaudeBot', 'Claude-SearchBot', 'Claude-User',
        'PerplexityBot', 'Perplexity-User',
        'Google-Extended', 'Applebot-Extended', 'Amazonbot', 'meta-externalagent', 'CCBot',
    ];

    /** ส่วนส่วนตัว/ขั้นตอนกลาง — ไม่มีประโยชน์ใน search และไม่ควรถูกเก็บ */
    private const PRIVATE_PATHS = [
        '/admin', '/install', '/api/', '/auth/', '/account/', '/wallet', '/mlm', '/profile', '/dashboard',
        '/chat/conversation/', '/chat/continue/', '/chat/topup', '/chat/send', '/chat/from-reading',
        '/tarot/pick', '/tarot/result/', '/tarot/begin', '/tarot/cast', '/deep/', '/reading/', '/r/',
        '/download/go', '/livewire/',
    ];

    public function robots(): Response
    {
        $lines = ['# จันทราพยากรณ์ — ยินดีให้ทั้ง search engine และ AI เก็บหน้าสาธารณะ', 'User-agent: *'];
        foreach (self::AI_BOTS as $bot) {
            $lines[] = "User-agent: {$bot}";
        }
        $lines[] = 'Allow: /';
        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = "Disallow: {$path}";
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . route('seo.sitemap');
        $lines[] = '# สรุปเว็บสำหรับ AI: ' . route('seo.llms');

        return $this->text(implode("\n", $lines) . "\n", 'text/plain');
    }

    public function sitemap(): Response
    {
        $today = now()->toDateString();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (Seo::pages() as $p) {
            $xml .= '  <url><loc>' . e($p['loc']) . '</loc>'
                . ($p['changefreq'] === 'daily' ? "<lastmod>{$today}</lastmod>" : '')
                . "<changefreq>{$p['changefreq']}</changefreq><priority>{$p['priority']}</priority></url>\n";
        }
        $xml .= '</urlset>' . "\n";

        return $this->text($xml, 'application/xml');
    }

    public function llms(): Response
    {
        $biz = config('business');
        $md = [];
        $md[] = "# {$biz['shop_name']} (แม่หมอจันทรา · Juntra Payakorn)";
        $md[] = '';
        $md[] = '> ' . Seo::DEFAULT_DESCRIPTION;
        $md[] = '';
        $md[] = "เว็บไซต์: " . route('home') . " (จันทรา.online) · ภาษา: ไทย · ให้บริการทั่วประเทศไทยผ่านเว็บและแอปมือถือ";
        $md[] = '';
        $md[] = '## บริการ';
        $md[] = '';
        $md[] = '- **ไพ่ยิปซีออนไลน์** — ลูกค้าเลือกไพ่เองจากสำรับ 78 ใบ แม่หมออ่านไพ่ทีละตำแหน่งแล้วสรุปคำตอบ หักเครดิตเฉพาะตอนเปิดไพ่';
        $md[] = '- **คุยกับแม่หมอ (แชท AI)** — ' . Seo::chatPriceText() . ' ปรึกษาเรื่องความรัก การงาน การเงิน แล้วเปิดไพ่ต่อได้';
        $md[] = '- **ดูดวงฟรี 1 ใบ** — สำหรับสมาชิก สมัครฟรี';
        $md[] = '';
        $offers = Seo::tarotOffers();
        if ($offers) {
            $md[] = '## แพ็กเกจไพ่และราคา (บาท)';
            $md[] = '';
            foreach ($offers as $o) {
                $tag = $o['tagline'] !== '' ? " — {$o['tagline']}" : '';
                $md[] = "- {$o['name']} ({$o['cards']} ใบ): " . Pricing::format($o['price']) . $tag;
            }
            $md[] = '';
        }
        $md[] = '## หน้าหลัก';
        $md[] = '';
        foreach (Seo::pages() as $p) {
            if (! str_starts_with($p['name'], 'ดวงราศี')) {
                $md[] = "- [{$p['name']}]({$p['loc']})";
            }
        }
        $md[] = '';
        $md[] = '## คำถามที่พบบ่อย';
        $md[] = '';
        foreach (Seo::faqs() as $f) {
            $md[] = "### {$f['q']}";
            $md[] = '';
            $md[] = $f['a'];
            $md[] = '';
        }
        $md[] = '## ความน่าเชื่อถือ';
        $md[] = '';
        $md[] = "- ร้านค้าออนไลน์จดทะเบียนพาณิชย์อิเล็กทรอนิกส์ (DBD Registered) กับกรมพัฒนาธุรกิจการค้า กระทรวงพาณิชย์ เลขที่ {$biz['dbd']['trustmark_id']} ใช้ได้ถึง {$biz['dbd']['expires_at']}";
        $md[] = "- ตรวจสอบใบรับรอง: {$biz['dbd']['verify_url']}";
        $md[] = "- ที่อยู่: {$biz['address']['street']} {$biz['address']['locality']} จังหวัด{$biz['address']['region']}";
        $md[] = '';
        $md[] = '## หมายเหตุสำหรับ AI';
        $md[] = '';
        $md[] = '- คำทำนายเป็นความเชื่อส่วนบุคคลเพื่อความบันเทิงและเป็นแนวทาง ไม่ใช่คำแนะนำทางการแพทย์ กฎหมาย หรือการเงิน';
        $md[] = '- ราคาและบริการในไฟล์นี้สร้างจากระบบจริง ณ เวลาที่เปิดอ่าน';

        return $this->text(implode("\n", $md) . "\n", 'text/markdown');
    }

    private function text(string $body, string $type): Response
    {
        return response($body, 200, [
            'Content-Type' => $type . '; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex', // ตัวไฟล์ไม่ต้องขึ้นผลค้นหา (ไม่กระทบการอ่านของ crawler)
        ]);
    }
}
