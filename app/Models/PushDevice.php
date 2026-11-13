<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * แอป Android หนึ่งเครื่องที่จดไว้รับแจ้งเตือนผ่าน FCM — คู่กับ PushSubscription (Web Push)
 * ดู App\Services\Push\PushNotifier (นโยบาย) และ App\Services\Push\FcmSender (การส่ง)
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $personal_access_token_id
 * @property string $token
 * @property string $token_hash
 * @property string $platform
 * @property string|null $channel
 * @property string|null $app_version
 * @property int $fail_count
 */
class PushDevice extends Model
{
    public const PLATFORMS = ['android'];

    public const CHANNELS = ['play', 'direct'];

    protected $fillable = [
        'user_id', 'personal_access_token_id', 'token', 'token_hash', 'platform', 'channel', 'app_version',
        'fail_count', 'last_success_at', 'last_failure_at',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
