<?php

/*
|--------------------------------------------------------------------------
| 🃏 โรงละครเปิดไพ่ (หน้าเลือกไพ่ + พิธีรอคำทำนาย + เปิดไพ่ทีละใบ) — 2026-11-05
|--------------------------------------------------------------------------
| ฉากของแต่ละห้อง = ภาพโต๊ะเปล่า (plate) + ภาพผู้พยากรณ์พื้นใส (sprite) ยืนหลังขอบโต๊ะ
|   plate.table  = ขอบหน้าโต๊ะอยู่ที่สัดส่วนความสูงภาพ (วัดจากภาพจริง — เปลี่ยนภาพต้องวัดใหม่)
|   plate.pos_y  = ตำแหน่งแนวตั้งของ background-position (จอที่ภาพล้นแนวตั้ง)
| title = ชื่อห้องบนแถบบน · สีของห้อง: accent = สีหลัก · accent_2 = สีรอง · glow = แสงขอบไพ่/วงพิธี · star = ดาวพื้นหลัง
|
| ล็อกห้อง (เจ้าของสั่ง 2026-11-05: "ถ้าเริ่มทำนายแล้วจะเปลี่ยนห้องไม่ได้จนกว่าจะจบ หมดเวลา")
|   pick_lock_minutes    = นับจากเริ่มสับไพ่ (tarot/begin) — ยังไม่จ่ายก็ล็อกจนหมดเวลานี้
|   reading_lock_minutes = คำทำนายที่ยังอ่านอยู่ ล็อกจนเสร็จ/ล้มเหลว หรือจนครบเวลานี้
|                          (readings:sweep-stuck คืนเงินรายการค้างที่ 5 นาที — 10 นาทีจึงครอบแน่นอน)
*/

return [
    'pick_lock_minutes'    => (int) env('CARD_THEATER_PICK_LOCK_MINUTES', 15),
    'reading_lock_minutes' => (int) env('CARD_THEATER_READING_LOCK_MINUTES', 10),

    'card_back' => 'images/juntra/theater/card-back.webp',

    'rooms' => [
        'busaba' => [
            'title'    => 'ห้องน้องบุษบา',
            'plate'    => ['src' => 'images/juntra/theater/plate-busaba.webp', 'w' => 1671, 'h' => 941, 'table' => 0.563, 'pos_y' => 0.35],
            'sprite'   => 'images/juntra/theater/sprite-busaba.webp',
            'accent'   => '#7cc7f6',
            'accent_2' => '#eedfb7',
            'glow'     => '#8fd3ff',
            'star'     => '#d9f1ff',
        ],
        'maemor' => [
            'title'    => 'ห้องแม่หมอจันทรา',
            'plate'    => ['src' => 'images/juntra/theater/plate-maemor.webp', 'w' => 1672, 'h' => 941, 'table' => 0.553, 'pos_y' => 0.35],
            'sprite'   => 'images/juntra/theater/sprite-maemor.webp',
            'accent'   => '#a884f4',
            'accent_2' => '#f5d89a',
            'glow'     => '#f5d89a',
            'star'     => '#c9d8ff',
        ],
        'kunsai' => [
            'title'    => 'ห้องคุณไสย',
            'plate'    => ['src' => 'images/juntra/theater/plate-kunsai.webp', 'w' => 1672, 'h' => 941, 'table' => 0.597, 'pos_y' => 0.35],
            'sprite'   => 'images/juntra/theater/sprite-kunsai.webp',
            'accent'   => '#e0455f',
            'accent_2' => '#e1b060',
            'glow'     => '#ff6a5a',
            'star'     => '#ffc9a0',
        ],
    ],
];
