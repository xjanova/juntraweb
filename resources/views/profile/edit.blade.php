@extends('layouts.app')
@section('title', 'โปรไฟล์ของฉัน')

@section('content')
<section class="canvas" style="padding-top:120px">
  <div class="account-shell">
    @include('partials.account-sidebar', ['active' => 'profile'])

    <div class="account-content">
    <div class="eyebrow">PROFILE • ACCOUNT</div>
    <h2 style="font-family:var(--serif);font-size:clamp(32px,4vw,52px);font-weight:400;margin:8px 0 28px">โปรไฟล์ <em style="color:var(--gold)">ของฉัน</em></h2>

    <div class="panel" style="margin-bottom:24px;padding:28px">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:18px">ข้อมูลทั่วไป</div>
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
          @if (! $user->hasVerifiedEmail() && $user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail)
            <p style="font-size:12px;color:#d4a017;margin-top:6px">⚠ อีเมลของคุณยังไม่ได้ยืนยัน</p>
          @elseif ($user->email_verified_at)
            <p style="font-size:12px;color:var(--gold);margin-top:6px">✓ ยืนยันอีเมลเมื่อ {{ $user->email_verified_at->diffForHumans() }}</p>
          @endif
        </div>
        <button class="btn btn-primary">บันทึก</button>
        @if (session('status') === 'profile-updated')
          <p style="display:inline-block;margin-left:16px;color:var(--gold)">บันทึกแล้ว</p>
        @endif
      </form>
    </div>

    <div class="panel" style="margin-bottom:24px;padding:28px">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:18px">เปลี่ยนรหัสผ่าน</div>
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

    <div class="panel" style="margin-bottom:24px;padding:24px">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">ทางลัด</div>
      <div style="display:flex;flex-wrap:wrap;gap:10px">
        <a href="{{ route('account.astrology') }}" class="btn btn-ghost">ข้อมูลโหราศาสตร์</a>
        <a href="{{ route('account.security') }}" class="btn btn-ghost">อุปกรณ์ & เซสชัน</a>
      </div>
    </div>

    <div class="panel" style="border-color:var(--rose);padding:24px">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:14px;color:var(--rose)">DANGER ZONE</div>
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
  </div>
</section>
@endsection
