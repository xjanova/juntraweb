<?php

namespace Tests\Unit;

use App\Models\WalletTransaction;
use Tests\TestCase;

/**
 * 🧾 (2026-11-07) บิล QR ที่หมดเวลาต้องขึ้น "หมดอายุ" ไม่ใช่ "ปฏิเสธ" — ลูกตกใจว่าโอนแล้วโดนตีกลับ
 */
class WalletTransactionStatusLabelTest extends TestCase
{
    private function topup(array $attrs): WalletTransaction
    {
        return (new WalletTransaction)->forceFill(array_merge(['type' => 'topup', 'amount' => 100], $attrs));
    }

    public function test_a_bill_closed_by_the_expiry_sweeper_reads_expired(): void
    {
        $this->assertSame('หมดอายุ', $this->topup(['status' => 'failed', 'meta' => ['expired' => true]])->statusLabel());
    }

    public function test_an_old_expired_bill_without_the_flag_still_reads_expired(): void
    {
        $this->assertSame('หมดอายุ', $this->topup(['status' => 'failed', 'meta' => ['base_amount' => 100]])->statusLabel());
    }

    public function test_a_bill_an_admin_or_the_sms_app_rejected_reads_rejected(): void
    {
        $this->assertSame('ปฏิเสธ', $this->topup(['status' => 'failed', 'approved_at' => now(), 'meta' => ['reject_reason' => 'สลิปปลอม']])->statusLabel());
    }

    public function test_a_pending_qr_past_its_time_reads_expired_but_a_slip_waiting_for_review_does_not(): void
    {
        $this->assertSame('หมดอายุ', $this->topup(['status' => 'pending', 'expires_at' => now()->subMinute()])->statusLabel());
        $this->assertSame('รอตรวจสอบ', $this->topup(['status' => 'pending', 'expires_at' => now()->subMinute(), 'slip_path' => 'slips/a.jpg'])->statusLabel());
        $this->assertSame('รอตรวจสอบ', $this->topup(['status' => 'pending', 'expires_at' => now()->addHour()])->statusLabel());
    }
}
