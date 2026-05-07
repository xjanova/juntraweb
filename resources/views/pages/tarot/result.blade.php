@extends('layouts.app')
@section('title', 'ผลการเปิดไพ่')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div class="reading-result">
    <div class="eyebrow">{{ $reading->type === 'tarot_celtic' ? 'CELTIC CROSS · 10 ใบ' : 'THREE-CARD SPREAD · 3 ใบ' }}</div>
    <h2 style="margin-bottom:8px">คำพยากรณ์ <em>จากไพ่ของคุณ</em></h2>
    @if ($reading->question)
      <p class="lede" style="margin-top:16px">"{{ $reading->question }}"</p>
    @endif

    @if ($reading->type === 'tarot_celtic')
      @php
        // Map position number → reading-card row, so the layout grid can pluck cards out by index
        $byPos = $reading->tarotCards->keyBy('position');
      @endphp

      <div class="celtic-cross" aria-label="Celtic Cross 10 cards">
        {{-- ───── Cross arm (positions 1–6) ───── --}}
        <div class="cc-cell cc-pos-5">@include('pages.tarot._celtic-card', ['rc' => $byPos[5] ?? null])</div>

        <div class="cc-cell cc-pos-4">@include('pages.tarot._celtic-card', ['rc' => $byPos[4] ?? null])</div>
        <div class="cc-cell cc-pos-cross">
          @if (isset($byPos[1]))
            <div class="cc-card-1" data-pos="1">
              @include('pages.tarot._celtic-card-bare', ['rc' => $byPos[1]])
            </div>
          @endif
          @if (isset($byPos[2]))
            <div class="cc-card-2" data-pos="2" aria-label="ไพ่ตำแหน่งที่ 2 ขวางกั้น">
              @include('pages.tarot._celtic-card-bare', ['rc' => $byPos[2]])
            </div>
          @endif
        </div>
        <div class="cc-cell cc-pos-6">@include('pages.tarot._celtic-card', ['rc' => $byPos[6] ?? null])</div>

        <div class="cc-cell cc-pos-3">@include('pages.tarot._celtic-card', ['rc' => $byPos[3] ?? null])</div>

        {{-- ───── Staff (positions 7–10, top→bottom: 10, 9, 8, 7) ───── --}}
        <div class="cc-cell cc-pos-10">@include('pages.tarot._celtic-card', ['rc' => $byPos[10] ?? null])</div>
        <div class="cc-cell cc-pos-9">@include('pages.tarot._celtic-card', ['rc' => $byPos[9] ?? null])</div>
        <div class="cc-cell cc-pos-8">@include('pages.tarot._celtic-card', ['rc' => $byPos[8] ?? null])</div>
        <div class="cc-cell cc-pos-7">@include('pages.tarot._celtic-card', ['rc' => $byPos[7] ?? null])</div>
      </div>

      {{-- Mobile fallback: simple stack with position labels --}}
      <div class="celtic-cross-mobile">
        @foreach ($reading->tarotCards->sortBy('position') as $rc)
          <div class="cc-mobile-row">
            <div class="cc-mobile-num">{{ $rc->position }}</div>
            @include('pages.tarot._celtic-card', ['rc' => $rc, 'compact' => true])
          </div>
        @endforeach
      </div>

    @else
      {{-- 3-card spread (existing layout) --}}
      <div class="reading-card-row" style="grid-template-columns: repeat(3, 1fr)">
        @foreach ($reading->tarotCards as $rc)
          <div class="position">
            <div class="pos-label">{{ $rc->position_label }}</div>
            <div class="card-img" style="{{ $rc->reversed ? 'transform:rotate(180deg)' : '' }}">
              <img src="{{ $rc->card->imageUrl() }}" alt="{{ $rc->card->name_th }}">
            </div>
            <div class="card-name">{{ $rc->card->name_th }} {{ $rc->reversed ? '(กลับหัว)' : '' }}</div>
            <div class="card-meaning">{{ $rc->reversed ? $rc->card->reversed_meaning_th : $rc->card->upright_meaning_th }}</div>
          </div>
        @endforeach
      </div>
    @endif

    <div class="panel" style="margin-top:48px">
      <div class="eyebrow" style="display:inline-flex">บทวิเคราะห์รวม</div>
      <div class="summary">{!! nl2br(e($reading->result)) !!}</div>
    </div>

    <div style="text-align:center;margin-top:48px;display:flex;gap:16px;justify-content:center;flex-wrap:wrap">
      <a href="{{ route('tarot.index') }}" class="btn btn-ghost">เปิดอีกครั้ง</a>
      <a href="{{ route('chat.index') }}" class="btn btn-primary">ถามเพิ่มเติมกับ AI
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>
</section>
@endsection
