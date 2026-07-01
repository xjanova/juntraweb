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

  // Follow-up eligibility: only the owner may consult about their own reading,
  // and (like any chat) only once linked via FB/LINE through Thaiprompt.
  $viewer      = auth()->user();
  $isOwner     = $viewer && $reading->user_id === $viewer->id;
  $canConsult  = $isOwner && $viewer->isLinkedViaFbOrLine() && ! empty($viewer->thaiprompt_token);
@endphp

@push('head')
<style>
  /* ── Follow-up "ask แม่หมอ about this spread" box ─────────────────── */
  .followup-box {
    max-width: 640px;
    margin: 56px auto 0;
    padding: 32px 28px;
    text-align: center;
    border: 1px solid var(--line-soft);
    border-radius: 22px;
    background:
      radial-gradient(120% 100% at 50% 0%, rgba(244,207,106,.07), transparent 60%),
      linear-gradient(160deg, rgba(106,59,214,.10), rgba(7,4,26,0) 70%);
  }
  .followup-hint {
    font-size: 14px;
    line-height: 1.7;
    color: var(--ink-dim);
    max-width: 52ch;
    margin: 6px auto 20px;
  }
  .followup-fee { color: var(--gold-deep, var(--gold)); white-space: nowrap; }
  .followup-form {
    display: flex;
    gap: 10px;
    align-items: stretch;
    max-width: 520px;
    margin: 0 auto;
  }
  .followup-form input {
    flex: 1;
    padding: 14px 18px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: rgba(7,4,26,.5);
    color: var(--ink);
    font-family: var(--thai);
    font-size: 15px;
    transition: border-color .3s, box-shadow .3s;
  }
  .followup-form input::placeholder { color: var(--ink-faint); }
  .followup-form input:focus {
    outline: none;
    border-color: var(--gold);
    box-shadow: 0 0 0 1px var(--gold);
  }
  .followup-form .btn { white-space: nowrap; padding: 14px 26px; }
  .followup-again {
    display: inline-block;
    margin-top: 18px;
    font-family: var(--display);
    font-size: 11px;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--ink-dim);
    transition: color .3s;
  }
  .followup-again:hover { color: var(--gold); }
  @media (max-width: 520px) {
    .followup-form { flex-direction: column; }
    .followup-form .btn { justify-content: center; }
  }
</style>
@endpush

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
      <x-reading-prose :text="$reading->result" />
    </div>

    {{-- Follow-up: ask แม่หมอ about THIS spread. She's primed with the exact
         cards drawn, so answers read those cards — not a blank-slate chat. --}}
    @if ($canConsult)
      <div class="followup-box">
        <div class="eyebrow" style="display:inline-flex">ถามแม่หมอต่อจากไพ่ชุดนี้</div>
        <p class="followup-hint">
          แม่หมอเห็นไพ่ทั้ง {{ $count }} ใบที่คุณเพิ่งเปิดแล้ว — พิมพ์ถามเจาะจงได้เลย
          เช่น “เรื่องงานควรตัดสินใจอย่างไร” หรือ “ไพ่ใบนี้เตือนอะไรฉัน”
          <span class="followup-fee">· คิดค่าบริการต่อข้อความตามระบบแชท</span>
        </p>
        <form action="{{ route('chat.from-reading', $reading) }}" method="POST" class="followup-form"
              x-data="{ sending:false }" @submit="sending=true">
          @csrf
          <input type="text" name="question" maxlength="2000" autocomplete="off"
                 placeholder="อยากถามแม่หมอเจาะจงเรื่องอะไรจากไพ่ชุดนี้?">
          <button type="submit" class="btn btn-primary" :disabled="sending" :style="sending ? 'opacity:.6;cursor:wait' : ''">
            <span x-show="!sending">ถามแม่หมอ</span>
            <span x-show="sending">กำลังพา ⋯</span>
            <svg x-show="!sending" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </button>
        </form>
        <a href="{{ route('tarot.index') }}" class="followup-again">↺ เปิดไพ่ใหม่อีกครั้ง</a>
      </div>
    @elseif ($isOwner)
      {{-- Owner, but not FB/LINE-linked yet → chat is gated, so guide them to link. --}}
      <div class="followup-box">
        <div class="eyebrow" style="display:inline-flex">ถามแม่หมอต่อจากไพ่ชุดนี้</div>
        <p class="followup-hint">
          เชื่อมบัญชี Facebook หรือ LINE ผ่าน Thaiprompt เพื่อปรึกษาแม่หมอต่อจากไพ่ชุดนี้แบบเจาะจง —
          แม่หมอจะจดจำไพ่ที่คุณเปิดและตอบคำถามเพิ่มเติมได้
        </p>
        <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin-top:6px">
          <a href="{{ route('thaiprompt.redirect') }}" class="btn btn-primary">เชื่อม Facebook / LINE
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </a>
          <a href="{{ route('tarot.index') }}" class="btn btn-ghost">เปิดอีกครั้ง</a>
        </div>
      </div>
    @else
      {{-- Non-owner (public share / admin view) → just the basics. --}}
      <div style="text-align:center;margin-top:48px;display:flex;gap:16px;justify-content:center;flex-wrap:wrap">
        <a href="{{ route('tarot.index') }}" class="btn btn-ghost">เปิดไพ่ของคุณ</a>
        <a href="{{ route('chat.index') }}" class="btn btn-primary">คุยกับแม่หมอ AI
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
      </div>
    @endif
  </div>
</section>
@endsection
