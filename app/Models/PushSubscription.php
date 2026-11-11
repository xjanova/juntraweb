<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * เครื่องหนึ่งเครื่องที่ผู้ใช้เปิดรับแจ้งเตือน (Web Push) — ดู App\Services\Push\PushNotifier
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $p256dh
 * @property string $auth
 * @property string|null $user_agent
 * @property int $fail_count
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'user_agent',
        'fail_count', 'last_success_at', 'last_failure_at',
    ];

    protected $hidden = ['endpoint', 'p256dh', 'auth'];

    protected $casts = [
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ชื่อเครื่องอ่านง่ายจาก user agent — โชว์ในหน้าบัญชีให้รู้ว่าเปิดไว้เครื่องไหน */
    public function deviceLabel(): string
    {
        $ua = (string) $this->user_agent;
        $os = match (true) {
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            default => 'อุปกรณ์',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => '',
        };

        return trim($os.' · '.$browser, ' ·');
    }
}
