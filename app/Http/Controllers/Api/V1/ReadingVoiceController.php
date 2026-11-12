<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Services\Voice\ReadingVoiceBuilder;
use App\Support\VoicePremium;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReadingVoiceController extends Controller
{
    private function authorizeVoice(Request $request, Reading $reading): void
    {
        abort_unless($reading->user_id === $request->user()->id, 403);
        abort_unless(VoicePremium::active($reading), 403);
    }

    public function next(Request $request, Reading $reading, ReadingVoiceBuilder $builder): JsonResponse
    {
        // A refunded premium still answers like the web (refunded: true) so a polling app learns why
        // the narration stopped; the mp3 itself stays paid-only (authorizeVoice in file()).
        abort_unless($reading->user_id === $request->user()->id, 403);
        abort_unless(is_array(data_get($reading->payload, 'premium')), 403);
        $response = app(\App\Http\Controllers\ReadingVoiceController::class)->next($request, $reading, $builder);
        $data = $response->getData(true);
        $reading->refresh();
        $voice = data_get($reading->payload, 'voice');
        if (VoicePremium::active($reading) && is_array($voice) && in_array($voice['status'] ?? '', ['ready', 'partial'], true)) {
            $data['voice'] = array_intersect_key($voice, array_flip(['status', 'duration', 'chapters']));
            $data['voice']['url'] = self::url($reading);
        } else {
            $data['voice'] = null;
        }

        return response()->json($data, $response->getStatusCode());
    }

    public static function url(Reading $reading): string
    {
        return rtrim((string) config('app.url'), '/').route('api.v1.history.voice.file', $reading, false);
    }

    public function file(Request $request, Reading $reading)
    {
        $this->authorizeVoice($request, $reading);

        return app(\App\Http\Controllers\ReadingVoiceController::class)->file($request, $reading);
    }
}
