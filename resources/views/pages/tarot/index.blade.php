@extends('layouts.app')
@section('title', 'ไพ่ยิปซี · Tarot Reading')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="text-align:center;max-width:780px;margin:0 auto 60px">
    <div class="eyebrow" style="display:inline-flex">ไพ่ยิปซี Rider-Waite</div>
    <h1 class="display" style="font-size:clamp(48px,6vw,84px);margin-bottom:24px">เปิดไพ่ <em>ค้นหาคำตอบ</em></h1>
    <p class="lede" style="margin:0 auto">เลือกรูปแบบการดูดวง — สามใบสำหรับเรื่องด่วน, Celtic Cross 10 ใบสำหรับเรื่องลึก แม่หมอจันทรา AI จะวิเคราะห์ให้ทันที</p>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;max-width:1080px;margin:0 auto" x-data="{spread: 'three', question: ''}">

    <div class="panel">
      <div class="eyebrow" style="display:inline-flex">เลือกรูปแบบ</div>
      <div style="display:flex;flex-direction:column;gap:14px;margin-top:16px">
        <label style="display:flex;gap:14px;padding:18px 20px;border:1px solid var(--line-soft);border-radius:14px;cursor:pointer;align-items:center" :style="spread==='three' ? 'border-color:var(--gold);background:rgba(244,207,106,.08)' : ''">
          <input type="radio" name="spread" value="three" x-model="spread" style="accent-color:var(--gold)">
          <div>
            <div style="font-family:var(--display);letter-spacing:.18em;color:var(--gold);font-size:13px;text-transform:uppercase">3 ใบ · อดีต ปัจจุบัน อนาคต</div>
            <div style="font-size:13px;color:var(--ink-dim);margin-top:4px">เร็วที่สุด เหมาะกับคำถามตรง ๆ ใช้เวลา 3 นาที</div>
            @if (isset($priceThree))
              <div style="font-family:var(--display);font-size:11px;color:var(--gold);letter-spacing:.18em;margin-top:6px">฿{{ number_format($priceThree, $priceThree == intval($priceThree) ? 0 : 2) }} / ครั้ง</div>
            @endif
          </div>
        </label>
        <label style="display:flex;gap:14px;padding:18px 20px;border:1px solid var(--line-soft);border-radius:14px;cursor:pointer;align-items:center" :style="spread==='celtic' ? 'border-color:var(--gold);background:rgba(244,207,106,.08)' : ''">
          <input type="radio" name="spread" value="celtic" x-model="spread" style="accent-color:var(--gold)">
          <div>
            <div style="font-family:var(--display);letter-spacing:.18em;color:var(--gold);font-size:13px;text-transform:uppercase">Celtic Cross · 10 ใบ</div>
            <div style="font-size:13px;color:var(--ink-dim);margin-top:4px">ลึก ครบ 360° วิเคราะห์ทั้งภายในและภายนอก</div>
            @if (isset($priceCeltic))
              <div style="font-family:var(--display);font-size:11px;color:var(--gold);letter-spacing:.18em;margin-top:6px">฿{{ number_format($priceCeltic, $priceCeltic == intval($priceCeltic) ? 0 : 2) }} / ครั้ง</div>
            @endif
          </div>
        </label>
      </div>

      <form action="{{ route('tarot.begin') }}" method="POST" style="margin-top:32px">
        @csrf
        {{-- the spread radios above also write to this hidden mirror so Alpine reactivity is irrelevant on submit --}}
        <input type="hidden" name="spread" :value="spread">
        <div class="field">
          <label for="question">คำถาม (ไม่ใส่ก็ได้)</label>
          <textarea id="question" name="question" rows="3" x-model="question" placeholder="เช่น ดวงเดือนนี้เป็นอย่างไร, ความสัมพันธ์กับคนรักจะดีขึ้นไหม"></textarea>
        </div>
        <button class="btn btn-primary" style="width:100%;justify-content:center">
          <span x-text="spread==='three' ? 'เลือกไพ่ 3 ใบ →' : 'เลือกไพ่ Celtic Cross 10 ใบ →'"></span>
        </button>
        <div style="margin-top:14px;font-size:12px;color:var(--ink-dim);text-align:center;letter-spacing:.04em">
          ระบบจะกางไพ่ทั้ง 78 ใบให้คุณเลือกด้วยตัวเองในขั้นตอนถัดไป ✨
        </div>
      </form>
    </div>

    <div style="display:grid;place-items:center">
      <div class="reading-stage" style="gap:18px">
        <div class="tarot" onclick="this.classList.toggle('flipped')">
          <div class="tarot-glow"></div>
          <div class="face back"><div class="back-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width=".7"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><path d="M12 2v20M2 12h20M5 5l14 14M19 5L5 19"/></svg>
          </div></div>
          <div class="face front">
            <img src="{{ asset('images/card-magician.png') }}" alt="">
            <div class="meaning">ลองหมุนเบาๆ ทำใจให้สงบก่อนเริ่มเปิดไพ่จริง</div>
            <div class="label">PREVIEW</div>
          </div>
        </div>
      </div>
    </div>

  </div>
</section>
@endsection
