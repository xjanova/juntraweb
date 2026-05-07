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
     *   1. Empty / null            → fallback (default magician art)
     *   2. Absolute URL            → returned as-is (CDN, etc.)
     *   3. `images/...`            → public/images/ — `asset()`
     *   4. `tarot/...`             → storage disk (uploaded via Filament FileUpload)
     *   5. anything else           → assume public/, `asset()`
     */
    public function imageUrl(): string
    {
        $path = $this->image_path;

        if (!$path) {
            return asset('images/card-magician.png');
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (str_starts_with($path, 'images/')) {
            return asset($path);
        }

        // Storage disk path (typically `tarot/cards/<slug>.webp` written by Filament FileUpload).
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return asset($path);
    }

    /** Fallback art when DB-stored path doesn't resolve at runtime. */
    public function imageUrlOrPlaceholder(): string
    {
        $url = $this->imageUrl();
        if (!$url || str_ends_with($url, 'card-magician.png')) {
            return asset('images/card-magician.png');
        }
        return $url;
    }
}
