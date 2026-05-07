<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function imageUrl(): string
    {
        if ($this->image_path) {
            return asset($this->image_path);
        }
        return asset('images/card-magician.png');
    }
}
