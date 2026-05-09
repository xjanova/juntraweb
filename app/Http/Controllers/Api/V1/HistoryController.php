<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile reading history — tarot / numerology / palmistry / auspicious
 * readings the user has paid for, in reverse-chronological order.
 *
 * Wraps the existing `readings` table that all the web controllers
 * persist into; juntra mobile shows the list under "ดูดวงล่าสุด" on the
 * home screen and the History tab.
 */
class HistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'limit'  => 'sometimes|integer|min:1|max:100',
            'cursor' => 'sometimes|nullable|string',
            'type'   => 'sometimes|string|in:tarot_three,tarot_celtic,numerology,palmistry,auspicious',
        ]);
        $limit = (int) $request->input('limit', 20);

        $q = Reading::where('user_id', $request->user()->id)
            ->orderByDesc('created_at');
        if ($type = $request->input('type')) {
            $q->where('type', $type);
        }
        if ($cursor = $request->input('cursor')) {
            $q->where('id', '<', (int) $cursor);
        }
        $rows = $q->limit($limit + 1)->get();
        $nextCursor = null;
        if ($rows->count() > $limit) {
            $nextCursor = (string) $rows->last()->id;
            $rows = $rows->take($limit);
        }

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->readingSummary($r))->values(),
            'meta' => ['next_cursor' => $nextCursor],
        ]);
    }

    public function show(Request $request, Reading $reading): JsonResponse
    {
        $user = $request->user();
        $isOwner = $reading->user_id === $user->id;
        $isAdmin = method_exists($user, 'isAdmin') && $user->isAdmin();
        // Same privacy gate as the web `/tarot/result/{id}` route — owner
        // OR admin OR explicitly public-shared.
        if (!$isOwner && !$isAdmin && !($reading->shared_public ?? false)) {
            abort(403);
        }

        $cards = [];
        // Only tarot readings have associated tarot_reading_cards rows.
        if (str_starts_with((string) $reading->type, 'tarot_')) {
            $cards = $reading->tarotCards()->get()->map(fn ($c) => [
                'position'       => $c->position,
                'position_label' => $c->position_label,
                'name_en'        => $c->name_en,
                'name_th'        => $c->name_th,
                'reversed'       => (bool) $c->reversed,
                'meaning'        => $c->meaning,
            ])->values();
        }

        return response()->json([
            'data' => array_merge($this->readingSummary($reading), [
                'question'      => $reading->question,
                'result'        => $reading->result,
                'payload'       => $reading->payload,
                'ai_provider'   => $reading->ai_provider,
                'ai_model'      => $reading->ai_model,
                'shared_public' => (bool) ($reading->shared_public ?? false),
                'cards'         => $cards,
            ]),
        ]);
    }

    private function readingSummary(Reading $r): array
    {
        return [
            'id'         => $r->id,
            'type'       => $r->type,
            'preview'    => mb_substr((string) ($r->result ?? ''), 0, 140),
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }
}
