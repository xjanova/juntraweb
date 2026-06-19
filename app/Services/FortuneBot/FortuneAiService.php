<?php

namespace App\Services\FortuneBot;

use App\Models\Reading;
use App\Models\User;
use App\Services\AiOracle;
use App\Support\TarotSpreads;
use Illuminate\Support\Facades\Log;

/**
 * Routes fortune AI requests to Thaiprompt's API pool first (so juntra
 * users get the same model rotation, sensitive-mode handling, and
 * billing telemetry as the FB Messenger and LINE OA bots) and falls
 * back to the local AiOracle (Gemini key on this machine) only when
 * upstream is unavailable.
 *
 * juntra still holds NO production AI keys — the local fallback exists
 * solely so the page renders something useful when the operator hasn't
 * yet linked the user's Thaiprompt account, OR when Thaiprompt is
 * temporarily unreachable.
 */
class FortuneAiService
{
    public function __construct(
        private FortuneBotClient $bot,
        private AiOracle $oracle,
    ) {}

    /**
     * Interpret a tarot spread.
     *
     * @return array{text: string, provider: string, model: string, source: 'thaiprompt'|'local'}
     */
    public function interpretTarot(Reading $reading, ?User $user): array
    {
        $key = TarotSpreads::keyFromType($reading->type);

        // Build the structured payload the upstream tarot endpoint expects.
        // `cards` carry each position's `asks` (TarotPromptBuilder::payloadCards)
        // and `prompt` is the full Card-First Mandate text so the chat-pipe
        // fallback reads card × position exactly like the local path.
        $payload = [
            'spread'      => $reading->type,
            'spread_key'  => $key,
            'spread_name' => $key ? (TarotSpreads::get($key)['name_th'] ?? null) : null,
            'question'    => $reading->question,
            'cards'       => TarotPromptBuilder::payloadCards($reading),
            'prompt'      => TarotPromptBuilder::userPrompt($reading),
        ];

        if ($this->bot->isAvailable($user)) {
            $remote = $this->bot->interpretTarot($user, $payload);
            if ($remote && !empty($remote['interpretation'])) {
                return [
                    'text'     => $remote['interpretation'],
                    'provider' => $remote['ai_provider'] ?? 'thaiprompt',
                    'model'    => $remote['ai_model']    ?? 'pool',
                    'source'   => 'thaiprompt',
                ];
            }
            Log::info('FortuneAiService: upstream returned null/empty — using local fallback', [
                'user_id' => $user?->id,
                'reading' => $reading->id,
            ]);
        }

        return [
            'text'     => $this->oracle->interpretTarotReading($reading),
            'provider' => $this->oracle->provider(),
            'model'    => $this->oracle->model(),
            'source'   => 'local',
        ];
    }
}
