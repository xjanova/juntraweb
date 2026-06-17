<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an admin approves a top-up but the amount they read on the slip
 * doesn't match the amount the user claimed — i.e. amount tampering.
 */
class SlipAmountMismatchException extends RuntimeException
{
    public function __construct(
        public readonly float $claimed,
        public readonly float $onSlip,
    ) {
        parent::__construct(sprintf(
            'ยอดบนสลิป (฿%s) ไม่ตรงกับยอดที่ผู้ใช้แจ้ง (฿%s) — ตรวจสอบก่อนอนุมัติ',
            number_format($onSlip, 2),
            number_format($claimed, 2),
        ));
    }
}
