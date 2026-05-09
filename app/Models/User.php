<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'thaiprompt_user_id',
        'thaiprompt_token',
        'thaiprompt_synced_at',
        'facebook_user_id',
        'line_user_id',
        'signup_via',
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
            // Stored encrypted at rest — bearer token grants access to upstream
            // Thaiprompt account. Custom cast tolerates legacy plaintext rows
            // (the next save() rewrites them encrypted).
            'thaiprompt_token' => EncryptedString::class,
        ];
    }

    public function isThaipromptLinked(): bool
    {
        return !empty($this->thaiprompt_user_id);
    }

    /**
     * True only when the membership originated from Facebook OR LINE —
     * the rule the operator wants for the AI chat gate.
     */
    public function isLinkedViaFbOrLine(): bool
    {
        return !empty($this->facebook_user_id) || !empty($this->line_user_id);
    }

    /** Returns 'facebook' | 'line' | null — for badge display in the UI. */
    public function chatLinkChannel(): ?string
    {
        if (!empty($this->facebook_user_id)) return 'facebook';
        if (!empty($this->line_user_id))     return 'line';
        return null;
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
