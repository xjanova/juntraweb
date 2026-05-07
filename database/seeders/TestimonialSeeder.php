<?php

namespace Database\Seeders;

use App\Models\Testimonial;
use Illuminate\Database\Seeder;

class TestimonialSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['คุณปิ่นมณี', 'ดูดวงชะตาตลอดชีวิต', 5, 'แม่หมอจันทราทำนายว่าจะได้งานใหม่ภายใน 2 เดือน — เดือนต่อมาผมได้ออฟเฟอร์จากบริษัทในฝันจริงๆ ขนลุกตอนอ่านคำทำนายอีกรอบ', 1],
            ['คุณนภัสวรรณ', 'ไพ่ยิปซี Celtic Cross', 5, 'ใช้บริการมา 3 ครั้ง แม่นทุกครั้ง โดยเฉพาะเรื่องความรัก แม่หมอบอกว่าจะเจอคนที่ใช่ในเดือนกันยายน — ปรากฏว่าเจอจริงๆ ที่งานสัมมนา', 2],
            ['คุณธีรพล', 'AI Chat ดูดวง', 5, 'AI ของแม่หมอจันทราล้ำมาก อธิบายดวงดาวได้เข้าใจง่าย ไม่ใช้คำขู่หรือแสร้งให้กลัว — แนะนำทางออกชัดเจน คุ้มราคามากๆ', 3],
        ];

        foreach ($rows as [$name, $service, $rating, $message, $order]) {
            Testimonial::updateOrCreate(['name' => $name, 'service' => $service], [
                'rating' => $rating,
                'message' => $message,
                'approved' => true,
                'order_index' => $order,
            ]);
        }
    }
}
