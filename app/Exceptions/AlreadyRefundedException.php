<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * รายการหักนี้ถูกคืนเงินครบแล้ว (ระบบคืนเอง / แอดมินคืน / แอดมินย้อนรายการ) — ไม่มีอะไรให้คืนอีก
 *
 * ผู้เรียกที่คืนเงินอัตโนมัติ (เช่น ทำนายไม่สำเร็จ) ถือว่าไม่ใช่ความผิดพลาด แค่ไม่ต้องทำอะไร
 */
class AlreadyRefundedException extends RuntimeException
{
}
