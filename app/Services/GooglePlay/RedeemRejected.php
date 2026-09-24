<?php

namespace App\Services\GooglePlay;

/** ปฏิเสธการเติมเครดิตจาก Google Play — ข้อความไทยส่งถึงลูกค้าได้, reason_code ให้แอพแยกเคส */
class RedeemRejected extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
