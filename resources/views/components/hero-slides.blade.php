{{-- สไลด์ภาพแม่หมอ/บุษบาเป็นพื้นหลังเต็มจอของ hero — เฟดวนไปเรื่อย ๆ (เจ้าของสั่ง 2026-11-04)

     โหลดไว: ภาพแรกเท่านั้นที่โหลดพร้อมหน้า (fetchpriority=high + srcset 600/1200)
     ภาพที่เหลือเก็บ URL ไว้ใน data-* แล้วค่อยใส่ตอนหน้าโหลดเสร็จ — ไม่แย่งแบนด์วิดท์ภาพแรก
     ภาพไหนยังไม่มีไฟล์ก็ข้าม ไม่มีสักภาพ = ไม่แสดงกรอบเลย (hero กลับเป็นแบบเดิม)
     ผู้ใช้ที่ตั้งลดการเคลื่อนไหว (prefers-reduced-motion) เห็นภาพแรกนิ่ง ๆ --}}
@props(['slides' => []])
@php
  $slides = collect($slides)->filter(fn ($s) => file_exists(public_path($s['src'])))->values();
@endphp
@if ($slides->isNotEmpty())
<div {{ $attributes->merge(['class' => 'hero-slides']) }}
     x-data="{
       i: 0, n: {{ $slides->count() }}, timer: null,
       loadAll() {
         this.$root.querySelectorAll('img[data-src]').forEach(img => {
           img.srcset = img.dataset.srcset; img.src = img.dataset.src;
           img.removeAttribute('data-src'); img.removeAttribute('data-srcset');
         });
       },
       // กดเลือกภาพเอง = เริ่มนับเวลาใหม่ ภาพที่เลือก (และคำโปรยของมัน) อยู่ให้อ่านครบรอบ ไม่ถูกเลื่อนทิ้งทันที
       go(k) { this.loadAll(); this.i = k; if (this.timer) this.tick() },
       tick() {
         clearInterval(this.timer);
         this.timer = setInterval(() => { if (!document.hidden) this.i = (this.i + 1) % this.n }, 5500);
       },
       start() {
         if (this.n < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
         const boot = () => { this.loadAll(); this.tick() };
         document.readyState === 'complete' ? boot() : window.addEventListener('load', boot, { once: true });
       },
       destroy() { clearInterval(this.timer) },
     }"
     x-init="start(); $watch('i', v => $dispatch('hero-slide', v))" :class="{ 'is-ready': true }">
  {{-- เปลี่ยนภาพเมื่อไหร่ บอกคำโปรยใน hero (@hero-slide.window) ให้เปลี่ยนตาม --}}
  {{-- ก่อน Alpine ทำงาน ภาพแรกโชว์ด้วย CSS (.hero-slides:not(.is-ready)) ไม่มีจังหวะกรอบว่าง --}}
  @foreach ($slides as $k => $s)
    @php($small = preg_replace('/\.webp$/', '-600.webp', $s['src']))
    @php($srcset = asset($small) . ' 600w, ' . asset($s['src']) . ' 1200w')
    <img @if ($k === 0) src="{{ asset($s['src']) }}" srcset="{{ $srcset }}" fetchpriority="high"
         @else data-src="{{ asset($s['src']) }}" data-srcset="{{ $srcset }}" @endif
         sizes="100vw" width="1200" height="1500" decoding="async"
         alt="{{ $s['alt'] }}" class="hero-slide" :class="{ 'is-active': i === {{ $k }} }">
  @endforeach
  @if ($slides->count() > 1)
    <div class="hero-slide-dots" role="tablist" aria-label="เลือกภาพ">
      @foreach ($slides as $k => $s)
        <button type="button" role="tab" :aria-selected="i === {{ $k }}" aria-label="ภาพที่ {{ $k + 1 }}"
                :class="{ 'is-active': i === {{ $k }} }" @click="go({{ $k }})"></button>
      @endforeach
    </div>
  @endif
</div>
@endif
