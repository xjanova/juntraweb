<?php

/*
 * 🔔 Web Push (2026-11-11) — แจ้งเตือนบนมือถือ/ไอแพด/คอมหลังติดตั้งเว็บเป็นแอป
 *
 * กุญแจ VAPID อยู่ใน .env เท่านั้น (ห้าม commit) — สร้างด้วย `php artisan webpush:vapid`
 *   VAPID_PUBLIC_KEY   จุดบนเส้นโค้ง P-256 แบบไม่บีบอัด 65 ไบต์ (base64url) — ส่งให้เบราว์เซอร์ตอนสมัครรับ
 *   VAPID_PRIVATE_KEY  ค่า d 32 ไบต์ (base64url) — ใช้เซ็น JWT ทุกครั้งที่ส่ง
 *   VAPID_SUBJECT      ช่องทางติดต่อเจ้าของเว็บที่ push service ใช้ (mailto: หรือ https://)
 *
 * ไม่มีกุญแจ = ระบบแจ้งเตือนทั้งหมดปิดตัวเอง (ปุ่มในหน้าบัญชีไม่โผล่ คำสั่งตามเวลาไม่ทำอะไร)
 * เปลี่ยนกุญแจ = subscription เดิมทุกเครื่องใช้ไม่ได้ (push service ตอบ 403) ต้องให้ผู้ใช้กดเปิดใหม่
 */
return [
    'public_key' => env('VAPID_PUBLIC_KEY', ''),
    'private_key' => env('VAPID_PRIVATE_KEY', ''),
    'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://xn--82c4af5bzdj.online')),

    // อายุข้อความที่ push service เก็บรอเครื่องที่ปิดอยู่ (วินาที) — เกินนี้ทิ้ง ไม่ส่งของเก่าค้างข้ามวัน
    'ttl' => (int) env('WEBPUSH_TTL', 6 * 3600),

    // ส่งไปยัง push service เหล่านี้เท่านั้น — endpoint มาจากเบราว์เซอร์ของผู้ใช้
    // ถ้าไม่จำกัด ผู้ใช้จะส่ง URL ภายในมาให้เซิร์ฟเวอร์ยิง (SSRF) ได้
    'allowed_hosts' => [
        'fcm.googleapis.com',            // Chrome / Edge Android / Samsung Internet
        'android.googleapis.com',
        'updates.push.services.mozilla.com', // Firefox
        'push.services.mozilla.com',
        'web.push.apple.com',            // Safari macOS / iOS 16.4+ (แอปบนหน้าจอโฮม)
        '.push.apple.com',
        '.notify.windows.com',           // Edge Windows (wns2-*.notify.windows.com)
    ],

    // เครื่องละไม่เกินเท่านี้ต่อผู้ใช้ — กันสคริปต์สมัครรับซ้ำเป็นพันแถว
    'max_per_user' => 10,
];
