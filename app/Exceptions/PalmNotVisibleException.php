<?php

namespace App\Exceptions;

/**
 * รูปที่ส่งมาไม่เห็นฝ่ามือชัดพอให้อ่าน — ผู้เรียกต้องคืนเงินแล้วบอกให้ถ่ายใหม่ (ไม่ใช่ "ระบบขัดข้อง")
 */
class PalmNotVisibleException extends \RuntimeException
{
    public const MESSAGE = 'รูปนี้ยังไม่เห็นฝ่ามือชัดพอ ลองถ่ายฝ่ามือให้เต็มกรอบในที่สว่างนะคะ — เครดิตถูกคืนแล้ว';
}
