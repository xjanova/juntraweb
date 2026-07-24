@extends('layouts.app')
@section('title', "ดวงราศี{$zodiac->name_th}")

@section('content')
@php
    // Inline map (not a declared function): the old __thaiElement() was declared
    // at the bottom of this file under if(!function_exists()). A CONDITIONAL
    // declaration is NOT hoisted, so the call up here ran before it existed →
    // "Call to undefined function" → every zodiac page 500'd.
    $thaiElement = ['Fire' => 'ไฟ', 'Earth' => 'ดิน', 'Air' => 'ลม', 'Water' => 'น้ำ'][$zodiac->element] ?? $zodiac->element;
@endphp
<section class="canvas" style="padding-top:160px">
  <div style="max-width:880px;margin:0 auto">
    <div class="eyebrow">ดวงประจำวันที่ {{ now()->format('d/m/Y') }}</div>
    <h1 class="display" style="font-size:clamp(60px,7vw,108px);margin-bottom:8px;display:flex;align-items:center;gap:24px">
      <span style="font-family:var(--display);color:var(--gold);font-size:.9em">{{ $zodiac->glyph }}</span>
      <span><em>{{ $zodiac->name_th }}</em></span>
    </h1>
    <p style="color:var(--ink-dim);font-size:15px;letter-spacing:.04em">{{ $zodiac->name_en }} · {{ $zodiac->date_range }} · ธาตุ{{ $thaiElement }}</p>

    <div class="panel" style="margin-top:40px">
      <div class="summary" style="font-family:var(--serif);font-style:italic;font-size:22px;line-height:1.5;color:var(--moon)">
        "{{ $horoscope->summary }}"
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:32px">
        <div>
          <div class="eyebrow" style="display:inline-flex;margin-bottom:8px">ความรัก</div>
          <p style="color:var(--ink-dim);line-height:1.7">{{ $horoscope->love }}</p>
        </div>
        <div>
          <div class="eyebrow" style="display:inline-flex;margin-bottom:8px">การงาน</div>
          <p style="color:var(--ink-dim);line-height:1.7">{{ $horoscope->career }}</p>
        </div>
        <div>
          <div class="eyebrow" style="display:inline-flex;margin-bottom:8px">การเงิน</div>
          <p style="color:var(--ink-dim);line-height:1.7">{{ $horoscope->money }}</p>
        </div>
        <div>
          <div class="eyebrow" style="display:inline-flex;margin-bottom:8px">สุขภาพ</div>
          <p style="color:var(--ink-dim);line-height:1.7">{{ $horoscope->health }}</p>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:32px;padding-top:32px;border-top:1px solid var(--line-soft)">
        <div style="text-align:center">
          <div style="font-family:var(--display);font-size:11px;letter-spacing:.22em;color:var(--gold);text-transform:uppercase;margin-bottom:8px">เลขนำโชค</div>
          <div style="font-family:var(--display);font-size:42px;color:var(--moon);font-weight:500">{{ $horoscope->lucky_number }}</div>
        </div>
        <div style="text-align:center">
          <div style="font-family:var(--display);font-size:11px;letter-spacing:.22em;color:var(--gold);text-transform:uppercase;margin-bottom:8px">สีมงคล</div>
          <div style="font-family:var(--serif);font-size:24px;color:var(--moon)">{{ $horoscope->lucky_color }}</div>
        </div>
        <div style="text-align:center">
          <div style="font-family:var(--display);font-size:11px;letter-spacing:.22em;color:var(--gold);text-transform:uppercase;margin-bottom:8px">ไพ่ประจำวัน</div>
          <div style="font-family:var(--display);font-size:18px;color:var(--moon);letter-spacing:.1em">{{ $horoscope->lucky_card }}</div>
        </div>
      </div>
    </div>

    <div style="margin-top:48px">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:24px">ราศีอื่น</div>
      <div class="zodiac-list" style="grid-template-columns:repeat(6,1fr)">
        @foreach ($allZodiacs as $z)
          <a href="{{ route('horoscope.show', $z) }}" class="z" style="padding:10px;text-decoration:none;{{ $z->id === $zodiac->id ? 'border-color:var(--gold);background:rgba(244,207,106,.1)' : '' }}">
            <span class="glyph" style="font-size:18px">{{ $z->glyph }}</span>
            <span class="name" style="font-size:11px">{{ $z->name_th }}</span>
          </a>
        @endforeach
      </div>
    </div>
  </div>
</section>
@endsection
