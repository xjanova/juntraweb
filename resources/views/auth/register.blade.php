@extends('layouts.app')
@section('title', 'สมัครสมาชิก')

@php $turnstileKey = \App\Support\Turnstile::siteKey(); @endphp

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:520px;margin:0 auto">
    <div style="text-align:center;margin-bottom:40px">
      <div class="eyebrow" style="display:inline-flex">CREATE ACCOUNT</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">เริ่มต้น <em>เดินทางใหม่</em></h1>
      <p class="lede" style="margin:0 auto">สมัครฟรี คุยกับแม่หมอได้ทันที พร้อมบันทึกคำทำนายทั้งหมดไว้เปิดอ่านย้อนหลัง</p>
    </div>

    {{-- เข้าด้วยบัญชีเดิมง่ายที่สุด — วางไว้บนสุดก่อนฟอร์ม ลูกค้าจากบอทแม่หมอ
         กดปุ่มเดียวจบ ไม่ต้องตั้งรหัสผ่านใหม่ให้จำเพิ่ม --}}
    <div class="panel" style="padding:22px 24px;margin-bottom:18px;text-align:center">
      <div style="font-size:14px;color:var(--ink-dim);margin-bottom:14px;line-height:1.7">
        เคยคุยกับแม่หมอใน <strong style="color:var(--gold)">Facebook</strong> หรือ
        <strong style="color:var(--gold)">LINE</strong> อยู่แล้ว?
      </div>
      <a href="{{ route('thaiprompt.redirect') }}" class="btn btn-primary" style="width:100%;justify-content:center">
        เข้าใช้ด้วยบัญชีเดิม ไม่ต้องสมัครใหม่
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
    </div>

    <div style="text-align:center;margin:18px 0;font-size:12px;color:var(--ink-faint);letter-spacing:.2em">
      — หรือสมัครใหม่ —
    </div>

    <form action="{{ route('register') }}" method="POST" class="panel">
      @csrf
      <div class="field">
        <label for="name">ชื่อที่อยากให้แม่หมอเรียก</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
      </div>

      {{-- กรอกอย่างใดอย่างหนึ่งพอ — ลูกค้าสูงอายุหลายคนไม่มีอีเมลหรือจำไม่ได้
           ถ้าใส่แต่เบอร์ ระบบจะสร้างอีเมลภายในให้เองจากเบอร์ --}}
      <div class="field">
        <label for="phone">เบอร์โทรศัพท์</label>
        <input id="phone" type="tel" name="phone" value="{{ old('phone') }}"
               inputmode="numeric" autocomplete="tel" placeholder="08x-xxx-xxxx">
      </div>

      <div class="field">
        <label for="email">อีเมล <span style="color:var(--ink-faint);font-size:11px">(ไม่มีก็ได้ ใส่เบอร์อย่างเดียวพอ)</span></label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username">
      </div>

      <div class="field">
        <label for="password">รหัสผ่าน</label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
      </div>
      <div class="field">
        <label for="password_confirmation">ยืนยันรหัสผ่าน</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
      </div>

      @if ($turnstileKey)
        <div class="cf-turnstile" data-sitekey="{{ $turnstileKey }}" data-theme="dark" data-language="th"
             style="margin-bottom:18px;display:flex;justify-content:center"></div>
      @endif

      <button class="btn btn-primary" style="width:100%;justify-content:center">
        สมัครสมาชิก
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </button>
      <div style="text-align:center;margin-top:24px;font-size:13px;color:var(--ink-dim)">
        เป็นสมาชิกอยู่แล้ว? <a href="{{ route('login') }}" style="color:var(--gold)">เข้าสู่ระบบ →</a>
      </div>
    </form>
  </div>
</section>

@if ($turnstileKey)
  @push('scripts')
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  @endpush
@endif
@endsection
