{{--
  แถบหัวเพจพร้อมภาพประกอบ — ใช้ร่วมกันได้ทั้งสองธีม

  art     : path ใต้ public/ (เว้นไว้ = ไม่มีภาพ เหลือหัวข้อความล้วน ไม่พัง)
  eyebrow : บรรทัดเล็กเหนือหัวเรื่อง
  title   : ส่งเป็น <x-slot:title> เพราะข้างในมี <em> — ห้ามแปลงเป็น prop
            แล้วพ่นด้วย {!! !!} เพราะถ้าวันหลังมีใครส่งค่าจากผู้ใช้เข้ามา
            จะกลายเป็นช่อง XSS ทันที slot ปลอดภัยกว่าและอ่านง่ายกว่า
  lede    : ย่อหน้าอธิบายใต้หัวเรื่อง (slot ด้วยเหตุผลเดียวกัน)

  ภาพทุกใบเป็น "ของประดับ" ล้วน → alt="" + aria-hidden
  เนื้อหาจริงอยู่ในตัวอักษรเสมอ คนใช้ screen reader จึงไม่พลาดอะไร
--}}
@props([
    'art' => null,
    'eyebrow' => null,
])

@php
    // หน้าที่มีหัวเรื่องของตัวเองอยู่แล้ว (แชท / วอลเลต / สายงาน) เรียกใช้แบบ
    // ใส่แค่ art ไม่ใส่ข้อความ → เป็นแถบประดับล้วน ต้องเตี้ยกว่า hero ปกติ
    // ไม่งั้นหน้าแดชบอร์ดจะเสียที่ว่างเปล่า ๆ ไป 230px ก่อนถึงเนื้อหาจริง
    $hasText = $eyebrow || isset($title) || isset($lede) || trim($slot) !== '';
@endphp

<div {{ $attributes->merge(['class' => 'page-hero'
        . ($art ? '' : ' page-hero--bare')
        . ($hasText ? '' : ' page-hero--band')]) }}>
  @if ($art)
    <div class="page-hero-art" aria-hidden="true">
      <img src="{{ asset($art) }}" alt="" width="1600" height="686"
           fetchpriority="high" decoding="async">
    </div>
  @endif

  <div class="page-hero-inner">
    @if ($eyebrow)
      <div class="eyebrow page-hero-eyebrow">{{ $eyebrow }}</div>
    @endif

    @isset($title)
      <h1 class="display page-hero-title">{{ $title }}</h1>
    @endisset

    @isset($lede)
      <p class="lede page-hero-lede">{{ $lede }}</p>
    @endisset

    {{ $slot }}
  </div>
</div>
