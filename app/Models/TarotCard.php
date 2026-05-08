<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TarotCard extends Model
{
    protected $fillable = [
        'slug', 'name_en', 'name_th', 'arcana', 'suit', 'number',
        'image_path', 'keywords_th',
        'upright_meaning_th', 'reversed_meaning_th',
        'love_th', 'career_th', 'money_th', 'active',
    ];

    protected $casts = ['active' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve the public URL for this card's face image.
     *
     * Image path conventions, in order of precedence:
     *   1. Absolute URL                       → returned as-is (CDN, etc.)
     *   2. `images/...`                       → public/images/ — `asset()`
     *   3. storage path that exists on disk   → Storage url (e.g. tarot/cards/<slug>.webp)
     *   4. `images/card-magician.png` (the legacy default placeholder) OR null
     *      → fallback to the operator-uploaded card-back if one is configured,
     *        otherwise the legacy magician placeholder.
     *
     * The card-back fallback is what makes Minor Arcana (without their own art)
     * render as a beautiful face-down card rather than 56 identical Magician
     * images on the result page.
     */
    public function imageUrl(): string
    {
        $path = $this->image_path;

        // Treat both null AND the legacy magician default as "no real face image"
        // so they both fall through to the card-back if one is set.
        $isPlaceholder = !$path || $path === 'images/card-magician.png';

        if (!$isPlaceholder) {
            if (preg_match('#^https?://#i', $path)) {
                return $path;
            }
            if (str_starts_with($path, 'images/')) {
                return asset($path);
            }
            // Storage disk path (typically `tarot/cards/<slug>.webp` from Filament FileUpload).
            if (Storage::disk('public')->exists($path)) {
                return Storage::disk('public')->url($path);
            }
            // Anything else — assume it's a public/-relative path.
            return asset($path);
        }

        // No face image — use the operator-configured card back, then magician as last resort.
        $back = $this->cardBackUrl();
        return $back ?: asset('images/card-magician.png');
    }

    /** Resolved URL of the global card-back image (admin-uploaded), or null if unset. */
    public function cardBackUrl(): ?string
    {
        $path = Setting::get('tarot_card_back_path');
        if (!$path) {
            return null;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }
        return null;
    }

    /** True when this card has its own face image (not falling back to the card back). */
    public function hasFaceImage(): bool
    {
        $path = $this->image_path;
        return $path && $path !== 'images/card-magician.png';
    }

    /**
     * Resolve the face-image URL only — never falls back to the card-back.
     * Used by the admin so an operator can SEE which cards genuinely have art
     * and which are still showing the placeholder.
     */
    public function faceImageUrl(): ?string
    {
        if (!$this->hasFaceImage()) {
            return null;
        }
        $path = $this->image_path;

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if (str_starts_with($path, 'images/')) {
            // public/images/... — bust the browser cache when the operator re-imports.
            $abs = public_path($path);
            $v = is_file($abs) ? filemtime($abs) : null;
            return $v ? asset($path) . '?v=' . $v : asset($path);
        }
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }
        return asset($path);
    }
}
