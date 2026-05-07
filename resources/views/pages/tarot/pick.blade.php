@extends('layouts.app')
@section('title', 'เลือกไพ่ของคุณ')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:1200px;margin:0 auto"
       x-data="{
         picked: [],
         needed: {{ $needed }},
         toggle(id) {
           const i = this.picked.indexOf(id);
           if (i >= 0) {
             this.picked.splice(i, 1);
           } else if (this.picked.length < this.needed) {
             this.picked.push(id);
             if (this.picked.length === this.needed) {
               // gentle scroll to the reveal button
               this.$nextTick(() => document.getElementById('reveal-anchor')?.scrollIntoView({behavior:'smooth', block:'center'}));
             }
           }
         },
         reset() { this.picked = []; },
         orderOf(id) { return this.picked.indexOf(id) + 1; }
       }">

    <div style="text-align:center;margin-bottom:32px">
      <div class="eyebrow" style="display:inline-flex">เลือกไพ่ของคุณ</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">
        จับ <em>{{ $needed }} ใบ</em> @if($needed === 10)<span style="font-size:.55em;letter-spacing:.18em;display:inline-block;vertical-align:middle;color:var(--gold,#e7c97a)">CELTIC CROSS</span>@endif
      </h1>
      <p class="lede" style="margin:0 auto">
        @if ($question)
          คำถามของคุณ: <em style="color:var(--gold-2,var(--gold,#e7c97a))">"{{ $question }}"</em><br>
        @endif
        ทำใจให้สงบ — แตะที่ไพ่หงาย {{ $needed }} ใบเรียงตามลำดับที่ใจคุณเลือก
      </p>
    </div>

    {{-- Status bar — sticky so user always sees progress --}}
    <div class="pick-status" :class="picked.length === needed ? 'is-complete' : ''">
      <div>
        เลือกแล้ว <strong x-text="picked.length"></strong> / {{ $needed }} ใบ
      </div>
      <button type="button" @click="reset" :disabled="picked.length === 0" class="pick-reset-btn">เลือกใหม่</button>
    </div>

    {{-- The fan — 78 face-down cards in a responsive auto-fill grid --}}
    <div class="card-fan">
      @foreach ($cards as $card)
        <button type="button"
                class="fan-card"
                :class="picked.includes({{ $card->id }}) ? 'is-picked' : ''"
                @click="toggle({{ $card->id }})"
                aria-label="ไพ่หงาย {{ $loop->iteration }}">
          <span class="fan-card-back" aria-hidden="true">
            <svg viewBox="0 0 60 110" width="100%" height="100%" preserveAspectRatio="xMidYMid meet">
              <defs>
                <radialGradient id="g{{ $loop->iteration }}" cx="50%" cy="50%" r="50%">
                  <stop offset="0%" stop-color="rgba(176,122,255,.55)"/>
                  <stop offset="60%" stop-color="rgba(42,18,89,.95)"/>
                  <stop offset="100%" stop-color="#0a0612"/>
                </radialGradient>
              </defs>
              <rect x="2" y="2" width="56" height="106" rx="6" fill="url(#g{{ $loop->iteration }})" stroke="rgba(231,201,122,.5)" stroke-width=".7"/>
              <rect x="5" y="5" width="50" height="100" rx="4" fill="none" stroke="rgba(231,201,122,.25)" stroke-width=".4" stroke-dasharray="1.5 2"/>
              <circle cx="30" cy="55" r="14" fill="none" stroke="rgba(231,201,122,.7)" stroke-width=".6"/>
              <circle cx="30" cy="55" r="9" fill="none" stroke="rgba(244,207,106,.5)" stroke-width=".4"/>
              <path d="M30 41 L33 55 L30 69 L27 55 Z M16 55 L30 53 L44 55 L30 57 Z" fill="rgba(244,207,106,.6)"/>
              <circle cx="30" cy="55" r="1.5" fill="#f4cf6a"/>
            </svg>
          </span>
          <span class="fan-card-marker" x-show="picked.includes({{ $card->id }})" x-text="orderOf({{ $card->id }})"></span>
        </button>
      @endforeach
    </div>

    <div id="reveal-anchor" style="height:1px"></div>

    {{-- Reveal CTA — appears when N cards picked --}}
    <div style="margin:48px auto 24px;text-align:center;min-height:80px">
      <form action="{{ route($targetRoute) }}" method="POST" x-show="picked.length === needed" x-transition.duration.500ms style="display:inline-block">
        @csrf
        <input type="hidden" name="question" value="{{ $question }}">
        <template x-for="id in picked">
          <input type="hidden" name="picked[]" :value="id">
        </template>
        <button type="submit" class="btn btn-primary" style="font-size:14px;padding:20px 44px">
          เปิดไพ่ทั้ง {{ $needed }} ใบ
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
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
@endsection
