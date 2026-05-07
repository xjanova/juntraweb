@extends('layouts.app')
@section('title', 'ดวงรายวัน · 12 ราศี')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="text-align:center;max-width:780px;margin:0 auto 60px">
    <div class="eyebrow" style="display:inline-flex">ดวงรายวัน · 12 ราศี</div>
    <h1 class="display" style="font-size:clamp(48px,6vw,84px);margin-bottom:24px">เลือกราศี <em>ของคุณ</em></h1>
    <p class="lede" style="margin:0 auto">คำพยากรณ์ประจำวันสด ๆ ทุกวัน — ความรัก การงาน การเงิน สุขภาพ พร้อมเลขนำโชค สีมงคล และไพ่ประจำวัน</p>
  </div>

  <div class="zodiac-list" style="grid-template-columns:repeat(4,1fr);max-width:880px;margin:0 auto;gap:14px">
    @foreach ($zodiacs as $z)
      <a href="{{ route('horoscope.show', $z) }}" class="z" style="padding:20px 14px;text-decoration:none">
        <span class="glyph" style="font-size:32px">{{ $z->glyph }}</span>
        <span class="name" style="font-size:14px;font-weight:500">{{ $z->name_th }}</span>
        <div style="font-size:11px;color:var(--ink-faint);margin-top:6px;letter-spacing:.04em">{{ $z->date_range }}</div>
      </a>
    @endforeach
  </div>

  <div style="text-align:center;margin-top:48px">
    <a href="{{ route('horoscope.thai') }}" class="btn btn-ghost">ดูราศีไทย / ปีนักษัตร</a>
  </div>
</section>
@endsection
