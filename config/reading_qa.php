<?php

/*
|--------------------------------------------------------------------------
| ⏳ เวลาถามต่อหลังเปิดไพ่ (2026-11-05)
|--------------------------------------------------------------------------
| เจ้าของสั่ง "นาฬิกาเริ่มนับถอยหลังตามระบบจริงที่เราจับเวลา" + "ทำไมบอทไม่รู้ว่าเหลือเวลาเท่าไหร่"
| ตัวเลขเดียวกับบอท FB/LINE ของ Thaiprompt (ProSessionTrait):
|   ไพ่ชุด 99 ขึ้นไป (Celtic / 12 เดือน / คุณไสย) = celtic_cross_qa_window_minutes = 15 นาที
|   ชุดอื่น = deep_reading_qa_window_minutes = 7 นาที
| นับจากคำถามแรกของลูกค้าในห้องที่คุยต่อจากไพ่ · ผู้พยากรณ์รู้เวลาที่เหลือทุกข้อความ (แนบในบริบท)
|
| idle_nudge_seconds = ลูกค้าเงียบนานเท่านี้ ผู้พยากรณ์ทักตาม (บนหน้าเว็บ ไม่ถาม AI ไม่เสียโควตา LINE)
*/

return [
    'minutes' => [
        'default' => (int) env('READING_QA_MINUTES', 7),
        'celtic'  => (int) env('READING_QA_MINUTES_PREMIUM', 15),
        'year'    => (int) env('READING_QA_MINUTES_PREMIUM', 15),
        'kunsai'  => (int) env('READING_QA_MINUTES_PREMIUM', 15),
    ],
    // 🎁 (2026-11-11) ถามฟรีหลังดูไพ่ฟรี 1 ใบ — เจ้าของสั่ง "ไม่เกิน 2 นาที" (FreeAskClock ตัดที่ 2 เสมอ)
    'free_minutes' => (int) env('FREE_ASK_MINUTES', 2),
    // เหลือเท่านี้ (นาที) ผู้พยากรณ์บอกลูกค้าว่าใกล้หมดเวลา
    'warn_minutes'       => 3,
    'idle_nudge_seconds' => (int) env('READING_QA_IDLE_NUDGE_SECONDS', 90),
];
