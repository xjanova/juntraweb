@extends('layouts.app')
@section('title', 'ยืนยันรหัสผ่าน')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:520px;margin:0 auto">
    <div style="text-align:center;margin-bottom:48px">
      <div class="eyebrow" style="display:inline-flex">CONFIRM PASSWORD</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">ยืนยัน <em>รหัสผ่าน</em></h1>
      <p class="lede" style="margin:0 auto">โปรดยืนยันรหัสผ่านก่อนเข้าหน้าที่มีข้อมูลสำคัญ</p>
    </div>

    <form action="{{ route('password.confirm') }}" method="POST" class="panel">
      @csrf
      <div class="field">
        <label for="password">รหัสผ่าน</label>
        <input id="password" type="password" name="password" required autofocus autocomplete="current-password">
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center">ยืนยัน</button>
    </form>
  </div>
</section>
@endsection
