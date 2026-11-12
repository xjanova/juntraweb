<?php

namespace App\Support;

use App\Models\Reading;

final class ReadingPresentation
{
    public static function details(Reading $reading): array
    {
        $spread = TarotSpreads::keyFromType((string) $reading->type);
        $persona = ChatRooms::normalize(data_get($reading->payload, 'room') ?? ChatRooms::roomForSpread($spread));
        $card = collect(ChatRooms::cards())->firstWhere('key', $persona) ?? [];
        $premium = data_get($reading->payload, 'premium');
        $review = $reading->review;

        return [
            'persona' => $persona,
            'room' => ['key' => $persona, 'name' => ChatRooms::name($persona), 'short' => ChatRooms::short($persona), 'image_url' => $card['image'] ?? null],
            'reader_name' => ChatRooms::readerName($spread),
            'premium' => is_array($premium) ? ['status' => (string) ($premium['status'] ?? 'paid'), 'price' => (float) ($premium['price'] ?? 0)] : null,
            'review' => $review ? self::review($review) : null,
            'share' => ['public' => (bool) $reading->shared_public, 'url' => $reading->shared_public ? route('tarot.show', $reading) : null],
            'qa_minutes' => ReadingQaClock::minutesFor($reading),
        ];
    }

    public static function review(\App\Models\Testimonial $review): array
    {
        return ['rating' => (int) $review->rating, 'message' => $review->message, 'allow_public' => (bool) $review->allow_public];
    }
}
