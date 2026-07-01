@extends('layouts.app')
@section('title', 'ไพ่ยิปซี · Tarot Reading')

@php
  // Compact meta for the Alpine submit-label lookup.
  $spreadJs = collect($spreads)->mapWithKeys(fn ($s) => [$s['key'] => ['count' => $s['count'], 'name' => $s['name_th']]]);

  // Per-spread line-art glyph (inherits `currentColor` → gold medallion).
  // Keyed by spread key so adding a spread degrades gracefully to a default.
  $glyphs = [
    'single'   => '<path d="M12 8l1.3 2.7 3 .4-2.2 2 .5 3-2.6-1.4-2.6 1.4.5-3-2.2-2 3-.4z"/><rect x="6" y="3" width="12" height="18" rx="2.2"/>',
    'three'    => '<path d="M3.5 12h17"/><circle cx="5.5" cy="12" r="1.9"/><circle cx="12" cy="12" r="2.4"/><circle cx="18.5" cy="12" r="1.9"/>',
    'love'     => '<path d="M12 20s-6.8-4.4-6.8-9.2A3.5 3.5 0 0 1 12 8a3.5 3.5 0 0 1 6.8 2.8C18.8 15.6 12 20 12 20z"/>',
    'career'   => '<path d="M4 20V4M4 20h16"/><path d="M8 20v-4.5M13 20v-8.5M18 20v-12.5"/>',
    'decision' => '<path d="M12 21v-6.5M12 14.5L6.8 9.3V4M12 14.5l5.2-5.2V4"/><circle cx="6.8" cy="3.4" r="1.5"/><circle cx="17.2" cy="3.4" r="1.5"/>',
    'celtic'   => '<path d="M12 3v18M4.5 12h15"/><circle cx="12" cy="12" r="4.6"/>',
    'year'     => '<circle cx="12" cy="12" r="8.4"/><path d="M12 3.6v16.8M3.6 12h16.8M6 6l12 12M18 6L6 18"/>',
  ];
  $defaultGlyph = '<circle cx="12" cy="12" r="8.4"/><path d="M12 3.6v16.8M3.6 12h16.8"/>';
@endphp

@push('head')
<style>
  /* ── Tarot spread picker — mystical card grid ─────────────────────── */
  .spread-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(268px, 1fr));
    gap: 18px;
  }
  .spread-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 24px 22px 20px;
    height: 100%;
    border: 1px solid var(--line-soft);
    border-radius: 20px;
    cursor: pointer;
    overflow: hidden;
    isolation: isolate;
    background:
      linear-gradient(180deg, rgba(255,255,255,.018), rgba(255,255,255,0)),
      linear-gradient(160deg, rgba(106,59,214,.10), rgba(7,4,26,0) 60%);
    transition: transform .35s cubic-bezier(.2,.7,.2,1), border-color .35s, box-shadow .35s, background .35s;
  }
  /* Corner aura that intensifies on hover / select */
  .spread-card::before {
    content: "";
    position: absolute;
    top: -40%; right: -30%;
    width: 70%; height: 80%;
    background: radial-gradient(circle, rgba(244,207,106,.16), transparent 70%);
    opacity: 0;
    transition: opacity .4s;
    z-index: -1;
    pointer-events: none;
  }
  .spread-card:hover {
    transform: translateY(-5px);
    border-color: var(--line);
    box-shadow: 0 18px 44px -22px rgba(0,0,0,.75);
  }
  .spread-card:hover::before { opacity: .7; }
  .spread-card:focus-within {
    border-color: var(--gold);
    box-shadow: 0 0 0 1px var(--gold);
  }
  .spread-card.is-selected {
    border-color: var(--gold);
    background:
      linear-gradient(180deg, rgba(244,207,106,.10), rgba(244,207,106,.02)),
      linear-gradient(160deg, rgba(106,59,214,.16), rgba(7,4,26,0) 60%);
    box-shadow: 0 0 0 1px var(--gold), 0 22px 50px -24px rgba(244,207,106,.35);
  }
  .spread-card.is-selected::before { opacity: 1; }

  .spread-card__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .spread-medallion {
    display: grid;
    place-items: center;
    width: 48px; height: 48px;
    flex: none;
    border-radius: 50%;
    color: var(--gold);
    border: 1px solid var(--line);
    background: radial-gradient(circle at 50% 40%, rgba(106,59,214,.35), rgba(7,4,26,.9));
    box-shadow: inset 0 0 16px rgba(244,207,106,.12);
    transition: all .35s;
  }
  .spread-medallion svg { width: 24px; height: 24px; }
  .spread-card:hover .spread-medallion {
    box-shadow: inset 0 0 18px rgba(244,207,106,.22), 0 0 18px -6px rgba(244,207,106,.5);
  }
  .spread-card.is-selected .spread-medallion {
    color: #1a0f30;
    border-color: var(--gold);
    background: radial-gradient(circle at 50% 35%, var(--moon), var(--gold) 70%);
    box-shadow: 0 0 22px -4px rgba(244,207,106,.7);
  }

  .spread-price {
    font-family: var(--display);
    font-size: 13px;
    letter-spacing: .12em;
    color: var(--gold);
    padding: 6px 13px;
    border: 1px solid var(--line);
    border-radius: 999px;
    white-space: nowrap;
    background: rgba(244,207,106,.05);
  }
  .spread-price.is-free { letter-spacing: .18em; text-transform: uppercase; font-size: 11px; }

  .spread-eyebrow {
    align-self: flex-start;
    font-family: var(--display);
    font-size: 10px;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: var(--gold-deep, var(--gold));
    padding: 5px 11px;
    border: 1px solid var(--line-soft);
    border-radius: 999px;
  }
  .spread-name {
    font-family: var(--serif);
    font-size: 24px;
    line-height: 1.15;
    color: var(--moon, var(--ink));
    margin-top: 2px;
  }
  .spread-name-en {
    font-size: 11px;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--ink-faint, var(--ink-dim));
    margin-top: 3px;
  }
  .spread-tagline {
    font-size: 13.5px;
    line-height: 1.65;
    color: var(--ink-dim);
    flex: 1;
  }
  .spread-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 2px;
    padding-top: 14px;
    border-top: 1px solid var(--line-soft);
    font-size: 12px;
    color: var(--ink-dim);
  }
  .spread-foot__est { display: inline-flex; align-items: center; gap: 7px; }
  .spread-foot__est::before {
    content: "";
    width: 5px; height: 5px;
    border-radius: 50%;
    background: var(--gold);
    box-shadow: 0 0 7px var(--gold);
  }
  .spread-pick {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-family: var(--display);
    font-size: 10px;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: var(--gold);
    opacity: 0;
    transform: translateX(-4px);
    transition: opacity .3s, transform .3s;
  }
  .spread-card:hover .spread-pick { opacity: .8; transform: none; }
  .spread-card.is-selected .spread-pick { opacity: 1; transform: none; }

  /* Visually-hidden radio — still focusable & drives x-model */
  .spread-radio {
    position: absolute;
    width: 1px; height: 1px;
    padding: 0; margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
  }

  .spread-cta { max-width: 640px; margin: 32px auto 0; text-align: center; }
</style>
@endpush

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="text-align:center;max-width:820px;margin:0 auto 44px">
    <div class="eyebrow" style="display:inline-flex">ไพ่ยิปซี Rider-Waite · 78 ใบ</div>
    <h1 class="display" style="font-size:clamp(48px,6vw,84px);margin-bottom:24px">เปิดไพ่ <em>ค้นหาคำตอบ</em></h1>
    <p class="lede" style="margin:0 auto">เลือกรูปแบบการวางไพ่ที่ตรงกับใจคุณ — ตั้งแต่ไพ่ใบเดียวฟันธงเร็ว ไปจนถึง Celtic Cross 10 ใบ และพยากรณ์รายปี 12 เดือน แม่หมอจันทรา AI จะอ่านไพ่ × ตำแหน่งให้คุณอย่างแม่นยำ</p>
  </div>

  <form action="{{ route('tarot.begin') }}" method="POST" style="max-width:1080px;margin:0 auto"
        x-data="{ spread: 'three', meta: {{ $spreadJs->toJson() }} }">
    @csrf
    <input type="hidden" name="spread" :value="spread">

    <div class="eyebrow" style="display:inline-flex;margin-bottom:18px">เลือกรูปแบบการวางไพ่</div>

    <div class="spread-grid">
      @foreach ($spreads as $s)
        <label class="spread-card" :class="spread === '{{ $s['key'] }}' ? 'is-selected' : ''">
          <input type="radio" name="spread_radio" value="{{ $s['key'] }}" x-model="spread" class="spread-radio">

          <div class="spread-card__top">
            <span class="spread-medallion" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                {!! $glyphs[$s['key']] ?? $defaultGlyph !!}
              </svg>
            </span>
            @if ($s['price'] > 0)
              <span class="spread-price">฿{{ number_format($s['price'], $s['price'] == intval($s['price']) ? 0 : 2) }}</span>
            @else
              <span class="spread-price is-free">ฟรี</span>
            @endif
          </div>

          <div>
            <span class="spread-eyebrow">{{ $s['eyebrow'] }}</span>
            <div class="spread-name">{{ $s['name_th'] }}</div>
            <div class="spread-name-en">{{ $s['name_en'] }}</div>
          </div>

          <div class="spread-tagline">{{ $s['tagline'] }}</div>

          <div class="spread-foot">
            <span class="spread-foot__est">{{ $s['count'] }} ใบ · ~{{ $s['est'] }}</span>
            <span class="spread-pick">
              เลือก
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" width="12" height="12"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </span>
          </div>
        </label>
      @endforeach
    </div>

    <div class="spread-cta">
      <button class="btn btn-primary" style="width:100%;justify-content:center" type="submit">
        <span x-text="meta[spread] ? `กางไพ่ ${meta[spread].count} ใบ — ${meta[spread].name} →` : 'เลือกไพ่ของคุณ →'"></span>
      </button>
      <div style="margin-top:14px;font-size:12px;color:var(--ink-dim);letter-spacing:.04em;line-height:1.7">
        ระบบจะกางไพ่ทั้ง 78 ใบให้คุณเลือกด้วยตัวเองในขั้นตอนถัดไป ✨<br>
        เครดิตจะถูกหักจากวอลเลตเมื่อเปิดไพ่เท่านั้น
      </div>
    </div>
  </form>
</section>
@endsection
