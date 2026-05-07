<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChineseZodiac extends Model
{
    protected $fillable = ['slug', 'name_th', 'name_en', 'glyph', 'order_index', 'traits_th'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
