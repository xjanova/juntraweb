@extends('layouts.app')
@section('title', 'ผลการเปิดไพ่')

@php
  use App\Support\TarotSpreads;
  $spreadKey   = TarotSpreads::keyFromType($reading->type);
  $spreadMeta  = $spreadKey ? TarotSpreads::get($spreadKey) : null;
  $spreadName  = $spreadMeta['name_th'] ?? 'ไพ่ยิปซี';
  $spreadEn    = $spreadMeta['name_en'] ?? '';
  $layout      = $spreadMeta['layout'] ?? 'grid';
  $count       = $reading->tarotCards->count();
  // position number → asks text, for the subtitle under each card.
  $asks        = $spreadKey ? array_column(TarotSpreads::positions($spreadKey), 'asks') : [];
@endphp

@section('content')
<section class="canvas" style="padding-top:160px">
  <div class="reading-result">
    <div class="eyebrow">{{ $spreadName }}@if($spreadEn) · {{ $spreadEn }}@endif · {{ $count }} ใบ</div>
    <h2 style="margin-bottom:8px">คำพยากรณ์ <em>จากไพ่ของคุณ</em></h2>
    @if ($reading->question)
      <p class="lede" style="margin-top:16px">"{{ $reading->question }}"</p>
    @endif

    @if ($layout === 'celtic')
      @php
        // Map position number → reading-card row, so the layout grid can pluck cards out by index
        $byPos = $reading->tarotCards->keyBy('position');
      @endphp

      <div class="celtic-cross" aria-label="Celtic Cross 10 cards">
        {{-- ───── Cross arm: 5 (top), 4 (left), 1 (center), 6 (right), 2 (below 1), 3 (below 2) ───── --}}
        <div class="cc-cell cc-pos-5">@include('pages.tarot._celtic-card', ['rc' => $byPos[5] ?? null])</div>

        <div class="cc-cell cc-pos-4">@include('pages.tarot._celtic-card', ['rc' => $byPos[4] ?? null])</div>
        <div class="cc-cell cc-pos-1">@include('pages.tarot._celtic-card', ['rc' => $byPos[1] ?? null])</div>
        <div class="cc-cell cc-pos-6">@include('pages.tarot._celtic-card', ['rc' => $byPos[6] ?? null])</div>

        <div class="cc-cell cc-pos-2">@include('pages.tarot._celtic-card', ['rc' => $byPos[2] ?? null])</div>

        <div class="cc-cell cc-pos-3">@include('pages.tarot._celtic-card', ['rc' => $byPos[3] ?? null])</div>

        {{-- ───── Staff (right column, top→bottom: 10, 9, 8, 7) ───── --}}
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
      {{-- Generic spread: 1 / 3 / 5 / 12 cards in a responsive row.
           1 card → centered; 2-3 → equal columns; more → wrap. --}}
      @php $rowClass = $count === 1 ? 'is-single' : ($count > 3 ? 'is-wrap' : ''); @endphp
      <div class="reading-card-row {{ $rowClass }}"
           @if($count > 1 && $count <= 3) style="grid-template-columns: repeat({{ $count }}, 1fr)" @endif>
        @foreach ($reading->tarotCards as $rc)
          <div class="position">
            <div class="pos-label">{{ $rc->position_label }}</div>
            @if (!empty($asks[$rc->position - 1]))
              <div class="pos-asks">{{ $asks[$rc->position - 1] }}</div>
            @endif
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
