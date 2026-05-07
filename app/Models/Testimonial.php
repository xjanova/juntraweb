<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $fillable = ['name', 'service', 'rating', 'message', 'approved', 'order_index'];

    protected $casts = ['approved' => 'boolean', 'rating' => 'integer'];

    public function scopeApproved($query)
    {
        return $query->where('approved', true);
    }
}
