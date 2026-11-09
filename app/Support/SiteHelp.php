<?php

namespace App\Support;

use App\Http\Controllers\ChatTopupController;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Alerts\Alert;
use App\Support\Alerts\Redact;
use Throwable;

/**
 * 🧭 (2026-11-10) คู่มือใช้เว็บของผู้พยากรณ์ — เลือกหัวข้อที่ตรงคำถาม แล้วแนบไปกับข้อความนั้นเท่านั้น
 *
 * เจ้าของสั่ง "ย้ายลูกค้าไปเว็บหมดแล้ว แม่หมอที่เว็บควรอัดความรู้เรื่องการใช้งานเว็บไซต์ แก้ปัญหาการใช้งาน
 * มากกว่าชวนคุยไปเรื่อย" — แชทจริงบน prod 2026-11-09: "ชำระตรงไหนค่ะ" ได้คำตอบสำเร็จรูปเรื่องบิลค้าง 8 ครั้ง
 * ติดกัน · "ใช้วอลเลตชำระเลยได้ไหม" ได้คำตอบลอย ๆ · ผู้พยากรณ์แต่ง "หน้าช่วยเหลือของเว็บ" ที่ไม่มีจริง
 *
 * เนื้อหาอยู่ใน config/site_help.php ที่เดียว (แก้ชื่อปุ่มได้โดยไม่แตะโค้ด) · คลาสนี้แค่:
 *   1. match()   ข้อความนี้ถามวิธีใช้เว็บเรื่องอะไร (เทียบคำในไฟล์ config ไม่ถาม AI ไม่เสียเงิน)
 *   2. block()   คู่มือเฉพาะหัวข้อนั้น + สถานะบัญชีจริงของลูกค้า (ส่งไป Thaiprompt ในฟิลด์ site_help)
 *   3. escalate() เรื่องที่ผู้พยากรณ์แก้เองไม่ได้ (เงินไม่เข้า ขอคุยกับคน เว็บค้าง) → การ์ดแจ้งทีมงานทาง Telegram
 *
 * ทุกเมธอดไม่โยน exception — คู่มือพังต้องไม่ทำให้แชทพัง (กลับไปตอบแบบเดิม)
 */
final class SiteHelp
{
    /** ยาวสุดที่ส่งไป (Thaiprompt รับไม่เกิน 4,000 — เผื่อไว้) */
    public const MAX_BLOCK_CHARS = 3800;

    public static function enabled(): bool
    {
        return (bool) config('site_help.enabled', true);
    }

    /**
     * หัวข้อที่ตรงกับข้อความ เรียงจากตรงที่สุด (ไม่เกิน max_sections)
     *
     * คะแนน = จำนวนคำใน `match` ที่เจอ · หัวข้อที่ต้องแจ้งทีมงานได้ +0.5 (เฉพาะเจาะจงกว่า "เติมเงิน" ทั่วไป)
     * เสมอกัน = ลำดับในไฟล์ config
     *
     * @return list<string>
     */
    public static function match(string $message): array
    {
        if (! self::enabled()) {
            return [];
        }
        $text = self::normalize($message);
        if ($text === '' || mb_strlen($text) > (int) config('site_help.max_message_chars', 500)) {
            return [];
        }

        $scores = [];
        $order = 0;
        foreach ((array) config('site_help.sections', []) as $id => $section) {
            $order++;
            $hits = 0;
            foreach ((array) ($section['match'] ?? []) as $entry) {
                if (self::entryMatches($text, (string) $entry)) {
                    $hits++;
                }
            }
            if ($hits > 0) {
                $scores[$id] = [$hits + (! empty($section['escalate']) ? 0.5 : 0), -$order];
            }
        }
        uasort($scores, fn ($a, $b) => $b <=> $a);

        return array_slice(array_keys($scores), 0, max(1, (int) config('site_help.max_sections', 2)));
    }

    public static function isHelp(string $message): bool
    {
        return self::match($message) !== [];
    }

    /**
     * คู่มือของหัวข้อที่เลือก + สถานะบัญชีจริง — ข้อความที่ส่งไปให้ผู้พยากรณ์ตอบ
     *
     * @param  list<string>  $ids
     */
    public static function block(array $ids, ?User $user, string $room = ChatRooms::DEFAULT): string
    {
        $sections = (array) config('site_help.sections', []);
        $vars = self::vars();
        $parts = [];
        $facts = false;
        $history = false;
        foreach ($ids as $id) {
            $s = $sections[$id] ?? null;
            if (! is_array($s)) {
                continue;
            }
            $parts[] = '## ' . ($s['title'] ?? $id) . "\n" . strtr(trim((string) ($s['body'] ?? '')), $vars);
            $facts = $facts || ! empty($s['facts']);
            $history = $history || ! empty($s['history']);
        }
        if ($parts === []) {
            return '';
        }

        $out = 'คู่มือใช้เว็บ จันทรา.online (ห้องที่ลูกค้าอยู่: ' . ChatRooms::name($room) . ")\n\n" . implode("\n\n", $parts);
        if ($facts && $user !== null && ($lines = self::accountFacts($user, $history)) !== '') {
            $out .= "\n\n" . $lines;
        }

        return mb_substr($out, 0, self::MAX_BLOCK_CHARS);
    }

    /** หัวข้อแรกที่ต้องแจ้งทีมงาน (null = ไม่ต้องแจ้ง) */
    public static function escalationFor(array $ids): ?string
    {
        foreach ($ids as $id) {
            if (! empty(config("site_help.sections.{$id}.escalate"))) {
                return $id;
            }
        }

        return null;
    }

    /**
     * ส่งการ์ดแจ้งทีมงาน — ครั้งเดียวต่อลูกค้าต่อหัวข้อ ภายใน escalate_minutes (AdminAlerts กันซ้ำให้)
     *
     * ข้อความของลูกค้าผ่าน Redact ก่อน (ลูกค้าเผลอพิมพ์เลขบัตร/รหัส OTP มาได้)
     */
    public static function escalate(User $user, string $sectionId, string $message, string $room = ChatRooms::DEFAULT): bool
    {
        try {
            $section = (array) config("site_help.sections.{$sectionId}", []);
            $bill = ChatTopupController::pendingBill($user);
            $facts = array_filter([
                'ลูกค้า' => mb_substr((string) ($user->name ?: 'สมาชิก'), 0, 40) . ' #' . $user->id,
                'ห้อง' => ChatRooms::name($room),
                'เครดิตคงเหลือ' => '฿' . number_format(app(WalletService::class)->balance($user), 2),
                'บิลรอชำระ' => $bill ? $bill->reference_code . ' ฿' . number_format((float) $bill->amount, 2) : null,
            ]);
            $recent = self::recentTopups($user, 3);
            if ($recent !== []) {
                $facts['เติมเงินล่าสุด'] = implode(' · ', array_map(
                    fn (WalletTransaction $t) => $t->reference_code . ' ฿' . number_format((float) $t->amount, 2) . ' ' . $t->statusLabel(),
                    $recent,
                ));
            }

            return AdminAlerts::send(new Alert(
                key: "site-help:{$sectionId}:u{$user->id}",
                level: Alert::WARNING,
                title: 'ลูกค้าขอความช่วยเหลือในแชท — ' . ($section['title'] ?? $sectionId),
                body: 'ลูกค้าพิมพ์ว่า: “' . mb_substr(Redact::text($message), 0, 300) . '”'
                    . "\nผู้พยากรณ์ตอบตามคู่มือแล้ว และขอรายละเอียดเพิ่มจากลูกค้าในห้อง — เข้าไปดู/ตอบในแชทของลูกค้าคนนี้",
                facts: $facts,
                url: url('/admin/users/' . $user->id),
                urlLabel: 'ดูลูกค้าคนนี้',
                category: (string) ($section['alert'] ?? 'money'),
            ), throttleMinutes: max(1, (int) config('site_help.escalate_minutes', 30)));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * สถานะบัญชีจริง — ยอดเครดิต บิลที่รอชำระ (และเติมเงินล่าสุดสำหรับเรื่องเงินไม่เข้า)
     * ไม่มีชื่อ เบอร์ อีเมล — แค่ตัวเลขของบัญชีนี้ที่ลูกค้าเห็นบนจอของตัวเองอยู่แล้ว
     */
    private static function accountFacts(User $user, bool $history): string
    {
        try {
            $lines = ['สถานะบัญชีของลูกค้าตอนนี้ (ข้อมูลจริงจากระบบ):'];
            $lines[] = '- เครดิตคงเหลือ ฿' . number_format(app(WalletService::class)->balance($user), 2);

            $bill = ChatTopupController::pendingBill($user);
            $lines[] = $bill
                ? sprintf(
                    '- มีบิลเติมเงินรอชำระ: เลขที่ %s ยอด ฿%s (ต้องโอนยอดนี้ให้ตรงเป๊ะ)%s%s',
                    $bill->reference_code,
                    number_format((float) $bill->amount, 2),
                    $bill->expires_at ? ' หมดอายุ ' . self::when($bill->expires_at) : '',
                    ! empty($bill->slip_path) ? ' · แนบสลิปแล้ว รอตรวจ' : ' · ยังไม่ได้แนบสลิป',
                )
                : '- ไม่มีบิลเติมเงินค้าง';

            if ($history) {
                $recent = self::recentTopups($user, 3);
                $lines[] = $recent === []
                    ? '- ยังไม่เคยเติมเงิน'
                    : '- เติมเงินล่าสุด: ' . implode(' · ', array_map(
                        fn (WalletTransaction $t) => sprintf('%s ฿%s %s (%s)', $t->reference_code, number_format((float) $t->amount, 2), $t->statusLabel(), self::when($t->created_at)),
                        $recent,
                    ));
            }

            return implode("\n", $lines);
        } catch (Throwable) {
            return '';
        }
    }

    /** @return list<WalletTransaction> */
    private static function recentTopups(User $user, int $limit): array
    {
        return WalletTransaction::where('user_id', $user->id)
            ->where('type', 'topup')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** ตัวแปรในเนื้อหาคู่มือ — ค่าจริงตอนนี้ (ราคา/เวลาเปลี่ยนในแอดมินได้) */
    private static function vars(): array
    {
        try {
            $prices = [];
            foreach (TarotSpreads::all() as $meta) {
                $price = Pricing::for((string) ($meta['price_key'] ?? ''));
                $prices[] = sprintf('- %s (%d ใบ) %s', $meta['name_th'] ?? '', count($meta['positions'] ?? []), $price > 0 ? Pricing::format($price) : 'ฟรี');
            }
            if (($deep = Pricing::for('deep')) > 0) {
                $prices[] = '- ดูดวงเชิงลึก (ห้องแม่หมอจันทรา) ' . Pricing::format($deep);
            }

            $rooms = [];
            foreach (ChatRooms::keys() as $key) {
                if (! ChatRooms::isOpen($key)) {
                    continue;
                }
                $room = ChatRooms::get($key) ?? [];
                $spread = TarotSpreads::all()[$room['spread'] ?? ''] ?? null;
                $rooms[] = sprintf('- %s — %s%s', $room['name'] ?? $key, $room['eyebrow'] ?? '', $spread ? ' (ไพ่หลัก: ' . ($spread['name_th'] ?? '') . ')' : '');
            }

            $minutes = (array) config('reading_qa.minutes', []);
            $qa = sprintf(
                '%d นาที (ไม้กางเขนเซลติก / พยากรณ์ 12 เดือน / คุณไสย %d นาที · เปิดแบบพรีเมียม %d นาที)',
                (int) ($minutes['default'] ?? 7),
                (int) ($minutes['celtic'] ?? 15),
                (int) config('reading_voice.qa_minutes', 20),
            );

            $line = config('business.social.line');
            $lineText = is_array($line) ? ($line['label'] ?? '') . ' ' . ($line['url'] ?? '') : 'LINE ของร้าน';

            return [
                '{prices}' => $prices !== [] ? implode("\n", $prices) : '- (ยังไม่มีแบบที่เปิดขาย)',
                '{chat_cost}' => self::chatCost(),
                '{qa_minutes}' => $qa,
                '{rooms}' => $rooms !== [] ? implode("\n", $rooms) : '- แม่หมอจันทรา',
                '{line}' => trim($lineText),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    private static function chatCost(): string
    {
        $price = ChatPolicy::cost();
        $limit = ChatPolicy::dailyLimit();

        return match (true) {
            $price <= 0 && $limit <= 0 => 'ฟรี ไม่จำกัดข้อความ',
            $price <= 0 => "ฟรีวันละ {$limit} ข้อความ",
            $limit > 0 => "ฟรีวันละ {$limit} ข้อความ จากนั้นข้อความละ " . Pricing::format($price),
            default => 'ข้อความละ ' . Pricing::format($price),
        };
    }

    private static function when($at): string
    {
        return $at ? $at->copy()->timezone(config('app.timezone'))->format('j/n H:i') . ' น.' : '';
    }

    /** ไม่สนช่องว่าง/ตัวพิมพ์/อักขระล่องหน — "เติม เงิน" = "เติมเงิน" */
    private static function normalize(string $text): string
    {
        return (string) preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', mb_strtolower(trim($text)));
    }

    /** "ก+ข" = ต้องมีครบทุกคำ · คำเดียว = มีคำนี้ */
    private static function entryMatches(string $text, string $entry): bool
    {
        $parts = array_filter(array_map(fn ($p) => self::normalize($p), explode('+', $entry)), fn ($p) => $p !== '');
        if ($parts === []) {
            return false;
        }
        foreach ($parts as $p) {
            if (! str_contains($text, $p)) {
                return false;
            }
        }

        return true;
    }
}
