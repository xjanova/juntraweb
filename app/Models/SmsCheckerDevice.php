<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A registered SMS Checker Android device. Each device holds its own api_key
 * (lookup) and secret_key (drives AES-GCM + HMAC, matching the app's
 * CryptoManager). secret_key is hidden from serialisation.
 */
class SmsCheckerDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id', 'device_name', 'api_key', 'secret_key', 'platform',
        'app_version', 'status', 'approval_mode', 'fcm_token', 'ip_address',
        'last_active_at',
    ];

    protected $hidden = ['api_key', 'secret_key'];

    protected $casts = ['last_active_at' => 'datetime'];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getApprovalMode(): string
    {
        return $this->approval_mode ?: config('smschecker.default_approval_mode', 'auto');
    }

    /**
     * Does an exact SMS match auto-credit the wallet for this device?
     * The app has a third mode, 'smart' (auto + fuzzy orphan matching done on
     * the phone). We store it as-is so the app's setting round-trips, but on
     * this server it behaves exactly like 'auto' — we have no fuzzy matching.
     */
    public function autoConfirms(): bool
    {
        return in_array($this->getApprovalMode(), ['auto', 'smart'], true);
    }

    public static function findByApiKey(string $apiKey): ?self
    {
        return static::where('api_key', $apiKey)->first();
    }

    public static function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function generateSecretKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
