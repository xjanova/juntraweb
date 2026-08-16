<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TarotCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
    /**
     * POST /v1/tarot/deal — สับไพ่หนึ่งกอง แล้วให้ไคลเอนต์เล่นซีนบนกองนี้
     *
     * 🔴 ทำไมต้องมี: สำรับในแอพเป็น `const` เรียง id 0–77 ตายตัว และซีน "สับไพ่"
     * เป็นแอนิเมชันล้วน ไม่เคยแตะโครงสร้างกองเลย ผลคือช่องซ้ายบนสุดคือ The Fool
     * ตลอดกาล ผู้ใช้ที่เปิดไพ่สองครั้งแล้วแตะตำแหน่งเดิมได้ไพ่ชุดเดิมเป๊ะ
     * ขณะที่เว็บสับจริงด้วย `inRandomOrder()` ทุกครั้ง
     *
     * และหัวตั้ง/หัวกลับก็ถูกตัดสินที่เซิร์ฟเวอร์ตรงนี้ (`random_int(0,1)` =
     * 50% เท่าเว็บ) ไม่ใช่ให้แอพสุ่มเอง 30% แล้วส่งค่าขึ้นมาให้เชื่อ
     *
     * กองถูกเก็บใน cache ตาม token 30 นาที — ตอนบันทึกผลผู้ใช้ส่งแค่
     * "ตำแหน่งที่แตะ" เซิร์ฟเวอร์เป็นคนแปลงกลับเป็นไพ่เอง
     */
    public function deal(Request $request): JsonResponse
    {
        $cards = TarotCard::query()
            ->where('active', true)
            ->get(['id', 'slug', 'name_th', 'name_en'])
            ->shuffle()
            ->values();

        if ($cards->isEmpty()) {
            return response()->json([
                'message'     => 'ยังไม่มีไพ่ในระบบ',
                'reason_code' => 'empty_deck',
            ], 503);
        }

        $deal = $cards->map(fn (TarotCard $c) => [
            'slug'     => $c->slug,
            'name_th'  => $c->name_th,
            'name_en'  => $c->name_en,
            'reversed' => (bool) random_int(0, 1),
        ])->all();

        $token = Str::random(40);
        Cache::put(
            self::dealCacheKey($request->user()->id, $token),
            array_map(fn (array $c) => ['slug' => $c['slug'], 'reversed' => $c['reversed']], $deal),
            now()->addMinutes(30),
        );

        return response()->json([
            'data' => [
                'deal_token' => $token,
                'expires_in' => 1800,
                'cards'      => $deal,
            ],
        ], 201);
    }

    /** คีย์แคชของกองไพ่ — ผูกกับ user ด้วย เพื่อไม่ให้ token ของคนอื่นใช้ข้ามกันได้ */
    public static function dealCacheKey(int $userId, string $token): string
    {
        return 'tarot_deal:' . $userId . ':' . hash('sha256', $token);
    }

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
