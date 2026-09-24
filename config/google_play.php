<?php

/**
 * Google Play Billing — เติมเครดิตจากแอพที่ติดตั้งผ่าน Google Play
 *
 * ทำไมต้องมี: นโยบาย Payments ของ Google Play บังคับให้แอพบน Play ขายเครดิต (virtual currency)
 * ผ่าน Google Play Billing เท่านั้น และห้ามชี้ไปจ่ายช่องทางอื่น (พร้อมเพย์/สลิป) จากในแอพ
 * แอพช่อง Play จึงซื้อ "แพ็กเครดิต" (สินค้าแบบ consumable ใน Play Console) แล้วส่ง purchase token
 * มาที่ POST /api/v1/wallet/google-play/redeem — เซิร์ฟเวอร์ถาม Google เองว่าจ่ายจริงไหม
 * (ไม่เชื่อแอพ) เติมเครดิตครั้งเดียวต่อ token แล้ว consume ให้ลูกค้าซื้อซ้ำได้
 *
 * ตั้งค่าบนเซิร์ฟเวอร์ (.env) — ไม่ตั้ง = ปิดการขายผ่าน Play (แอพช่อง Play ใช้เครดิตที่มีได้อย่างเดียว):
 *   GOOGLE_PLAY_SERVICE_ACCOUNT_PATH=/home/<user>/secure/google-play-service-account.json
 *     ไฟล์คีย์ JSON ของ service account ที่ได้สิทธิ์ใน Play Console (ดูเอกสาร docs/GOOGLE_PLAY.md
 *     ในรีโปแอพ) — เก็บไว้นอก public_html และห้าม commit
 *   GOOGLE_PLAY_PACKAGE_NAME=com.xjanova.juntra
 *
 * แพ็กเครดิต (product id => เครดิตที่ได้) แก้ได้ที่หลังบ้าน → ตั้งค่าวอลเลต/ราคา
 * ค่าข้างล่างใช้เมื่อยังไม่เคยตั้ง — product id ต้องตรงกับที่สร้างใน Play Console ทุกตัวอักษร
 * ราคาขายตั้งใน Play Console (Google หักค่าธรรมเนียม 15% ของรายได้ปีละ $1M แรก)
 */
return [
    'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME', 'com.xjanova.juntra'),

    'service_account_path' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_PATH'),

    'products' => [
        'juntra_credits_50'   => 50,
        'juntra_credits_100'  => 100,
        'juntra_credits_200'  => 200,
        'juntra_credits_500'  => 500,
        'juntra_credits_1000' => 1000,
    ],

    // เรียก Google ได้นานแค่ไหนต่อคำขอ (วินาที)
    'timeout' => (int) env('GOOGLE_PLAY_TIMEOUT', 15),
];
