@extends('layouts.app')
@section('title', 'ไพ่ยิปซี · Tarot Reading')

@section('content')
@php
  // Compact meta for the Alpine submit-label + count lookup.
  $spreadJs = collect($spreads)->mapWithKeys(fn ($s) => [$s['key'] => ['count' => $s['count'], 'name' => $s['name_th']]]);
@endphp

<section class="canvas" style="padding-top:160px">
  <div style="text-align:center;max-width:820px;margin:0 auto 48px">
    <div class="eyebrow" style="display:inline-flex">ไพ่ยิปซี Rider-Waite · 78 ใบ</div>
    <h1 class="display" style="font-size:clamp(48px,6vw,84px);margin-bottom:24px">เปิดไพ่ <em>ค้นหาคำตอบ</em></h1>
    <p class="lede" style="margin:0 auto">เลือกรูปแบบการวางไพ่ที่ตรงกับใจคุณ — ตั้งแต่ไพ่ใบเดียวฟันธงเร็ว ไปจนถึง Celtic Cross 10 ใบ และพยากรณ์รายปี 12 เดือน แม่หมอจันทรา AI จะอ่านไพ่ × ตำแหน่งให้คุณอย่างแม่นยำ</p>
  </div>

  <form action="{{ route('tarot.begin') }}" method="POST" style="max-width:1080px;margin:0 auto"
        x-data="{ spread: 'three', question: '', meta: {{ $spreadJs->toJson() }} }">
    @csrf
    <input type="hidden" name="spread" :value="spread">

    <div class="eyebrow" style="display:inline-flex;margin-bottom:16px">เลือกรูปแบบการวางไพ่</div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px">
      @foreach ($spreads as $s)
        <label
          style="display:flex;flex-direction:column;gap:8px;padding:20px;border:1px solid var(--line-soft);border-radius:16px;cursor:pointer;height:100%"
          :style="spread==='{{ $s['key'] }}' ? 'border-color:var(--gold);background:rgba(244,207,106,.08);box-shadow:0 0 0 1px var(--gold)' : ''">
          <div style="display:flex;align-items:flex-start;gap:12px">
            <input type="radio" name="spread_radio" value="{{ $s['key'] }}" x-model="spread" style="accent-color:var(--gold);margin-top:4px">
            <div style="flex:1">
              <div style="font-family:var(--display);letter-spacing:.16em;color:var(--gold);font-size:12px;text-transform:uppercase">{{ $s['eyebrow'] }}</div>
              <div style="font-family:var(--serif,serif);font-size:20px;color:var(--ink,#f6efe0);margin-top:4px;line-height:1.2">{{ $s['name_th'] }}</div>
              <div style="font-size:11px;color:var(--ink-dim);letter-spacing:.12em;text-transform:uppercase">{{ $s['name_en'] }}</div>
            </div>
          </div>
          <div style="font-size:13px;color:var(--ink-dim);line-height:1.5;flex:1">{{ $s['tagline'] }}</div>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:4px">
            <span style="font-size:12px;color:var(--ink-dim)">{{ $s['count'] }} ใบ · ~{{ $s['est'] }}</span>
            <span style="font-family:var(--display);font-size:13px;color:var(--gold);letter-spacing:.14em">
              @if ($s['price'] > 0)
                ฿{{ number_format($s['price'], $s['price'] == intval($s['price']) ? 0 : 2) }}
              @else
                ฟรี
              @endif
            </span>
          </div>
        </label>
      @endforeach
    </div>

    <div class="panel" style="margin-top:28px">
      <div class="field" style="margin-bottom:18px">
        <label for="question">คำถามของคุณ (ไม่ใส่ก็ได้ — ใส่ให้แม่หมออ่านได้ตรงขึ้น)</label>
        <textarea id="question" name="question" rows="3" x-model="question" maxlength="500"
                  placeholder="เช่น ความสัมพันธ์กับคนรักจะไปทางไหน, ปีนี้การงานการเงินเป็นอย่างไร, ควรเปลี่ยนงานดีไหม"></textarea>
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center" type="submit">
        <span x-text="meta[spread] ? `เลือกไพ่ ${meta[spread].count} ใบ — ${meta[spread].name} →` : 'เลือกไพ่ของคุณ →'"></span>
      </button>
      <div style="margin-top:14px;font-size:12px;color:var(--ink-dim);text-align:center;letter-spacing:.04em">
        ระบบจะกางไพ่ทั้ง 78 ใบให้คุณเลือกด้วยตัวเองในขั้นตอนถัดไป ✨ · เครดิตจะถูกหักจากวอลเลตเมื่อเปิดไพ่เท่านั้น
      </div>
    </div>
  </form>
</section>
@endsection
