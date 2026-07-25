<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\ChatSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ปุ่มคำถามลัดต้อง "ไม่ขัดกับโฟลว์การทำนาย"
 *
 * บอทฝั่ง Thaiprompt เดินเป็น state machine: เมื่อแม่หมอถามขอข้อมูล
 * (วันเกิด / ชื่อ / ให้เลือกข้อ) ข้อความถัดไปที่ส่งไปจะถูกตีความเป็น
 * "คำตอบ" ของคำถามนั้น ถ้าผู้ใช้เผลอกดปุ่มคำถามทั่วไปตอนนั้น โฟลว์ทำนาย
 * จะพังทันที — UI จึงต้องยุบแถบปุ่มเมื่อ isAwaitingAnswer() เป็นจริง
 */
class ChatSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public static function awaitingCases(): array
    {
        return [
            'ขอวันเกิด'      => ['ก่อนอื่นบอกแม่หมอหน่อยค่ะ ลูกเกิดวันเดือนปีเกิดอะไรคะ', true],
            'ขอชื่อ'         => ['แม่หมอขอทราบชื่อจริงของลูกก่อนนะคะ', true],
            'ให้เลือกข้อ'    => ['ลูกอยากให้แม่หมอดูเรื่องไหนคะ เลือกข้อที่ตรงใจได้เลย', true],
            'ให้ยืนยัน'      => ['แม่หมอเข้าใจถูกไหมคะ ใช่หรือไม่', true],
            'ให้พิมพ์ตัวเลข' => ['พิมพ์ตัวเลขที่ลูกนึกถึงมา 1 ตัวนะคะ', true],

            // คำตอบปกติ ไม่ได้รอข้อมูล → ปุ่มลัดต้องโชว์ตามปกติ
            'คำทำนายปกติ'    => ['ดวงความรักของลูกช่วงนี้กำลังเปิดค่ะ มีคนแอบมองอยู่นะคะ', false],
            'ปิดท้ายให้กำลังใจ' => ['ขอให้ลูกโชคดีนะคะ แม่หมอเป็นกำลังใจให้เสมอค่ะ', false],
            'ว่าง'           => ['', false],
        ];
    }

    /** @dataProvider awaitingCases */
    public function test_detects_when_mae_mor_is_waiting_for_an_answer(string $reply, bool $expected): void
    {
        $this->assertSame($expected, ChatSuggestions::isAwaitingAnswer($reply), $reply);
    }

    /** ชุดปุ่มต้องเปลี่ยนตามสถานะ ไม่ใช่ชุดตายตัวชุดเดียว */
    public function test_suggestion_sets_differ_per_state(): void
    {
        $fresh   = ChatSuggestions::forState('fresh');
        $flowing = ChatSuggestions::forState('flowing');
        $reading = ChatSuggestions::forState('reading');

        $this->assertNotEmpty($fresh);
        $this->assertNotEmpty($flowing);
        $this->assertNotEmpty($reading);

        // เริ่มต้น = ชวนเปิดเรื่อง · คุยไปแล้ว = ต่อยอดคำตอบล่าสุด
        $this->assertNotSame(
            array_column($fresh, 'label'),
            array_column($flowing, 'label'),
            'ปุ่มตอนเริ่มคุยกับตอนคุยไปแล้วต้องไม่ใช่ชุดเดียวกัน',
        );

        // ชุดของไพ่ต้องอ้างอิงไพ่ ไม่ใช่คำถามลอย ๆ
        $this->assertStringContainsString('ไพ่', implode(' ', array_column($reading, 'prompt')));

        // ทุกชิปต้องมีครบ 3 ฟิลด์ ไม่งั้น UI จะ render ปุ่มเปล่า
        foreach ([...$fresh, ...$flowing, ...$reading] as $chip) {
            $this->assertArrayHasKey('label', $chip);
            $this->assertArrayHasKey('prompt', $chip);
            $this->assertArrayHasKey('icon', $chip);
            $this->assertNotSame('', trim($chip['label']));
            $this->assertNotSame('', trim($chip['prompt']));
        }
    }

    /** หน้าแชทต้อง render ปุ่มคำถามลัดจริง ๆ ไม่ใช่แค่มีในโค้ด */
    public function test_chat_page_renders_quick_prompt_chips(): void
    {
        Http::fake([
            '*/chat/mae-mor/start' => Http::response(['data' => ['session_id' => 'sess-test']], 200),
            '*'                    => Http::response([], 200),
        ]);
        Setting::put('pricing_chat_message', '0', 'pricing', false);
        Cache::flush();

        $user = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);

        $res = $this->actingAs($user)->get('/chat');

        $res->assertOk();
        $res->assertSee('เริ่มจากคำถามเหล่านี้ก็ได้ค่ะ');   // ป้ายกำกับแถบปุ่ม
        $res->assertSee('หมวดคำถาม');                       // ปุ่มกางหมวด
        $res->assertSee('chat-suggest', false);             // แถบปุ่มอยู่ในหน้าจริง

        // ตัวชิปถูก render ฝั่ง client จาก JSON (@js escape ยูนิโค้ด) จึงเช็คที่
        // ข้อมูลที่ส่งเข้า view แทนการหาตัวอักษรไทยดิบใน HTML
        $res->assertViewHas('suggestions', fn ($s) => collect($s)->contains(
            fn ($chip) => $chip['label'] === 'ดวงรวมวันนี้'
        ));
    }

    /** อ่านประวัติย้อนหลัง = ห้ามมีปุ่มที่กดแล้วไม่เกิดอะไร */
    public function test_readonly_history_has_no_quick_prompt_chips(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Setting::put('pricing_chat_message', '0', 'pricing', false);
        Cache::flush();

        $user  = User::factory()->create(['thaiprompt_token' => 'tok-'.Str::random(6)]);
        $convo = \App\Models\ChatConversation::create([
            'user_id'       => $user->id,
            'session_token' => (string) Str::uuid(),
            'title'         => 'เก่า',
        ]);

        $res = $this->actingAs($user)->get("/chat/conversation/{$convo->id}");

        $res->assertOk();
        $res->assertSee('นี่คือประวัติการสนทนา');
        $res->assertDontSee('เริ่มจากคำถามเหล่านี้ก็ได้ค่ะ');
    }
}
