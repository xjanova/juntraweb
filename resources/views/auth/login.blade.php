@extends('layouts.app')
@section('title', 'เข้าสู่ระบบ')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:520px;margin:0 auto">
    <div style="text-align:center;margin-bottom:48px">
      <div class="eyebrow" style="display:inline-flex">SIGN IN</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">เปิดประตู <em>สู่ดวงชะตา</em></h1>
      <p class="lede" style="margin:0 auto">เข้าสู่ระบบเพื่อบันทึกประวัติการดูดวงและคำพยากรณ์ที่ได้รับ</p>
    </div>

    <form action="{{ route('login') }}" method="POST" class="panel">
      @csrf

      @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
      @endif

      <div class="field">
        <label for="email">อีเมล</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label for="password">รหัสผ่าน</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
      </div>
      <label style="display:flex;align-items:center;gap:10px;margin-bottom:24px;color:var(--ink-dim);font-size:14px;cursor:pointer">
        <input type="checkbox" name="remember" style="accent-color:var(--gold)">
        จดจำการเข้าสู่ระบบ
      </label>
      <button class="btn btn-primary" style="width:100%;justify-content:center">
        เข้าสู่ระบบ
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </button>

      <div style="display:flex;justify-content:space-between;margin-top:24px;font-size:13px">
        @if (Route::has('password.request'))
          <a href="{{ route('password.request') }}" style="color:var(--ink-dim)">ลืมรหัสผ่าน?</a>
        @endif
        <a href="{{ route('register') }}" style="color:var(--gold)">สมัครสมาชิก →</a>
      </div>
    </form>
  </div>
</section>
@endsection
