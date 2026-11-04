<?php

/**
 * ข้อมูลผู้ประกอบการ — ที่เดียวสำหรับตรา DBD (footer / หน้าแรก) และ structured data (SEO)
 *
 * มาจากใบ DBD Registered ของกรมพัฒนาธุรกิจการค้า (2026-11-04)
 * ใบหมดอายุ 5 มิ.ย. 2031 — ต่ออายุแล้วแก้ dbd.expires_at ที่นี่ที่เดียว
 */
return [
    'shop_name' => 'จันทราพยากรณ์',
    'shop_name_en' => 'CHANTHRAPAYAAKORN',
    'business_type' => 'คอมพิวเตอร์ อุปกรณ์ไอที และซอฟต์แวร์',
    'started_at' => '2024-07-11',

    'address' => [
        'street' => '106 หมู่ 1',
        'locality' => 'ตำบลเอราวัณ อำเภอเอราวัณ',
        'region' => 'เลย',
        'country' => 'TH',
    ],

    // ช่องทางโซเชียลทางการ (footer + schema sameAs) — ลำดับนี้คือลำดับที่แสดง
    'social' => [
        'facebook' => ['label' => 'Facebook แม่หมอจันทราพยากรณ์', 'url' => 'https://www.facebook.com/juntrapayakorn'],
        'line' => ['label' => 'LINE Official @002dqcls', 'url' => 'https://line.me/R/ti/p/@002dqcls'],
        'instagram' => ['label' => 'Instagram @juntraforturn', 'url' => 'https://www.instagram.com/juntraforturn'],
        'tiktok' => ['label' => 'TikTok @chantraphayakon2324', 'url' => 'https://www.tiktok.com/@chantraphayakon2324'],
        'youtube' => ['label' => 'YouTube แม่หมอจันทราพยากรณ์', 'url' => 'https://www.youtube.com/@%E0%B9%81%E0%B8%A1%E0%B9%88%E0%B8%AB%E0%B8%A1%E0%B8%AD%E0%B8%88%E0%B8%B1%E0%B8%99%E0%B8%97%E0%B8%A3%E0%B8%B2%E0%B8%9E%E0%B8%A2%E0%B8%B2%E0%B8%81%E0%B8%A3%E0%B8%93%E0%B9%8C2324'],
    ],

    'dbd' => [
        'trustmark_id' => '125789',
        // หน้ายืนยันของกรมพัฒนาธุรกิจการค้า (ข้อมูลสด) — ตรา/ใบรับรองบนเว็บลิงก์มาที่นี่
        'verify_url' => 'https://dbdregistered.dbd.go.th/api/public/trustmarkinfo/125789',
        'registered_at' => '2026-06-05',
        'expires_at' => '2031-06-05',
        'badge' => 'images/dbd/dbd-registered.png',
        // สำเนาใบรับรองไว้แสดงผล (ภาพจาก DBD หนัก 200KB และไม่ให้ cache) — ของจริงดูที่ verify_url
        'certificate' => 'images/dbd/dbd-certificate.webp',
        // แม่หมอถือใบรับรอง (ภาพเจน + วางใบจริงทับ ตัวหนังสือจึงตรงของจริง) — ใช้บนหน้าแรก
        'showcase' => 'images/dbd/maemor-dbd.webp',
    ],
];
