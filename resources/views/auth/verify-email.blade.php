@extends('layouts.app')
@section('title', 'ยืนยันอีเมล')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:520px;margin:0 auto">
    <div style="text-align:center;margin-bottom:48px">
      <div class="eyebrow" style="display:inline-flex">VERIFY EMAIL</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">ยืนยัน <em>อีเมล</em></h1>
      <p class="lede" style="margin:0 auto">เราได้ส่งลิงก์ยืนยันอีเมลไปยังอีเมลของคุณแล้ว — โปรดตรวจกล่องจดหมาย</p>
    </div>

    @if (session('status') == 'verification-link-sent')
      <div class="flash" style="margin-bottom:24px">ส่งลิงก์ยืนยันใหม่แล้ว — โปรดตรวจอีเมล</div>
    @endif

    <div class="panel" style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <form action="{{ route('verification.send') }}" method="POST">
        @csrf
        <button class="btn btn-primary">ส่งลิงก์อีกครั้ง</button>
      </form>
      <form action="{{ route('logout') }}" method="POST">
        @csrf
        <button class="btn btn-ghost">ออกจากระบบ</button>
      </form>
    </div>
  </div>
</section>
@endsection
