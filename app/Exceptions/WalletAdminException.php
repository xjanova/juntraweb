<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * ข้อผิดพลาดจากการจัดการวอลเลตโดยแอดมินที่ "แสดงให้แอดมินเห็นได้"
 *
 * ข้อความเป็นภาษาไทยที่อ่านรู้เรื่อง — หน้าแอดมินแสดง getMessage() ของ exception ชนิดนี้ได้ตรง ๆ
 * ส่วน exception ชนิดอื่น (เช่น QueryException) ต้อง log แล้วแสดงข้อความกลาง ห้ามโชว์ข้อความดิบ
 */
class WalletAdminException extends RuntimeException
{
}
