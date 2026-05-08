<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'thaiprompt_user_id',
        'thaiprompt_token',
        'thaiprompt_synced_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'thaiprompt_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'thaiprompt_synced_at' => 'datetime',
        ];
    }

    public function isThaipromptLinked(): bool
    {
        return !empty($this->thaiprompt_user_id);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, ['admin', 'editor'], true);
    }
}
