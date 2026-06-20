<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TarotCard;
use Illuminate\Http\JsonResponse;

/**
 * Public tarot card catalog for the juntra mobile app.
 *
 * The Flutter app ships a built-in, programmatically-drawn deck (78 cards)
 * that it uses as a FALLBACK. Its preference, though, is the real
 * Rider-Waite art served from จันทรา.online. This endpoint hands the app
 * every card's resolved face-image URL — or `null` when a card has no
 * uploaded art yet — plus the global card-back image, so the cinematic and
 * reading screens render real faces and fall back to the local drawing only
 * when an image is missing or unreachable.
 *
 * Public (no auth) on purpose: the shuffle cinematic shows card faces before
 * the seeker signs in. Carries a short Cache-Control so CDNs/clients can
 * cheaply re-use it; the app also caches the envelope on-device for offline.
 */
class TarotController extends Controller
{
    public function cards(): JsonResponse
    {
        // Canonical order: the seeder inserts Major 0–21 then the four suits,
        // so `id` ASC keeps the deck in a sensible order. We intentionally
        // avoid DB-specific ordering (e.g. MySQL FIELD()) so the same query
        // runs under the sqlite test harness.
        $cards = TarotCard::query()
            ->where('active', true)
            ->orderBy('id')
            ->get();

        $data = $cards->map(fn (TarotCard $c) => [
            'slug'           => $c->slug,
            'name_en'        => $c->name_en,
            'name_th'        => $c->name_th,
            'arcana'         => $c->arcana,
            'suit'           => $c->suit,
            'number'         => $c->number,
            // Real uploaded art, or null → app draws its own built-in face.
            'image_url'      => $c->faceImageUrl(),
            'has_face_image' => $c->hasFaceImage(),
        ])->values();

        // The card-back is a single global setting, independent of any one
        // card — resolve it off a bare model so an empty deck still answers.
        $cardBackUrl = (new TarotCard())->cardBackUrl();

        // Cheap cache key the app stores alongside the cached envelope: when
        // an operator re-imports art (touches updated_at) or adds a card, the
        // version changes and clients know fresh art is available.
        $latest  = optional($cards->max('updated_at'));
        $version = sha1(($latest?->toIso8601String() ?? '') . '|' . $cards->count());

        return response()
            ->json([
                'data'          => $data,
                'card_back_url' => $cardBackUrl,
                'count'         => $cards->count(),
                'version'       => $version,
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
