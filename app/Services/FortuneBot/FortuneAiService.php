<?php

namespace App\Services\FortuneBot;

use App\Models\Reading;
use App\Models\User;
use App\Services\AiOracle;
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
        $cards = $reading->tarotCards()->with('card')->orderBy('position')->get();

        // Build the structured payload the upstream tarot endpoint expects.
        $payload = [
            'spread'   => $reading->type, // tarot_three | tarot_celtic
            'question' => $reading->question,
            'cards'    => $cards->map(fn ($pc) => [
                'position'       => $pc->position,
                'position_label' => $pc->position_label,
                'reversed'       => (bool) $pc->reversed,
                'name_th'        => $pc->card->name_th,
                'name_en'        => $pc->card->name_en ?? null,
                'slug'           => $pc->card->slug,
                'arcana'         => $pc->card->arcana ?? null,
                'meaning'        => $pc->reversed
                    ? $pc->card->reversed_meaning_th
                    : $pc->card->upright_meaning_th,
            ])->toArray(),
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
