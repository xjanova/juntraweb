@extends('install.layout')

@section('content')
<h2>สร้างผู้ดูแลระบบ</h2>
<p class="lede">บัญชีนี้จะเข้าหน้า /admin ได้ และจัดการเนื้อหาทั้งหมดบนเว็บ</p>

<form method="POST" action="{{ route('install.admin.save') }}">
  @csrf
  <div class="field">
    <label for="name">ชื่อแสดงผล</label>
    <input type="text" name="name" id="name" value="{{ $data['name'] ?? '' }}" required>
  </div>

  <div class="field">
    <label for="email">อีเมล</label>
    <input type="email" name="email" id="email" value="{{ $data['email'] ?? '' }}" required>
  </div>

  <div class="row">
    <div class="field">
      <label for="password">รหัสผ่าน</label>
      <input type="password" name="password" id="password" required minlength="8">
      <div class="hint">อย่างน้อย 8 ตัวอักษร</div>
    </div>
    <div class="field">
      <label for="password_confirmation">ยืนยันรหัสผ่าน</label>
      <input type="password" name="password_confirmation" id="password_confirmation" required>
    </div>
  </div>

  <div class="actions">
    <a href="{{ route('install.database') }}" class="btn btn-secondary">← กลับ</a>
    <button type="submit" class="btn">สร้างบัญชีแอดมิน →</button>
  </div>
</form>
@endsection
