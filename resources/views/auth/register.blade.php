@extends('layouts.app')
@section('title', 'สมัครสมาชิก')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:520px;margin:0 auto">
    <div style="text-align:center;margin-bottom:48px">
      <div class="eyebrow" style="display:inline-flex">CREATE ACCOUNT</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">เริ่มต้น <em>เดินทางใหม่</em></h1>
      <p class="lede" style="margin:0 auto">สมัครสมาชิกฟรี รับการดูดวงครั้งแรก พร้อมบันทึกประวัติทั้งหมดของคุณ</p>
    </div>

    <form action="{{ route('register') }}" method="POST" class="panel">
      @csrf
      <div class="field">
        <label for="name">ชื่อที่อยากให้แม่หมอเรียก</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
      </div>
      <div class="field">
        <label for="email">อีเมล</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
      </div>
      <div class="field">
        <label for="password">รหัสผ่าน</label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
      </div>
      <div class="field">
        <label for="password_confirmation">ยืนยันรหัสผ่าน</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
      </div>
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
@endsection
