<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Models\TarotReadingCard;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guards the "ปรึกษาแม่หมอต่อจากไพ่ชุดนี้" entry (chat.from-reading).
 *
 * Rules it locks in:
 *   - Only the reading's OWNER may consult it (privacy / IDOR).
 *   - Priming แม่หมอ with the drawn cards NEVER debits the wallet — the charge
 *     happens later, per follow-up message, on the normal /chat/send path.
 *   - Owners who haven't linked Facebook/LINE are bounced to /chat (which
 *     renders the connect CTA) rather than silently entering a dead chat.
 */
class TarotConsultFromReadingTest extends TestCase
{
    use RefreshDatabase;

    private function linkedMember(): User
    {
        $u = User::factory()->create([
            'facebook_user_id' => 'fb-' . Str::random(6), // passes FB/LINE gate
            'thaiprompt_token' => 'tok-' . Str::random(6), // makes the bot "available"
        ]);
        app(WalletService::class)->credit($u, 100, 'seed');
        return $u;
    }

    private function readingFor(User $u): Reading
    {
        $card = TarotCard::create([
            'slug' => 'the-star', 'name_en' => 'The Star', 'name_th' => 'ดวงดาว',
            'arcana' => 'major', 'suit' => 'major', 'number' => 17,
            'upright_meaning_th' => 'ความหวัง', 'reversed_meaning_th' => 'สิ้นหวัง',
            'active' => true,
        ]);

        $reading = Reading::create([
            'user_id'       => $u->id,
            'session_token' => (string) Str::uuid(),
            'type'          => 'tarot_single',
            'question'      => null,
            'payload'       => ['positions' => ['คำตอบของไพ่'], 'cost' => 9],
            'result'        => 'คำพยากรณ์ทดสอบ',
        ]);
        TarotReadingCard::create([
            'reading_id'     => $reading->id,
            'tarot_card_id'  => $card->id,
            'position'       => 1,
            'position_label' => 'คำตอบของไพ่',
            'reversed'       => false,
        ]);

        return $reading;
    }

    /** A member cannot consult a reading that isn't theirs. */
    public function test_non_owner_is_forbidden(): void
    {
        $owner   = $this->linkedMember();
        $reading = $this->readingFor($owner);
        $other   = $this->linkedMember();

        $this->actingAs($other)
            ->post("/chat/from-reading/{$reading->id}", ['question' => 'x'])
            ->assertForbidden();
    }

    /** Priming แม่หมอ with the drawn cards must be free — no debit here. */
    public function test_priming_never_charges_and_carries_question(): void
    {
        Http::fake([
            '*/chat/mae-mor/start' => Http::response(['data' => ['session_id' => 'sess-1']], 200),
            '*/chat/mae-mor/send'  => Http::response(['data' => ['reply' => 'แม่หมอเห็นไพ่แล้วค่ะ']], 200),
        ]);

        $owner   = $this->linkedMember();
        $reading = $this->readingFor($owner);

        $resp = $this->actingAs($owner)
            ->post("/chat/from-reading/{$reading->id}", ['question' => 'เรื่องงานเป็นอย่างไร']);

        $resp->assertRedirect(route('chat.index'));
        $this->assertSame(100.0, app(WalletService::class)->balance($owner), 'priming must not debit the wallet');
        $resp->assertSessionHas('chat_autosend', 'เรื่องงานเป็นอย่างไร');
        $resp->assertSessionHas('chat_primed_reading', $reading->id);

        // A grounded greeting (the priming reply) is stored, ready to show.
        $convo = ChatConversation::where('user_id', $owner->id)->firstOrFail();
        $this->assertSame(1, $convo->messages()->where('role', 'assistant')->count());
    }

    /** Owner without a Facebook/LINE link is routed to /chat to connect first. */
    public function test_unlinked_owner_bounced_to_chat(): void
    {
        $owner = User::factory()->create(); // no fb/line link, no token
        app(WalletService::class)->credit($owner, 100, 'seed');
        $reading = $this->readingFor($owner);

        $this->actingAs($owner)
            ->post("/chat/from-reading/{$reading->id}", ['question' => 'x'])
            ->assertRedirect(route('chat.index'));

        // Nothing primed, nothing to auto-send.
        $this->assertDatabaseMissing('chat_messages', ['role' => 'assistant']);
    }
}
