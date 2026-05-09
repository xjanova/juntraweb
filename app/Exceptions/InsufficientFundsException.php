<?php

namespace App\Exceptions;

use Exception;

class InsufficientFundsException extends Exception
{
    public function __construct(
        public readonly float $required,
        public readonly float $balance,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'เครดิตไม่พอ — ต้องการ ฿%s แต่คงเหลือ ฿%s',
            number_format($required, 2),
            number_format($balance, 2),
        ));
    }
}
