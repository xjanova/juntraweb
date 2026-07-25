@extends('layouts.app')
@section('title', 'ปีนักษัตรไทย · 12 นักษัตร')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="text-align:center;max-width:780px;margin:0 auto 60px">
    <div class="eyebrow" style="display:inline-flex">ปีนักษัตร · 12 นักษัตร</div>
    <h1 class="display" style="font-size:clamp(48px,6vw,84px);margin-bottom:24px">ปีเกิด <em>เขียนชะตา</em></h1>
    <p class="lede" style="margin:0 auto">นักษัตรไทยจาก 12 ปีในรอบ — แต่ละปีมีพลังเฉพาะตัวที่ส่งผลต่อบุคลิกและโชคชะตาของผู้เกิดในปีนั้น</p>
  </div>

  <div class="services-grid" style="max-width:1080px;margin:0 auto">
    @foreach ($chineseZodiacs as $cz)
      <div class="service" style="text-align:center">
        <div class="icon" style="margin:0 auto 24px;font-size:32px;line-height:1">{{ $cz->glyph }}</div>
        <h3>ปี{{ $cz->name_th }}</h3>
        <div class="duration">{{ $cz->name_en }}</div>
        <p>{{ $cz->traits_th }}</p>
      </div>
    @endforeach
  </div>
</section>
@endsection
