@extends('layouts.app')
@section('title', 'เลือกไพ่ของคุณ')

@section('content')
@php
  use Illuminate\Support\Facades\Storage;
  use App\Models\Setting;
  $cardBack = Setting::get('tarot_card_back_path');
  $cardBackUrl = $cardBack ? Storage::disk('public')->url($cardBack) : null;
@endphp

<section class="canvas" style="padding-top:160px">
  <div style="max-width:1400px;margin:0 auto"
       x-data="cardFan({
         cards: {{ $cards->map(fn($c) => ['id' => $c->id])->toJson() }},
         needed: {{ $needed }}
       })"
       x-init="init">

    <div style="text-align:center;margin-bottom:32px">
      <div class="eyebrow" style="display:inline-flex">เลือกไพ่ของคุณ</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">
        จับ <em>{{ $needed }} ใบ</em>
        @if($needed === 10)<span style="font-size:.55em;letter-spacing:.18em;display:inline-block;vertical-align:middle;color:var(--gold,#e7c97a)">CELTIC CROSS</span>@endif
      </h1>
      <p class="lede" style="margin:0 auto">
        @if ($question)
          คำถามของคุณ: <em style="color:var(--gold-2,var(--gold,#e7c97a))">"{{ $question }}"</em><br>
        @endif
        แม่หมอกำลังกรีดไพ่ทั้ง 78 ใบให้คุณ — แตะที่ไพ่หงาย {{ $needed }} ใบเรียงตามลำดับที่ใจคุณเลือก
      </p>
    </div>

    <div class="pick-status" :class="picked.length === needed ? 'is-complete' : ''">
      <div>
        เลือกแล้ว <strong x-text="picked.length"></strong> / {{ $needed }} ใบ
        @if (isset($cost))
          <span style="margin-left:14px;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--gold)">
            ค่าบริการ ฿{{ number_format($cost, $cost == intval($cost) ? 0 : 2) }}
          </span>
          @if ($balance !== null)
            <span style="margin-left:8px;font-size:12px;color:var(--ink-dim)">
              · คงเหลือ ฿{{ number_format($balance, 2) }}
              @if (bccomp(number_format($balance, 2, '.', ''), number_format($cost, 2, '.', ''), 2) < 0)
                <a href="{{ route('wallet.topup') }}" style="color:#d4a017;margin-left:6px">— เติมเงิน</a>
              @endif
            </span>
          @endif
        @endif
      </div>
      <button type="button" @click="reset" :disabled="picked.length === 0" class="pick-reset-btn">เลือกใหม่</button>
    </div>

    {{-- The fan: Alpine builds rows client-side based on viewport width.
         Each row is a horizontal arc of cards rotated about its bottom-center pivot. --}}
    <div class="card-fan {{ $cardBackUrl ? 'has-card-back' : '' }}"
         @if($cardBackUrl) style="--card-back-image: url('{{ $cardBackUrl }}')" @endif>
      <template x-for="(row, ri) in rows" :key="ri">
        <div class="fan-row" :style="`--row-count:${row.length};--row-mid:${(row.length - 1) / 2}`">
          <template x-for="card in row" :key="card.id">
            <button type="button"
                    class="fan-card"
                    :style="`--row-i:${card.ri};--card-i:${card.gi}`"
                    :class="picked.includes(card.id) ? 'is-picked' : ''"
                    @click="toggle(card.id)"
                    :aria-label="`ไพ่หงายลำดับ ${card.gi + 1}`">
              <span class="fan-card-back" aria-hidden="true">
                @if (! $cardBackUrl)
                  <svg viewBox="0 0 60 110" preserveAspectRatio="xMidYMid meet">
                    <defs>
                      <radialGradient :id="`g${card.gi}`" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="rgba(176,122,255,.55)"/>
                        <stop offset="60%" stop-color="rgba(42,18,89,.95)"/>
                        <stop offset="100%" stop-color="#0a0612"/>
                      </radialGradient>
                    </defs>
                    <rect x="2" y="2" width="56" height="106" rx="6" :fill="`url(#g${card.gi})`" stroke="rgba(231,201,122,.5)" stroke-width=".7"/>
                    <rect x="5" y="5" width="50" height="100" rx="4" fill="none" stroke="rgba(231,201,122,.25)" stroke-width=".4" stroke-dasharray="1.5 2"/>
                    <circle cx="30" cy="55" r="14" fill="none" stroke="rgba(231,201,122,.7)" stroke-width=".6"/>
                    <circle cx="30" cy="55" r="9" fill="none" stroke="rgba(244,207,106,.5)" stroke-width=".4"/>
                    <path d="M30 41 L33 55 L30 69 L27 55 Z M16 55 L30 53 L44 55 L30 57 Z" fill="rgba(244,207,106,.6)"/>
                    <circle cx="30" cy="55" r="1.5" fill="#f4cf6a"/>
                  </svg>
                @endif
              </span>
              <span class="fan-card-marker" x-show="picked.includes(card.id)" x-text="picked.indexOf(card.id) + 1"></span>
            </button>
          </template>
        </div>
      </template>
    </div>

    <div id="reveal-anchor" style="height:1px"></div>

    <div style="margin:48px auto 24px;text-align:center;min-height:80px">
      {{-- submitting flag prevents a fast double-click from POSTing twice
           (which would otherwise debit the wallet and create a reading twice) --}}
      <form action="{{ route($targetRoute) }}" method="POST" x-show="picked.length === needed" x-transition.duration.500ms style="display:inline-block"
            x-data="{submitting:false}" @submit="submitting=true">
        @csrf
        <input type="hidden" name="question" value="{{ $question }}">
        <template x-for="id in picked" :key="id">
          <input type="hidden" name="picked[]" :value="id">
        </template>
        <button type="submit" class="btn btn-primary" style="font-size:14px;padding:20px 44px"
                :disabled="submitting"
                :style="submitting ? 'opacity:.6;cursor:wait' : ''">
          <span x-show="!submitting">เปิดไพ่ทั้ง {{ $needed }} ใบ</span>
          <span x-show="submitting">กำลังเปิดไพ่ ⋯</span>
          <svg x-show="!submitting" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </button>
      </form>

      <div x-show="picked.length < needed" class="pick-hint">
        กรุณาเลือกอีก <strong x-text="needed - picked.length"></strong> ใบเพื่อเปิดดวง
      </div>
    </div>

    <div style="text-align:center;margin-top:24px">
      <a href="{{ route('tarot.index') }}" class="btn btn-ghost" style="padding:12px 28px;font-size:13px">← กลับไปเลือกรูปแบบใหม่</a>
    </div>

  </div>
</section>

@push('scripts')
<script>
  // Alpine component: chunks the 78-card array into responsive rows + resizes on viewport change.
  document.addEventListener('alpine:init', () => {
    Alpine.data('cardFan', ({ cards, needed }) => ({
      cards,
      needed,
      picked: [],
      rows: [],
      init() {
        this.rebuild();
        let t;
        window.addEventListener('resize', () => {
          clearTimeout(t);
          t = setTimeout(() => this.rebuild(), 120);
        });
      },
      rebuild() {
        const w = window.innerWidth;
        let perRow;
        if (w >= 1400) perRow = 26;
        else if (w >= 1100) perRow = 20;
        else if (w >= 880)  perRow = 16;
        else if (w >= 640)  perRow = 13;
        else if (w >= 480)  perRow = 11;
        else                perRow = 9;

        const rows = [];
        let gi = 0;
        for (let i = 0; i < this.cards.length; i += perRow) {
          const slice = this.cards.slice(i, i + perRow);
          rows.push(slice.map((c, ri) => ({ id: c.id, ri, gi: gi++ })));
        }
        // Balance: if the last row has < half perRow, pull from previous row's tail
        if (rows.length >= 2) {
          const last = rows[rows.length - 1];
          const prev = rows[rows.length - 2];
          if (last.length < Math.floor(perRow / 2)) {
            const move = Math.floor((perRow - last.length) / 2);
            const moved = prev.splice(prev.length - move, move);
            rows[rows.length - 1] = [...moved, ...last].map((c, ri) => ({ ...c, ri }));
            prev.forEach((c, ri) => (c.ri = ri));
          }
        }
        this.rows = rows;
      },
      toggle(id) {
        const i = this.picked.indexOf(id);
        if (i >= 0) {
          this.picked.splice(i, 1);
        } else if (this.picked.length < this.needed) {
          this.picked.push(id);
          if (this.picked.length === this.needed) {
            this.$nextTick(() => document.getElementById('reveal-anchor')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
          }
        }
      },
      reset() { this.picked = []; }
    }));
  });
</script>
@endpush
@endsection
