{{-- คำถามที่พบบ่อย (SEO 2026-11-04) — เนื้อหามาจาก App\Support\Seo::faqs() ชุดเดียวกับ FAQPage schema
     และ llms.txt · schema ต้องตรงกับคำถามที่ "แสดงจริง" บนหน้า จึงประกาศไว้ใน component นี้ที่เดียว --}}
@php($faqs = \App\Support\Seo::faqs())
@push('head')
<script type="application/ld+json">{!! \App\Support\Seo::jsonLd(\App\Support\Seo::homeGraph()) !!}</script>
@endpush
<section {{ $attributes->merge(['class' => 'section home-faq']) }} id="faq">
  <div class="section-head reveal">
    <div class="section-eyebrow">FAQ · คำถามที่พบบ่อย</div>
    <h2 class="section-title" style="padding:30px 0 7px">สงสัยเรื่องดูดวงออนไลน์? แม่หมอตอบให้</h2>
  </div>
  <div class="faq-list">
    @foreach ($faqs as $k => $f)
      <details class="faq-item reveal" @if ($k === 0) open @endif>
        <summary><h3>{{ $f['q'] }}</h3></summary>
        <p>{{ $f['a'] }}</p>
      </details>
    @endforeach
  </div>
</section>
