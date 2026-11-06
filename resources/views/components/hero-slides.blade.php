{{-- สไลด์ภาพแม่หมอ/บุษบาเป็นพื้นหลังเต็มจอของ hero — เฟดวนไปเรื่อย ๆ (เจ้าของสั่ง 2026-11-04)

     โหลดไว: ภาพแรกเท่านั้นที่โหลดพร้อมหน้า (fetchpriority=high + srcset 600/1200)
     ภาพที่เหลือเก็บ URL ไว้ใน data-* โหลดเฉพาะภาพถัดไปหรือภาพที่ผู้ใช้เลือก
     ภาพไหนยังไม่มีไฟล์ก็ข้าม ไม่มีสักภาพ = ไม่แสดงกรอบเลย (hero กลับเป็นแบบเดิม)
     ผู้ใช้ที่ตั้งลดการเคลื่อนไหว (prefers-reduced-motion) เห็นภาพแรกนิ่ง ๆ --}}
@props(['slides' => []])
@php
  $slides = collect($slides)->filter(fn ($s) => file_exists(public_path($s['src'])))->values();
@endphp
@if ($slides->isNotEmpty())
<div {{ $attributes->merge(['class' => 'hero-slides']) }} x-data="heroSlides">
  @foreach ($slides as $k => $s)
    @php($small = preg_replace('/\.webp$/', '-600.webp', $s['src']))
    @php($ambient = preg_replace('/\.webp$/', '-ambient.webp', $s['src']))
    @php($srcset = file_exists(public_path($small)) ? asset($small) . ' 600w, ' . asset($s['src']) . ' 1200w' : asset($s['src']) . ' 1200w')
    <div class="hero-slide{{ $k === 0 ? ' is-active' : '' }}"
         :class="{ 'is-active': i === {{ $k }}, 'is-previous': previous === {{ $k }} }"
         aria-hidden="{{ $k === 0 ? 'false' : 'true' }}" :aria-hidden="i !== {{ $k }}">
      @if (file_exists(public_path($ambient)))
        <img @if ($k === 0) src="{{ asset($ambient) }}"
             @else data-src="{{ asset($ambient) }}" @endif
             width="96" height="120" decoding="async" alt="" class="hero-slide-ambient">
      @endif
      <img @if ($k === 0) src="{{ asset($s['src']) }}" srcset="{{ $srcset }}" fetchpriority="high"
           @else data-src="{{ asset($s['src']) }}" data-srcset="{{ $srcset }}" @endif
           sizes="(max-width: 900px) min(100vw, clamp(304px, 52.8vh, 576px)), min(60vw, max(440px, 80vh))"
           width="1200" height="1500" decoding="async"
           alt="{{ $s['alt'] }}" class="hero-slide-image">
    </div>
  @endforeach
  @if ($slides->count() > 1)
    <div class="hero-slide-dots" role="tablist" aria-label="เลือกภาพ">
      @foreach ($slides as $k => $s)
        <button type="button" role="tab" aria-selected="{{ $k === 0 ? 'true' : 'false' }}"
                :aria-selected="i === {{ $k }}" aria-label="ภาพที่ {{ $k + 1 }}"
                class="{{ $k === 0 ? 'is-active' : '' }}" :class="{ 'is-active': i === {{ $k }} }"
                @click="go({{ $k }})"></button>
      @endforeach
    </div>
  @endif
</div>
@endif
