@extends('layouts.app')
@section('title', 'เลขศาสตร์')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:680px;margin:0 auto">
    <div style="text-align:center;margin-bottom:60px">
      <div class="eyebrow" style="display:inline-flex">เลขศาสตร์ · NUMEROLOGY</div>
      <h1 class="display" style="font-size:clamp(48px,6vw,84px);margin-bottom:24px">ตัวเลข <em>กำหนดชะตา</em></h1>
      <p class="lede" style="margin:0 auto">คำนวณเลข Life Path, Expression, และ Birth Day จากชื่อและวันเกิดของคุณ พร้อมคำอธิบายความหมายเชิงลึก</p>
    </div>

    @if (isset($cost) && $cost > 0)
      <div style="text-align:center;margin-bottom:24px;font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase">
        ค่าบริการ ฿{{ number_format($cost, $cost == intval($cost) ? 0 : 2) }} / ครั้ง
      </div>
    @endif
    <form action="{{ route('numerology.calculate') }}" method="POST" class="panel"
          x-data="{submitting:false}" @submit="submitting=true">
      @csrf
      <div class="field">
        <label for="name">ชื่อ-นามสกุลจริง</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="เช่น จันทรา ศรีพยากรณ์" required>
      </div>
      <div class="field">
        <label for="birth_date">วัน-เดือน-ปีเกิด</label>
        <input type="date" id="birth_date" name="birth_date" value="{{ old('birth_date') }}" required>
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center" :disabled="submitting" :style="submitting ? 'opacity:.6;cursor:wait' : ''">
        <span x-show="!submitting">คำนวณดวงเลข</span>
        <span x-show="submitting">กำลังคำนวณ ⋯</span>
        <svg x-show="!submitting" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </button>
    </form>
  </div>
</section>
@endsection
