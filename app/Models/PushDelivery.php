<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * สมุดจดการส่งแจ้งเตือน — 1 แถว = เรื่องหนึ่งที่ส่งถึงผู้ใช้หนึ่งคนแล้ว (กันซ้ำด้วย unique user+type+ref)
 */
class PushDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'ref', 'devices', 'delivered'];
}
