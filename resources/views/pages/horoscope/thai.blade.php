@extends('layouts.app')
@section('title', 'ปีนักษัตรไทย · 12 นักษัตร')

@section('content')
<section class="canvas" style="padding-top:160px">
  <x-page-hero art="images/juntra/art/thai-zodiac.webp" eyebrow="ปีนักษัตร · 12 นักษัตร">
    <x-slot:title>ปีเกิด <em>เขียนชะตา</em></x-slot:title>
    <x-slot:lede>นักษัตรไทยจาก 12 ปีในรอบ — แต่ละปีมีพลังเฉพาะตัวที่ส่งผลต่อบุคลิกและโชคชะตาของผู้เกิดในปีนั้น</x-slot:lede>
  </x-page-hero>

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
