<?php

/*
 * 📲 (2026-11-12) แจ้งเตือนในแอป Android ผ่าน Firebase Cloud Messaging (HTTP v1) — คู่กับ Web Push (config/webpush.php)
 *
 * กุญแจอยู่ใน .env เท่านั้น (ห้าม commit):
 *   FCM_SERVICE_ACCOUNT_JSON  path ไฟล์คีย์ JSON ของ service account ในโปรเจกต์ Firebase ที่แอปใช้
 *                             (Firebase console → Project settings → Service accounts → Generate new private key)
 *                             เก็บไว้นอก public_html · จะวาง JSON ทั้งก้อน หรือ JSON ที่เข้ารหัส base64 ก็ได้
 *   FCM_PROJECT_ID            ไม่ใส่ = ใช้ project_id ในไฟล์คีย์
 *
 * ไม่ตั้ง/อ่านไม่ออก = ช่องทางแอปปิดเงียบ ๆ (Web Push ยังทำงานตามเดิม) · แอปยังจด token ได้ ตั้งกุญแจเมื่อไรก็ส่งได้ทันที
 * นโยบายว่าส่งอะไร เมื่อไร กี่ครั้ง อยู่ที่ App\Services\Push\PushNotifier ที่เดียว
 */
return [
    'project_id' => env('FCM_PROJECT_ID', ''),
    'service_account' => env('FCM_SERVICE_ACCOUNT_JSON', ''),

    // อายุข้อความที่ FCM เก็บรอเครื่องที่ปิดอยู่ (วินาที) — เท่ากับ Web Push
    'ttl' => (int) env('FCM_TTL', env('WEBPUSH_TTL', 6 * 3600)),

    // เครื่องละไม่เกินเท่านี้ต่อผู้ใช้ — เกินแล้วลบเครื่องที่ไม่ได้เปิดแอปนานที่สุด
    'max_per_user' => 10,
];
