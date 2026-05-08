@extends('install.layout')

@section('content')
<h2>ตรวจสอบระบบ</h2>
<p class="lede">ก่อนเริ่มติดตั้ง ระบบจะตรวจว่า server มีส่วนประกอบครบถ้วนหรือไม่ ทุกข้อต้องผ่านจึงจะติดตั้งได้</p>

<ul class="check-list">
  @foreach ($checks as $c)
    <li>
      <span>{{ $c['label'] }}</span>
      <span class="{{ $c['ok'] ? 'ok' : 'fail' }}">{{ $c['ok'] ? '✓ ผ่าน' : '✗ ไม่ผ่าน' }}</span>
    </li>
  @endforeach
</ul>

<div class="actions">
  <span></span>
  @if ($allOk)
    <a href="{{ route('install.database') }}" class="btn">ถัดไป — ตั้งค่าฐานข้อมูล →</a>
  @else
    <button class="btn" disabled style="opacity:.5;cursor:not-allowed">มีรายการที่ไม่ผ่าน</button>
  @endif
</div>
@endsection
