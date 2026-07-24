<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    // SoftDeletes: deleting an account preserves the wallet ledger (the FK
    // cascade never fires because the user row is only soft-deleted).
    use HasFactory, HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'thaiprompt_user_id',
        'thaiprompt_token',
        'thaiprompt_refresh_token',
        'thaiprompt_token_expires_at',
        'thaiprompt_synced_at',
        'facebook_user_id',
        'line_user_id',
        'signup_via',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'thaiprompt_token',
        'thaiprompt_refresh_token',
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
            'thaiprompt_refresh_token' => EncryptedString::class,
            'thaiprompt_token_expires_at' => 'datetime',
        ];
    }

    public function isThaipromptLinked(): bool
    {
        return !empty($this->thaiprompt_user_id);
    }

    /**
     * True only when we hold a usable Thaiprompt token — i.e. upstream calls
     * (MLM, AI pool) will actually authenticate. Distinct from
     * isThaipromptLinked() (which is true once the account was EVER linked,
     * even after the token expired/was cleared). Gate upstream-dependent
     * features on THIS so a dead token surfaces as "re-link" instead of an
     * empty dashboard.
     */
    public function isThaipromptUsable(): bool
    {
        return !empty($this->thaiprompt_token);
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

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class)->latest('id');
    }

    public function chatConversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class)->latest('id');
    }

    /** Current wallet balance without forcing a Wallet row to exist. */
    public function walletBalance(): float
    {
        return (float) ($this->wallet?->balance ?? 0);
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
