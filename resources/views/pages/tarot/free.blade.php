@extends('layouts.app')
@section('title', 'ดูดวงฟรี 1 ใบ')

@php
  /**
   * 🎁 (2026-07-26) หน้ารับคำทำนายฟรี 1 ใบ
   *
   * ลูกค้าจากบอทเฟซบุ๊ก/ไลน์กดปุ่ม "ดูดวงฟรี" → magic link → ล็อกอินให้อัตโนมัติ
   * → มาถึงหน้านี้ → หน้าเว็บยิง POST ให้เองครั้งเดียว แล้วเด้งไปหน้าคำทำนาย
   *
   * ทำไมไม่ทำนายใน GET เลย: รีเฟรช/prefetch จะยิง AI ซ้ำและกินโควตาฟรีของระบบ
   */
  $allowed = (bool) ($gate['allowed'] ?? false);
  $code    = $gate['code'] ?? null;

  /**
   * 🛑 ห้าม auto-submit ถ้ารอบก่อนเพิ่งล้ม
   *
   * ขาล้มของ cast() จะ "คืนสิทธิ์" แล้ว redirect กลับมาที่นี่ ถ้าหน้ายิงเองอีก
   * จะกลายเป็นลูป: ล้ม → คืนสิทธิ์ → ยิงใหม่ → ล้ม ... จบที่หน้า 429 ภาษาอังกฤษ
   * (และถ้าฝั่งอัปสตรีมช้าจนเกิน timeout มันจะสร้างคำทำนายจริงทุกรอบแล้วถูกทิ้ง
   *  = เผา token ฟรีไม่จำกัด) รอบ retry จึงให้ลูกค้ากดปุ่มเอง
   */
  $isRetry  = (bool) session('free_retry');
  $autoCast = $allowed && ! $isRetry;
@endphp

@push('head')
<style>
  .free-wrap { max-width: 620px; margin: 0 auto; text-align: center; }
  .free-orb {
    width: 132px; height: 132px; margin: 8px auto 26px;
    border-radius: 50%;
    border: 1px solid var(--line-soft);
    background:
      radial-gradient(60% 60% at 50% 35%, rgba(244,207,106,.20), transparent 70%),
      linear-gradient(160deg, rgba(106,59,214,.22), rgba(7,4,26,0) 70%);
    display: flex; align-items: center; justify-content: center;
    font-size: 46px;
    animation: freePulse 2.6s ease-in-out infinite;
  }
  @keyframes freePulse {
    0%, 100% { transform: scale(1);    opacity: .92; }
    50%      { transform: scale(1.06); opacity: 1;   }
  }
  @media (prefers-reduced-motion: reduce) {
    .free-orb { animation: none; }
  }
  .free-note {
    font-size: 14px; line-height: 1.8; color: var(--ink-dim);
    max-width: 46ch; margin: 0 auto 26px;
  }
  .free-actions { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
</style>
@endpush

@section('content')
<section class="canvas" style="padding-top:160px">
  <div class="free-wrap">

    {{-- แบนเนอร์ประดับล้วน ไม่มีข้อความ — หัวเรื่องจริงของหน้านี้อยู่ในบล็อกด้านล่าง
         ซึ่งเปลี่ยนไปตามสถานะ (ได้สิทธิ์ / ลองใหม่ / สิทธิ์หมด) --}}
    <x-page-hero art="images/juntra/art/tarot-free.webp" />

    @if ($allowed)
      {{-- ── เปิดไพ่ (อัตโนมัติ / หรือให้กดเองเมื่อรอบก่อนล้ม) ───────── --}}
      <div x-data="{ sent: false }"
           @if ($autoCast) x-init="$nextTick(() => { if (! sent) { sent = true; $refs.castForm.submit(); } })" @endif>

        <div class="free-orb">🔮</div>
        <div class="eyebrow" style="display:inline-flex">ของขวัญจากแม่หมอ</div>

        @if ($isRetry)
          <h2 style="margin-bottom:10px">ลอง <em>อีกครั้ง</em> นะคะ</h2>
          <p class="free-note">สิทธิ์ดูดวงฟรีของคุณยังอยู่ครบค่ะ กดปุ่มด้านล่างเพื่อให้แม่หมอเปิดไพ่ใหม่</p>
        @else
          <h2 style="margin-bottom:10px">แม่หมอกำลัง <em>สับไพ่ให้คุณ</em></h2>
          <p class="free-note">
            นิ่ง ๆ สักครู่นะคะ ให้จิตของคุณเลือกไพ่เอง —
            แม่หมอจะอ่านให้ฟังว่าชีวิตช่วงนี้ของคุณกำลังเดินไปทางไหน
          </p>
        @endif

        <form action="{{ route('tarot.free.cast') }}" method="POST" x-ref="castForm">
          @csrf
          {{-- ปุ่มจริงเสมอ: รอบ retry ต้องกดเอง ส่วนรอบปกติเป็นตาข่ายกันเคส
               JS/Alpine โหลดไม่ขึ้นใน webview เก่า (noscript ไม่ครอบเคสนั้น) --}}
          <button type="submit" class="btn btn-primary"
                  @if ($autoCast) x-show="! sent" x-cloak @endif
                  x-on:click="sent = true">
            เปิดไพ่ให้ฉัน
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </button>
        </form>
      </div>

    @elseif ($code === 'in_flight')
      {{-- ── กำลังเปิดไพ่อยู่ (อีกแท็บ/กดซ้ำ) — รอแล้วรีเฟรชเอง ────── --}}
      <div class="free-orb">🔮</div>
      <div class="eyebrow" style="display:inline-flex">ของขวัญจากแม่หมอ</div>
      <h2 style="margin-bottom:10px">แม่หมอกำลัง <em>เปิดไพ่ให้อยู่</em></h2>
      <p class="free-note">รอสักครู่นะคะ หน้านี้จะพาไปที่คำทำนายให้เองเมื่อเสร็จ</p>
      <div x-data x-init="setTimeout(() => window.location.reload(), 5000)"></div>
      <div class="free-actions">
        <a href="{{ route('tarot.free') }}" class="btn btn-ghost">รีเฟรชเอง</a>
      </div>

    @elseif ($code === 'no_token')
      {{-- ── เซสชันกับ Thaiprompt หมดอายุ — ยิงไปก็ล้ม ให้เข้าระบบใหม่ --}}
      <div class="free-orb">🌙</div>
      <div class="eyebrow" style="display:inline-flex">ดูดวงฟรี 1 ใบ</div>
      <h2 style="margin-bottom:10px">เข้าสู่ระบบใหม่ <em>อีกครั้งนะคะ</em></h2>
      <p class="free-note">เซสชันของคุณหมดอายุแล้วค่ะ กดปุ่มด้านล่างเพื่อเข้าระบบใหม่ แล้วแม่หมอจะเปิดไพ่ให้ทันที</p>
      <div class="free-actions">
        <a href="{{ $loginUrl }}" class="btn btn-primary">เข้าสู่ระบบใหม่
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
      </div>

    @elseif ($code === 'guest')
      {{-- ── ยังไม่ล็อกอิน (SSO หลุด) ─────────────────────────────── --}}
      <div class="free-orb">🌙</div>
      <div class="eyebrow" style="display:inline-flex">ดูดวงฟรี 1 ใบ</div>
      <h2 style="margin-bottom:10px">เข้าสู่ระบบ <em>เพื่อรับคำทำนาย</em></h2>
      <p class="free-note">
        แม่หมอเก็บคำทำนายไว้ให้คุณย้อนดูได้ทุกเมื่อ จึงขอให้เข้าระบบสักครู่ค่ะ —
        ใช้บัญชีเดิมที่คุณคุยกับแม่หมออยู่ ไม่ต้องกรอกอะไรใหม่
      </p>
      <div class="free-actions">
        <a href="{{ $loginUrl }}" class="btn btn-primary">เข้าสู่ระบบแล้วรับไพ่
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
      </div>

    @else
      {{-- ── ใช้สิทธิ์แล้ว / เต็มโควตาวันนี้ / ปิดรอบ ─────────────── --}}
      <div class="free-orb">✨</div>
      <div class="eyebrow" style="display:inline-flex">ดูดวงฟรี</div>
      <h2 style="margin-bottom:10px">{{ $gate['reason'] ?? 'ตอนนี้ยังเปิดไพ่ฟรีให้ไม่ได้ค่ะ' }}</h2>
      <p class="free-note">
        @if ($code === 'daily_cap')
          แม่หมออ่านไพ่เองทีละคน วันนี้เลยเปิดได้จำกัดค่ะ 🙏 พรุ่งนี้มาใหม่นะคะ
          หรือถ้าอยากดูตอนนี้เลย เลือกเปิดไพ่ชุดเต็มได้เลยค่ะ
        @else
          ถ้าอยากให้แม่หมออ่านลึกกว่านี้ เลือกเปิดไพ่ชุดเต็มได้เลยค่ะ
        @endif
      </p>
      <div class="free-actions">
        <a href="{{ route('tarot.index') }}" class="btn btn-primary">เปิดไพ่ชุดเต็ม
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
        <a href="{{ route('chat.index') }}" class="btn btn-ghost">คุยกับแม่หมอ</a>
      </div>
    @endif

  </div>
</section>
@endsection
