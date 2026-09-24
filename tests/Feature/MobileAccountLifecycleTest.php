<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ContentReport;
use App\Models\Reading;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 📱 (2026-09-24) บัญชีจากในแอพ — สิ่งที่ Google Play บังคับ:
 *  - ลบบัญชีได้ในแอพ และลบ "จริง" (ของเดิมบนเว็บเหลือเบอร์ วันเกิด คำถาม แชท รูปลายมือไว้ครบ
 *    และเบอร์ที่ค้างทำให้สมัครใหม่ด้วยเบอร์เดิมไม่ได้)
 *  - รายงานเนื้อหาที่ AI สร้างได้ในแอพ
 * และเปลี่ยนรหัสผ่านในแอพ (เดิมต้องเปิดเว็บ) — เพิกถอนเครื่องอื่นทั้งหมด
 */
class MobileAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function userWithToken(array $attrs = []): array
    {
        $u = User::factory()->create(array_merge(['password' => Hash::make('secret-pass-1'), 'phone' => '0812345678'], $attrs));

        return [$u, $u->createToken('phone-a')->plainTextToken];
    }

    private function seedFootprint(User $u): array
    {
        Storage::fake('public');
        Storage::fake('local');
        $wallet = app(WalletService::class);
        $wallet->credit($u, 100, 'seed');
        $debit = $wallet->debit($u, 9, 'เปิดไพ่', ['reference_type' => 'reading']);

        $palm = UploadedFile::fake()->image('palm.jpg')->store('palmistry', 'public');
        $reading = Reading::create([
            'user_id' => $u->id, 'session_token' => (string) Str::uuid(), 'type' => 'tarot_single',
            'question' => 'แฟนเก่าจะกลับมาไหม ชื่อสมศรี', 'result' => 'คำทำนายส่วนตัว',
            'payload' => ['cost' => 9, 'wallet_tx_id' => $debit->id, 'birth_date' => '1990-01-01', 'image_path' => $palm],
        ]);
        $conv = ChatConversation::create(['user_id' => $u->id, 'session_token' => (string) Str::uuid(), 'title' => 'คุยเรื่องงาน']);
        ChatMessage::create(['chat_conversation_id' => $conv->id, 'role' => 'user', 'content' => 'เงินเดือนผมสามหมื่น']);
        $u->profile()->create(['birth_date' => '1990-01-01', 'birth_place' => 'เชียงใหม่']);

        $slip = UploadedFile::fake()->image('slip.jpg')->store('slips', 'local');
        $pending = WalletTransaction::create([
            'user_id' => $u->id, 'wallet_id' => $u->wallet->id, 'type' => 'topup', 'status' => 'pending',
            'amount' => '100.00', 'description' => 'เติมเงิน', 'method' => 'promptpay', 'slip_path' => $slip,
            'meta' => ['sender_name' => 'นายสมชาย ใจดี'],
        ]);

        return compact('reading', 'conv', 'palm', 'slip', 'pending', 'debit');
    }

    public function test_wrong_password_deletes_nothing(): void
    {
        [$u, $token] = $this->userWithToken();

        $this->withToken($token)->postJson('/api/v1/account/delete', ['password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertNull($u->fresh()->deleted_at);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_in_app_deletion_erases_personal_data_and_keeps_the_ledger(): void
    {
        [$u, $token] = $this->userWithToken(['email' => 'somchai@example.com']);
        $other = $u->createToken('phone-b')->plainTextToken;
        $f = $this->seedFootprint($u);

        $this->withToken($token)->postJson('/api/v1/account/delete', ['password' => 'secret-pass-1'])
            ->assertOk()->assertJsonPath('data.deleted', true);

        $gone = User::withTrashed()->find($u->id);
        $this->assertNotNull($gone->deleted_at);
        $this->assertNull($gone->phone, 'เบอร์โทรต้องหาย (และเปิดให้สมัครใหม่ได้)');
        $this->assertSame('deleted+' . $u->id . '@deleted.local', $gone->email);
        $this->assertNotSame($u->name, $gone->name);
        $this->assertSame(0, $gone->tokens()->count(), 'ทุกเครื่องต้องออกจากระบบ');
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $reading = $f['reading']->fresh();
        $this->assertNull($reading->question);
        $this->assertNull($reading->result);
        $this->assertArrayNotHasKey('birth_date', $reading->payload);
        $this->assertSame(9, $reading->payload['cost'], 'บิลยังนับรายได้ได้');
        Storage::disk('public')->assertMissing($f['palm']);

        $this->assertSame(0, ChatConversation::where('user_id', $u->id)->count());
        $this->assertSame(0, ChatMessage::where('chat_conversation_id', $f['conv']->id)->count());
        $this->assertNull($u->profile()->first());

        $pending = $f['pending']->fresh();
        $this->assertSame('cancelled', $pending->status);
        $this->assertNull($pending->slip_path);
        $this->assertArrayNotHasKey('sender_name', (array) $pending->meta);
        Storage::disk('local')->assertMissing($f['slip']);
        $this->assertSame('success', $f['debit']->fresh()->status, 'แถวบัญชีการเงินยังอยู่');
    }

    public function test_the_same_phone_can_register_again_after_deletion(): void
    {
        [$u, $token] = $this->userWithToken();
        $this->withToken($token)->postJson('/api/v1/account/delete', ['password' => 'secret-pass-1'])->assertOk();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'สมัครใหม่', 'phone' => '081-234-5678',
            'password' => 'another-pass-2', 'password_confirmation' => 'another-pass-2',
        ])->assertCreated();
    }

    public function test_web_profile_deletion_erases_the_same_things(): void
    {
        $u = User::factory()->create(['password' => Hash::make('secret-pass-1'), 'phone' => '0899999999']);
        $f = $this->seedFootprint($u);

        $this->actingAs($u)->delete('/profile', ['password' => 'secret-pass-1'])->assertRedirect('/');

        $this->assertNull(User::withTrashed()->find($u->id)->phone);
        $this->assertNull($f['reading']->fresh()->question);
        $this->assertSame(0, ChatConversation::where('user_id', $u->id)->count());
    }

    public function test_change_password_keeps_this_phone_and_signs_out_the_others(): void
    {
        [$u, $token] = $this->userWithToken();
        $other = $u->createToken('stolen-phone')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);

        $this->withToken($token)->putJson('/api/v1/auth/password', [
            'current_password' => 'secret-pass-1', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $u->fresh()->password));
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_reporting_ai_content_reaches_the_admin_queue(): void
    {
        [$u, $token] = $this->userWithToken();
        $reading = Reading::create([
            'user_id' => $u->id, 'session_token' => (string) Str::uuid(), 'type' => 'tarot_three',
            'result' => 'คำทำนายที่ไม่เหมาะสม',
        ]);

        $r = $this->withToken($token)->postJson('/api/v1/reports', [
            'subject_type' => 'reading', 'subject_id' => $reading->id, 'reason' => 'offensive', 'note' => 'ใช้คำไม่ดี',
        ])->assertCreated();

        $report = ContentReport::sole();
        $this->assertSame('คำทำนายที่ไม่เหมาะสม', $report->snapshot);
        $this->assertSame('open', $report->status);

        // กดซ้ำ = รายการเดิม
        $this->withToken($token)->postJson('/api/v1/reports', [
            'subject_type' => 'reading', 'subject_id' => $reading->id, 'reason' => 'harmful',
        ])->assertOk()->assertJsonPath('data.id', $r->json('data.id'));
        $this->assertSame(1, ContentReport::count());
    }

    /**
     * แอพถาม /status ทุก 3 วินาทีระหว่างแม่หมออ่านไพ่ (~20 ครั้งต่อนาที) แล้วลูกค้ามักกดรายงานคำทำนายนั้นทันที
     * throttle:N,M ที่ไม่ใส่ prefix ใช้ตัวนับเดียวกันทั้งแอพต่อผู้ใช้ — เส้นใหม่ของแอพต้องมีตัวนับของตัวเอง
     * ไม่งั้นการ poll กินโควตาของรายงาน/เติมเครดิต/เปลี่ยนรหัส/ลบบัญชี จนตอบ 429
     */
    public function test_polling_a_reading_does_not_use_up_the_other_app_limits(): void
    {
        [$u, $token] = $this->userWithToken();
        $reading = Reading::create([
            'user_id' => $u->id, 'session_token' => (string) Str::uuid(), 'type' => 'tarot_three', 'result' => 'คำทำนาย',
        ]);

        for ($i = 0; $i < 20; $i++) {
            $this->withToken($token)->getJson("/api/v1/history/readings/{$reading->id}/status")->assertOk();
        }

        $this->withToken($token)->postJson('/api/v1/reports', [
            'subject_type' => 'reading', 'subject_id' => $reading->id, 'reason' => 'inaccurate',
        ])->assertCreated();
        $this->withToken($token)->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong-pass', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123',
        ])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/account/delete', ['password' => 'wrong-pass'])->assertStatus(422);
        $this->assertNotSame(429, $this->withToken($token)->postJson('/api/v1/wallet/google-play/redeem', [
            'product_id' => 'juntra_credits_50', 'purchase_token' => str_repeat('t', 20),
        ])->status());
    }

    public function test_only_my_own_ai_content_can_be_reported(): void
    {
        [$u, $token] = $this->userWithToken();
        $someoneElse = Reading::create([
            'user_id' => User::factory()->create()->id, 'session_token' => (string) Str::uuid(), 'type' => 'tarot_three', 'result' => 'x',
        ]);
        $conv = ChatConversation::create(['user_id' => $u->id, 'session_token' => (string) Str::uuid()]);
        $mine = ChatMessage::create(['chat_conversation_id' => $conv->id, 'role' => 'user', 'content' => 'คำถามของฉันเอง']);
        $maeMor = ChatMessage::create(['chat_conversation_id' => $conv->id, 'role' => 'assistant', 'content' => 'คำตอบจากแม่หมอ']);

        $this->withToken($token)->postJson('/api/v1/reports', ['subject_type' => 'reading', 'subject_id' => $someoneElse->id, 'reason' => 'other'])
            ->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/reports', ['subject_type' => 'chat_message', 'subject_id' => $mine->id, 'reason' => 'other'])
            ->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/reports', ['subject_type' => 'chat_message', 'subject_id' => $maeMor->id, 'reason' => 'inaccurate'])
            ->assertCreated();
        $this->assertSame('คำตอบจากแม่หมอ', ContentReport::sole()->snapshot);
    }
}
