<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a top-up slip image has already been used by another top-up. */
class DuplicateSlipException extends RuntimeException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'สลิปนี้ถูกใช้เติมเงินไปแล้ว ไม่สามารถใช้ซ้ำได้');
    }
}
