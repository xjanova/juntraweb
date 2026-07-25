@extends('layouts.app')
@section('title', 'วันมงคลที่แม่หมอแนะนำ')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:880px;margin:0 auto">
    <div class="eyebrow">AUSPICIOUS DATES</div>
    <h2 style="font-family:var(--serif);font-size:clamp(40px,5vw,68px);font-weight:400">วันมงคลสำหรับ <em style="color:var(--gold)">"{{ $occasion }}"</em></h2>

    <div class="auto-grid" style="--col-min:220px;gap:14px;margin-top:48px">
      @foreach ($candidates as $c)
        <div class="panel" style="padding:24px;display:flex;justify-content:space-between;align-items:center;gap:16px">
          <div>
            <div style="font-family:var(--display);font-size:32px;color:var(--gold)">{{ $c['date']->format('d/m/Y') }}</div>
            <div style="font-family:var(--serif);font-size:16px;color:var(--ink-dim);margin-top:4px">{{ $c['label'] }}</div>
          </div>
          <div style="text-align:center">
            <div style="font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase">SCORE</div>
            <div style="font-family:var(--display);font-size:28px;color:var(--moon);font-weight:500">{{ $c['score'] }}/10</div>
          </div>
        </div>
      @endforeach
    </div>

    <div class="panel" style="margin-top:32px">
      <div class="eyebrow" style="display:inline-flex">คำแนะนำของแม่หมอ</div>
      <x-reading-prose :text="$advice" />
    </div>

    <div style="text-align:center;margin-top:48px;display:flex;gap:16px;justify-content:center;flex-wrap:wrap">
      <a href="{{ route('auspicious.index') }}" class="btn btn-ghost">ค้นหาใหม่</a>
      <a href="{{ route('chat.index') }}" class="btn btn-primary">ถามแม่หมอต่อ
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>
</section>
@endsection
