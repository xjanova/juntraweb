<?php

namespace App\Services\GooglePlay;

/**
 * คุยกับ Google Play ไม่สำเร็จ — code = HTTP status ที่ Google ตอบ (0 = ไปไม่ถึง/ตั้งค่าไม่ครบ)
 * ข้อความเป็นรหัสภายในสำหรับ log เท่านั้น ห้ามส่งถึงลูกค้า
 */
class GooglePlayException extends \RuntimeException
{
    /** Google บอกว่า token นี้ไม่มีอยู่จริง/ไม่ใช่ของแอพนี้ — ไม่ใช่ปัญหาชั่วคราว */
    public function isInvalidPurchase(): bool
    {
        return in_array($this->getCode(), [400, 404, 410], true);
    }

    /** ฝั่งเราตั้งค่าผิด (คีย์/สิทธิ์ของ service account) — ต้องให้แอดมินแก้ */
    public function isConfigurationProblem(): bool
    {
        return in_array($this->getCode(), [401, 403], true)
            || str_contains($this->getMessage(), 'not_configured')
            || str_contains($this->getMessage(), 'bad_service_account')
            || str_contains($this->getMessage(), 'bad_private_key')
            || str_contains($this->getMessage(), 'auth_http');
    }
}
