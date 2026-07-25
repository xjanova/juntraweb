@extends('layouts.app')
@section('title', 'ดูลายมือ AI')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:680px;margin:0 auto">
    <div style="text-align:center;margin-bottom:60px">
      <div class="eyebrow" style="display:inline-flex">ดูลายมือ AI · PALMISTRY</div>
      <h1 class="display" style="font-size:clamp(48px,6vw,84px);margin-bottom:24px">ฝ่ามือ <em>ภาพชะตา</em></h1>
      <p class="lede" style="margin:0 auto">อัพโหลดรูปฝ่ามือซ้าย (สำหรับผู้ชาย) หรือฝ่ามือขวา (สำหรับผู้หญิง) ในที่มีแสงสว่างเพียงพอ — AI จะวิเคราะห์เส้นชีวิต เส้นใจ เส้นปัญญา</p>
    </div>

    @if (isset($cost) && $cost > 0)
      <div style="text-align:center;margin-bottom:24px;font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase">
        ค่าบริการ ฿{{ number_format($cost, $cost == intval($cost) ? 0 : 2) }} / ครั้ง
      </div>
    @endif
    <form action="{{ route('palmistry.analyze') }}" method="POST" enctype="multipart/form-data" class="panel"
          x-data="{submitting:false}" @submit="submitting=true">
      @csrf
      <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::uuid() }}">
      <div class="field">
        <label for="image">รูปฝ่ามือ (JPG/PNG, ≤ 4MB)</label>
        <input type="file" id="image" name="image" accept="image/*" required>
      </div>
      <div class="field">
        <label for="question">คำถาม (ไม่ใส่ก็ได้)</label>
        <textarea id="question" name="question" rows="2" placeholder="เช่น ดวงเรื่องการเงินจะเป็นอย่างไร, มีโอกาสแต่งงานไหม"></textarea>
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center" :disabled="submitting">
        <span x-show="!submitting">วิเคราะห์ลายมือ</span>
        <span x-show="submitting">กำลังวิเคราะห์ ⋯</span>
        <svg x-show="!submitting" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </button>
    </form>
  </div>
</section>
@endsection
