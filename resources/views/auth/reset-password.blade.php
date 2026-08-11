@extends('layouts.app')
@section('title', 'ตั้งรหัสผ่านใหม่')

@section('content')
<section class="canvas auth-canvas" style="padding-top:160px">
  {{-- ฉากหลังซุ้มประตูจักรวาล — ของประดับล้วน ไม่มีข้อมูลอยู่ในภาพ --}}
  <div class="auth-backdrop" aria-hidden="true">
    <img src="{{ asset('images/juntra/art/auth.webp') }}" alt="" width="1100" height="1467" decoding="async">
  </div>
  <div style="max-width:520px;margin:0 auto">
    <div style="text-align:center;margin-bottom:48px">
      <div class="eyebrow" style="display:inline-flex">RESET PASSWORD</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">ตั้ง <em>รหัสผ่านใหม่</em></h1>
    </div>

    <form action="{{ route('password.store') }}" method="POST" class="panel">
      @csrf
      <input type="hidden" name="token" value="{{ $request->route('token') }}">
      <div class="field">
        <label for="email">อีเมล</label>
        <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus>
      </div>
      <div class="field">
        <label for="password">รหัสผ่านใหม่</label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
      </div>
      <div class="field">
        <label for="password_confirmation">ยืนยันรหัสผ่าน</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center">ตั้งรหัสผ่าน</button>
    </form>
  </div>
</section>
@endsection
