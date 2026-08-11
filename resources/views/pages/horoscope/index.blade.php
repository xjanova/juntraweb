@extends('layouts.app')
@section('title', 'ดวงรายวัน · 12 ราศี')

@section('content')
<section class="canvas" style="padding-top:160px">
  <x-page-hero art="images/juntra/art/horoscope.webp" eyebrow="ดวงรายวัน · 12 ราศี">
    <x-slot:title>เลือกราศี <em>ของคุณ</em></x-slot:title>
    <x-slot:lede>คำพยากรณ์ประจำวันสด ๆ ทุกวัน — ความรัก การงาน การเงิน สุขภาพ พร้อมเลขนำโชค สีมงคล และไพ่ประจำวัน</x-slot:lede>
  </x-page-hero>

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
