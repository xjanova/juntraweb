<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuspiciousDate extends Model
{
    protected $fillable = ['date', 'type', 'title', 'description', 'active'];

    protected $casts = ['date' => 'date', 'active' => 'boolean'];
}
