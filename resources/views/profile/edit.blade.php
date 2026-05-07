@extends('layouts.app')
@section('title', 'โปรไฟล์ของฉัน')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:680px;margin:0 auto">
    <div class="eyebrow">PROFILE</div>
    <h2 style="font-family:var(--serif);font-size:clamp(40px,5vw,68px);font-weight:400;margin-bottom:48px">โปรไฟล์ <em style="color:var(--gold)">ของฉัน</em></h2>

    <div class="panel" style="margin-bottom:24px">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:24px">ข้อมูลทั่วไป</div>
      <form method="POST" action="{{ route('profile.update') }}">
        @csrf
        @method('patch')
        <div class="field">
          <label for="name">ชื่อ</label>
          <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required>
        </div>
        <div class="field">
          <label for="email">อีเมล</label>
          <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required>
        </div>
        <button class="btn btn-primary">บันทึก</button>
        @if (session('status') === 'profile-updated')
          <p style="display:inline-block;margin-left:16px;color:var(--gold)">บันทึกแล้ว</p>
        @endif
      </form>
    </div>

    <div class="panel" style="margin-bottom:24px">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:24px">เปลี่ยนรหัสผ่าน</div>
      <form method="POST" action="{{ route('password.update') }}">
        @csrf
        @method('put')
        <div class="field">
          <label for="current_password">รหัสผ่านเดิม</label>
          <input id="current_password" type="password" name="current_password" autocomplete="current-password">
        </div>
        <div class="field">
          <label for="password">รหัสผ่านใหม่</label>
          <input id="password" type="password" name="password" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="password_confirmation">ยืนยันรหัสผ่านใหม่</label>
          <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password">
        </div>
        <button class="btn btn-primary">บันทึกรหัสผ่าน</button>
        @if (session('status') === 'password-updated')
          <p style="display:inline-block;margin-left:16px;color:var(--gold)">เปลี่ยนรหัสผ่านแล้ว</p>
        @endif
      </form>
    </div>

    <div class="panel" style="border-color:var(--rose)">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:16px;color:var(--rose)">DANGER ZONE</div>
      <p style="color:var(--ink-dim);margin-bottom:16px;font-size:14px">เมื่อลบบัญชี ประวัติการดูดวงทั้งหมดจะถูกลบและไม่สามารถกู้คืนได้</p>
      <form method="POST" action="{{ route('profile.destroy') }}" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบบัญชี?')">
        @csrf
        @method('delete')
        <div class="field">
          <label for="del_password">ยืนยันรหัสผ่าน</label>
          <input id="del_password" type="password" name="password" required>
        </div>
        <button class="btn btn-ghost" style="border-color:var(--rose);color:var(--rose)">ลบบัญชี</button>
      </form>
    </div>
  </div>
</section>
@endsection
