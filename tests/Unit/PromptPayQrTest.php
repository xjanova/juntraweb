<?php

namespace Tests\Unit;

use App\Support\PromptPayQr;
use PHPUnit\Framework\TestCase;

/**
 * QR ที่ลูกค้าสแกนจริงคือของฝั่งเซิร์ฟเวอร์ — ต้องมีเทสต์ตรึง
 *
 * แอพเลือก `qr_payload` จากเซิร์ฟเวอร์ก่อนเสมอ (wallet_screen.dart) ตัวสร้าง
 * ฝั่งนี้จึงเป็นตัวจริง แต่เดิม **ไม่มีเทสต์เลยสักตัว** ขณะที่ฝั่งแอพมีครบ
 * — ผิดกฎ CLAUDE.md ข้อ 4 (ค่าที่แตะเงินต้องตรึงด้วยเทสต์)
 *
 * เคสที่เคยพังจริง: เลข e-Wallet 15 หลักถูก `substr(...,0,13)` ตัดเหลือ 13
 * แล้วติดแท็ก 02 (บัตรประชาชน) → QR พาโอนเข้าเลขที่ไม่มีอยู่จริง
 *
 * เทสต์ชุดนี้จงใจใช้ input ชุดเดียวกับ `APP/juntra/test/promptpay_qr_test.dart`
 * เพื่อให้สองฝั่งเทียบกันได้ตรง ๆ — input เดียวกันต้องได้ payload เดียวกัน
 */
class PromptPayQrTest extends TestCase
{
    /** CRC-16/CCITT-FALSE ค่ามาตรฐานของแคตตาล็อก: "123456789" → 0x29B1 */
    public function test_crc16_matches_the_canonical_check_value(): void
    {
        $payload = PromptPayQr::payload('0899999999', 4.22);

        // สร้าง CRC ใหม่จาก body (รวม '6304') แล้วต้องได้ 4 ตัวท้ายเดิม
        $body = substr($payload, 0, -4);
        $tail = substr($payload, -4);

        $this->assertSame($tail, $this->crc16($body), 'CRC ท้าย payload ต้องตรงกับที่คำนวณใหม่');
        $this->assertSame('29B1', $this->crc16('123456789'), 'ค่าตรวจมาตรฐานของ CRC-16/CCITT-FALSE');
    }

    /** เบอร์มือถือ + ยอด = QR แบบ dynamic ครบทุกแท็ก */
    public function test_mobile_dynamic_qr_is_well_formed(): void
    {
        $qr = PromptPayQr::payload('0899999999', 4.22);

        $this->assertStringStartsWith('000201', $qr);          // payload format
        $this->assertStringContainsString('010212', $qr);       // POI = dynamic
        $this->assertStringContainsString('A000000677010111', $qr);
        $this->assertStringContainsString('0066899999999', $qr); // 0 → 0066
        $this->assertStringContainsString('5303764', $qr);       // THB
        $this->assertStringContainsString('54044.22', $qr);      // ยอด 2 ตำแหน่ง
        $this->assertStringContainsString('5802TH', $qr);
    }

    /** ไม่มียอด = static และต้องไม่มีแท็ก 54 */
    public function test_static_qr_omits_the_amount_tag(): void
    {
        $qr = PromptPayQr::payload('081-234-5678');

        $this->assertStringContainsString('010211', $qr);
        $this->assertStringNotContainsString('5402', $qr);
        $this->assertStringContainsString('0066812345678', $qr, 'ขีดต้องถูกตัดทิ้ง');
    }

    /**
     * ยอด 0 ต้องถือเป็น static
     *
     * ของเดิมใส่ POI '12' (dynamic) ทันทีที่ `$amount !== null` แต่ใส่แท็ก 54
     * เฉพาะเมื่อ > 0 → ได้ QR dynamic ที่ไม่มียอด ซึ่งขัดกันเอง
     */
    public function test_zero_amount_is_treated_as_static(): void
    {
        $qr = PromptPayQr::payload('0899999999', 0.0);

        $this->assertStringContainsString('010211', $qr);
        $this->assertStringNotContainsString('5402', $qr);
    }

    /** บัตรประชาชน 13 หลัก → แท็ก 02 */
    public function test_national_id_uses_sub_tag_02(): void
    {
        $qr = PromptPayQr::payload('1234567890123', 50);
        $this->assertStringContainsString('02131234567890123', $qr);
    }

    /**
     * e-Wallet 15 หลัก → แท็ก 03 และห้ามถูกตัดสั้น
     *
     * นี่คือหมุดของบั๊กที่แก้: ก่อนหน้านี้ได้ '0213123456789012' (ตัดเหลือ 13
     * แล้วติดแท็กผิด) ซึ่งพาโอนเข้าเลขที่ไม่มีอยู่จริง
     */
    public function test_ewallet_id_uses_sub_tag_03_and_is_not_truncated(): void
    {
        $qr = PromptPayQr::payload('123456789012345', 50);

        $this->assertStringContainsString('0315123456789012345', $qr);
        $this->assertStringNotContainsString('02131234567890123', $qr);
    }

    /** ความยาวที่ไม่เข้าชนิดใดเลย ต้องคืนสตริงว่าง ไม่ใช่ปั้น QR มั่ว */
    public function test_unusable_ids_return_empty(): void
    {
        $this->assertSame('', PromptPayQr::payload(''));
        $this->assertSame('', PromptPayQr::payload('not-a-number'));
        $this->assertSame('', PromptPayQr::payload('12345'));
    }

    /** เบอร์รูปแบบอื่นที่ฝั่งแอพรับ ต้องได้ผลเหมือนกันเป๊ะ */
    public function test_alternate_mobile_shapes_match_the_flutter_client(): void
    {
        $expected = PromptPayQr::payload('0812345678', 10);

        $this->assertSame($expected, PromptPayQr::payload('66812345678', 10), '+66 ต้องได้ค่าเดียวกัน');
        $this->assertSame($expected, PromptPayQr::payload('812345678', 10), 'เบอร์ 9 หลักต้องได้ค่าเดียวกัน');
    }

    /** CRC-16/CCITT-FALSE — สำเนาอิสระไว้ตรวจของจริง ไม่เรียกเมธอดใน production */
    private function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
