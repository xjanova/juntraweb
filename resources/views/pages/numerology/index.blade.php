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

    <form action="{{ route('numerology.calculate') }}" method="POST" class="panel">
      @csrf
      <div class="field">
        <label for="name">ชื่อ-นามสกุลจริง</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="เช่น จันทรา ศรีพยากรณ์" required>
      </div>
      <div class="field">
        <label for="birth_date">วัน-เดือน-ปีเกิด</label>
        <input type="date" id="birth_date" name="birth_date" value="{{ old('birth_date') }}" required>
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center">
        คำนวณดวงเลข
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </button>
    </form>
  </div>
</section>
@endsection
