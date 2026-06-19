<?php

/**
 * Thai validation messages. APP_LOCALE=th is set, but without this file
 * Laravel falls back to the built-in English strings — so every form (tarot,
 * numerology, palmistry, auspicious, chat, wallet, profile) showed English
 * errors to Thai users. This is the site-wide fix.
 *
 * Only the rules actually used across the app are translated in full; any
 * rule not listed falls back to Laravel's default (English) string, which is
 * fine for developer-only rules.
 */
return [
    'required'         => 'กรุณากรอก:attribute',
    'required_if'      => 'กรุณากรอก:attribute',
    'filled'           => 'กรุณากรอก:attribute',
    'string'           => ':attribute ต้องเป็นข้อความ',
    'integer'          => ':attribute ต้องเป็นตัวเลข',
    'numeric'          => ':attribute ต้องเป็นตัวเลข',
    'boolean'          => ':attribute ต้องเป็นค่าจริงหรือเท็จ',
    'array'            => ':attribute ต้องเป็นรายการ',
    'date'             => ':attribute ต้องเป็นวันที่ที่ถูกต้อง',
    'date_format'      => ':attribute ไม่ตรงกับรูปแบบ :format',
    'email'            => ':attribute ต้องเป็นอีเมลที่ถูกต้อง',
    'image'            => ':attribute ต้องเป็นไฟล์รูปภาพ',
    'file'             => ':attribute ต้องเป็นไฟล์',
    'in'               => ':attribute ที่เลือกไม่ถูกต้อง',
    'exists'           => ':attribute ที่เลือกไม่ถูกต้อง',
    'confirmed'        => ':attribute ยืนยันไม่ตรงกัน',
    'before_or_equal'  => ':attribute ต้องเป็นวันที่ก่อนหรือเท่ากับ :date',
    'before'           => ':attribute ต้องเป็นวันที่ก่อน :date',
    'after_or_equal'   => ':attribute ต้องเป็นวันที่หลังหรือเท่ากับ :date',
    'after'            => ':attribute ต้องเป็นวันที่หลัง :date',
    'regex'            => 'รูปแบบของ:attribute ไม่ถูกต้อง',

    'min' => [
        'numeric' => ':attribute ต้องไม่น้อยกว่า :min',
        'string'  => ':attribute ต้องมีอย่างน้อย :min ตัวอักษร',
        'array'   => ':attribute ต้องมีอย่างน้อย :min รายการ',
        'file'    => ':attribute ต้องมีขนาดอย่างน้อย :min กิโลไบต์',
    ],
    'max' => [
        'numeric' => ':attribute ต้องไม่เกิน :max',
        'string'  => ':attribute ต้องมีไม่เกิน :max ตัวอักษร',
        'array'   => ':attribute ต้องมีไม่เกิน :max รายการ',
        'file'    => ':attribute ต้องมีขนาดไม่เกิน :max กิโลไบต์',
    ],
    'size' => [
        'numeric' => ':attribute ต้องเท่ากับ :size',
        'string'  => ':attribute ต้องมี :size ตัวอักษร',
        'array'   => ':attribute ต้องมี :size รายการ',
        'file'    => ':attribute ต้องมีขนาด :size กิโลไบต์',
    ],

    // Friendly Thai field names so messages read naturally.
    'attributes' => [
        'name'       => 'ชื่อ-นามสกุล',
        'birth_date' => 'วัน-เดือน-ปีเกิด',
        'occasion'   => 'โอกาส/งานมงคล',
        'from_date'  => 'วันที่เริ่มต้น',
        'to_date'    => 'วันที่สิ้นสุด',
        'question'   => 'คำถาม',
        'message'    => 'ข้อความ',
        'image'      => 'รูปภาพ',
        'spread'     => 'รูปแบบการวางไพ่',
        'picked'     => 'ไพ่ที่เลือก',
        'amount'     => 'จำนวนเงิน',
        'slip'       => 'สลิปโอนเงิน',
        'email'      => 'อีเมล',
        'password'   => 'รหัสผ่าน',
    ],

    'custom' => [],
];
