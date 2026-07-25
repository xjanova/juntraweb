@extends('layouts.app')
@section('page-handles-flash', '1')
@section('title', 'ลืมรหัสผ่าน')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:520px;margin:0 auto">
    <div style="text-align:center;margin-bottom:48px">
      <div class="eyebrow" style="display:inline-flex">RESET PASSWORD</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">ลืม <em>รหัสผ่าน</em>?</h1>
      <p class="lede" style="margin:0 auto">กรอกอีเมลของคุณ ระบบจะส่งลิงก์รีเซ็ตรหัสผ่านให้</p>
    </div>

    <form action="{{ route('password.email') }}" method="POST" class="panel">
      @csrf
      @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
      <div class="field">
        <label for="email">อีเมล</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center">ส่งลิงก์รีเซ็ตรหัสผ่าน</button>
    </form>
  </div>
</section>
@endsection
