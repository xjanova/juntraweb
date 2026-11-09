<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Pricing;
use App\Support\SiteHelp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 🧭 (2026-11-10) ผู้พยากรณ์บนเว็บช่วยลูกค้าใช้เว็บ/แก้ปัญหา มากกว่าชวนคุยไปเรื่อย
 *
 * เจ้าของสั่ง "ย้ายลูกค้าไปเว็บหมดแล้ว แม่หมอที่เว็บควรอัดความรู้เรื่องการใช้งานเว็บไซต์ แก้ปัญหาการใช้งาน
 * มากกว่าชวนคุยไปเรื่อย" — แชทจริง: "ชำระตรงไหนค่ะ" ได้ประโยคบิลค้างซ้ำ 8 ครั้ง, "ย้อนดูยังไงคะ" ถูกตีเป็นขอดูดวง
 *
 *   - คำถามวิธีใช้เว็บ → แนบคู่มือเฉพาะหัวข้อ (config/site_help.php) + สถานะบัญชีจริง ในฟิลด์ site_help
 *   - คำถามดูดวงที่บังเอิญมีคำคล้ายกัน ("สมัครงาน", "เงินไม่เข้าช่วงนี้") ต้องไม่ถูกตีเป็นถามวิธีใช้เว็บ
 *   - เทิร์นช่วยใช้เว็บไม่คิดเงิน ไม่ยื่นการ์ด · เรื่องเงินไม่เข้า = แจ้งทีมงานครั้งเดียว
 */
class ChatSiteHelpTest extends TestCase
{
    use RefreshDatabase;

    private const TP = 'https://tp.test';

    private array $upstream = ['reply' => 'ทำตามนี้นะคะ 1. กดเมนู "วอลเลต"', 'kind' => 'reply'];

    private bool $upstreamDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Setting::put('thaiprompt_base_url', self::TP, 'thaiprompt');
        Setting::put('thaiprompt_client_id', 'juntra-client', 'thaiprompt');
        Setting::put('thaiprompt_client_secret', 'juntra-secret', 'thaiprompt', true);
        Setting::put('pricing_chat_message', '2', 'pricing', false);
        Setting::put('pricing_chat_message_enabled', '0', 'pricing', false);
        Setting::put('promptpay_id', '0812345678', 'pricing', false);
        Setting::put('promptpay_name', 'ร้านแม่หมอ', 'pricing', false);
        config(['chat_rooms.persona_upstream' => true]);
        Cache::flush();

        Http::fake(function (Request $req) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'srv-token', 'expires_in' => 3600]),
                str_ends_with($url, '/server/chat/start') => Http::response(['data' => ['session_id' => 'sess-1']], 201),
                str_ends_with($url, '/server/chat/send') => $this->upstreamDown
                    ? Http::response([], 503)
                    : Http::response(['data' => ['session_id' => 'sess-1'] + $this->upstream]),
                default => Http::response([], 503),
            };
        });
    }

    private function member(): User
    {
        return User::factory()->create(['thaiprompt_token' => null]);
    }

    /** payload ที่ส่งไป /server/chat/send */
    private function sendCalls(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/server/chat/send'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()->all();
    }

    private function title(string $id): string
    {
        return (string) config("site_help.sections.{$id}.title");
    }

    public function test_where_do_i_pay_gets_the_topup_guide_with_the_real_pending_bill_not_the_canned_hold(): void
    {
        $user = $this->member();
        $bill = $this->actingAs($user)->postJson(route('chat.topup.store'), ['amount' => 100])->assertOk();
        $ref = (string) $bill->json('reference_code');

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'ชำระตรงไหนค่ะ', 'room' => 'maemor'])->assertOk();

        // คำตอบมาจากผู้พยากรณ์ (ตามคู่มือ) — ไม่ใช่ประโยคบิลค้างสำเร็จรูปที่ลูกค้าได้ซ้ำ 8 ครั้ง
        $this->assertSame($this->upstream['reply'], $res->json('reply'));
        $res->assertJsonPath('kind', 'help')->assertJsonPath('offers', []);
        $this->assertSame(0.0, (float) $res->json('cost'));

        $help = (string) ($this->sendCalls()[0]['site_help'] ?? '');
        $this->assertStringContainsString('## ' . $this->title('topup'), $help);
        $this->assertStringContainsString('"วอลเลต"', $help);
        // สถานะบัญชีจริง: เลขบิลและยอดที่ต้องโอน
        $this->assertStringContainsString('สถานะบัญชีของลูกค้าตอนนี้', $help);
        $this->assertStringContainsString("เลขที่ {$ref}", $help);
        $this->assertStringContainsString('฿' . number_format((float) $bill->json('payable'), 2), $help);
        // ส่งเฉพาะหัวข้อที่เกี่ยว ไม่ใช่ทั้งเล่ม
        $this->assertStringNotContainsString('## ' . $this->title('login'), $help);
        $this->assertStringNotContainsString('## ' . $this->title('voice'), $help);
        $this->assertLessThanOrEqual(SiteHelp::MAX_BLOCK_CHARS, mb_strlen($help));
    }

    public function test_how_do_i_look_back_points_to_the_history_page_and_sends_no_account_numbers(): void
    {
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'ย้อนดูยังไงคะ', 'room' => 'maemor'])->assertOk();

        $res->assertJsonPath('kind', 'help')->assertJsonPath('offers', []);
        $help = (string) ($this->sendCalls()[0]['site_help'] ?? '');
        $this->assertStringContainsString('## ' . $this->title('history'), $help);
        $this->assertStringContainsString('/account/history', $help);
        // หัวข้อนี้ไม่เกี่ยวกับเงิน — ไม่แนบยอดเครดิต/บิล
        $this->assertStringNotContainsString('สถานะบัญชีของลูกค้าตอนนี้', $help);
    }

    public static function fortuneQuestions(): array
    {
        return [
            'การเงิน' => ['การเงินช่วงนี้เป็นยังไงบ้างคะ'],
            'สมัครงาน' => ['สมัครงานที่ใหม่จะผ่านไหม'],
            'เงินไม่เข้า (ชีวิต)' => ['เงินไม่เข้าเลยช่วงนี้ จะดีขึ้นไหมคะ'],
            'ค้างคาใจ' => ['ค้างคาใจเรื่องแฟนเก่ามาก'],
            'ชื่อไมค์' => ['ไมค์เขารักหนูจริงไหม'],
            'โบนัส' => ['จะได้โบนัสกี่บาท'],
            'ทันเวลา' => ['งานจะเสร็จทันเวลาไหม'],
            'ห้องนอน' => ['ย้ายเตียงไปห้องไหนดี'],
            'ขอดูดวง' => ['ดูดวงความรักให้หน่อยค่ะ'],
        ];
    }

    #[DataProvider('fortuneQuestions')]
    public function test_fortune_questions_are_not_mistaken_for_site_help(string $message): void
    {
        $this->assertSame([], SiteHelp::match($message), "\"{$message}\" ต้องไม่ใช่คำถามวิธีใช้เว็บ");
    }

    public static function usageQuestions(): array
    {
        return [
            ['ชำระตรงไหนค่ะ', 'topup'],
            ['เติมเงินยังไงคะ', 'topup'],
            ['ใช้วอลเลตชำระเลยได้ไหม', 'topup'],
            ['ยกเลิกบิลนั้นไป', 'pending_bill'],
            ['ค่าดูเท่าไหร่คะ', 'credits_prices'],
            ['เหลือกี่นาทีคะ', 'qa_timer'],
            ['ย้อนดูยังไงคะ', 'history'],
            ['ลืมรหัสผ่านค่ะ', 'login'],
            ['โอนแล้วแต่เครดิตยังไม่เข้าเลยค่ะ', 'money_missing'],
            ['ขอคุยกับแอดมินหน่อย', 'contact_admin'],
            ['เว็บ ค้าง กดไม่ได้เลย', 'site_bug'],
            ['เปิดไพ่ยังไงคะ', 'open_cards'],
        ];
    }

    #[DataProvider('usageQuestions')]
    public function test_usage_questions_pick_the_right_section_first(string $message, string $section): void
    {
        $this->assertSame($section, SiteHelp::match($message)[0] ?? null, "\"{$message}\"");
        $this->assertLessThanOrEqual((int) config('site_help.max_sections'), count(SiteHelp::match($message)));
    }

    public function test_a_reading_request_still_gets_the_offer_cards_and_no_guide(): void
    {
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'ดูดวงความรักให้หน่อยค่ะ', 'room' => 'maemor'])->assertOk();

        $res->assertJsonPath('kind', 'offer');
        $this->assertNotEmpty($res->json('offers'));
        foreach ($this->sendCalls() as $call) {
            $this->assertArrayNotHasKey('site_help', $call);
        }
    }

    public function test_a_normal_chat_message_sends_no_guide(): void
    {
        $this->actingAs($this->member())->postJson('/chat/send', ['message' => 'สวัสดีค่ะแม่หมอ', 'room' => 'maemor'])
            ->assertOk()->assertJsonPath('kind', 'reply');

        $this->assertArrayNotHasKey('site_help', $this->sendCalls()[0]);
    }

    public function test_money_missing_alerts_the_team_once_and_sends_the_recent_topups(): void
    {
        Setting::put('telegram_bot_token', '123456789:AAHfakeTokenForTestsOnly_abcdefghij', 'telegram', true);
        Setting::put('telegram_chat_id', '555000111', 'telegram');
        Setting::put('telegram_alerts_enabled', '1', 'telegram');
        $user = $this->member();
        $bill = $this->actingAs($user)->postJson(route('chat.topup.store'), ['amount' => 50])->assertOk();
        $line = 'เติมเงินล่าสุด: ' . $bill->json('reference_code') . ' ฿' . number_format((float) $bill->json('payable'), 2) . ' รอตรวจสอบ';

        $this->actingAs($user)->postJson('/chat/send', ['message' => 'โอนแล้วแต่เครดิตยังไม่เข้าเลยค่ะ', 'room' => 'maemor'])
            ->assertOk()->assertJsonPath('kind', 'help');
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'โอนไปแล้วเครดิตไม่เข้าจริง ๆ ค่ะ', 'room' => 'maemor'])
            ->assertOk();

        $help = (string) ($this->sendCalls()[0]['site_help'] ?? '');
        $this->assertStringContainsString('## ' . $this->title('money_missing'), $help);
        $this->assertStringContainsString($line, $help);

        // แจ้งทีมงานแล้ว (กันซ้ำ 30 นาทีต่อลูกค้าต่อหัวข้อ) — ข้อความที่สองไม่ยิงการ์ดซ้ำ
        $this->assertTrue(Cache::has('alert:throttle:' . sha1("site-help:money_missing:u{$user->id}")));
        // (บิลที่ออกด้านบนก็แจ้ง Telegram ของมันเอง — นับเฉพาะการ์ด "ขอความช่วยเหลือ")
        $cards = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.telegram.org')
            && str_contains($p[0]->body(), 'ขอความช่วยเหลือในแชท'));
        $this->assertCount(1, $cards, 'แจ้งทีมงานครั้งเดียว ไม่ใช่ทุกข้อความ');
    }

    public function test_a_help_question_is_free_even_when_chat_is_paid_and_the_wallet_is_empty(): void
    {
        Setting::put('pricing_chat_message_enabled', '1', 'pricing', false);
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'เติมเงินยังไงคะ', 'room' => 'maemor'])->assertOk();
        $res->assertJsonPath('kind', 'help');
        $this->assertSame(0.0, (float) $res->json('cost'));
        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->where('type', 'debit')->count());

        // ข้อความทั่วไปยังคิดเงินตามเดิม (เครดิตไม่พอ = 402)
        $this->actingAs($user)->postJson('/chat/send', ['message' => 'สวัสดีค่ะ', 'room' => 'maemor'])->assertStatus(402);
    }

    public function test_a_help_turn_without_the_oracle_gets_the_busy_note_not_a_guessing_fallback(): void
    {
        $this->upstreamDown = true;

        $res = $this->actingAs($this->member())->postJson('/chat/send', ['message' => 'ชำระตรงไหนค่ะ', 'room' => 'maemor'])->assertOk();

        $this->assertTrue((bool) $res->json('degraded'));
        $this->assertSame(0.0, (float) $res->json('cost'));
    }

    public function test_the_pending_bill_hold_says_how_to_pay_or_cancel(): void
    {
        $user = $this->member();
        $ref = (string) $this->actingAs($user)->postJson(route('chat.topup.store'), ['amount' => 100])->json('reference_code');

        $res = $this->actingAs($user)->postJson('/chat/send', ['message' => 'เปิดไพ่ให้หน่อย', 'room' => 'maemor'])->assertOk();

        $this->assertSame([], $res->json('offers'));
        $reply = (string) $res->json('reply');
        $this->assertStringContainsString($ref, $reply);
        $this->assertStringContainsString('"ดู QR จ่ายต่อ"', $reply);
        $this->assertStringContainsString('"ยกเลิกรายการนี้"', $reply);
    }

    public function test_the_switch_turns_the_guide_off(): void
    {
        config(['site_help.enabled' => false]);

        $this->assertSame([], SiteHelp::match('ชำระตรงไหนค่ะ'));
    }

    public function test_the_guide_fills_live_prices_and_leaves_no_placeholders(): void
    {
        $block = SiteHelp::block(['credits_prices', 'contact_admin'], $this->member());

        $this->assertStringNotContainsString('{prices}', $block);
        $this->assertStringNotContainsString('{line}', $block);
        $this->assertStringNotContainsString('{chat_cost}', $block);
        $this->assertStringContainsString(Pricing::format(Pricing::for('celtic')), $block);
        $this->assertStringContainsString('เครดิตคงเหลือ ฿0.00', $block);
    }
}
