@extends('layouts.app')
@section('title', 'ฤกษ์ยาม · วันมงคล')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:680px;margin:0 auto">
    <div style="text-align:center;margin-bottom:60px">
      <div class="eyebrow" style="display:inline-flex">ฤกษ์ยาม · AUSPICIOUS DATES</div>
      <h1 class="display" style="font-size:clamp(48px,6vw,84px);margin-bottom:24px">หาวัน <em>มงคล</em></h1>
      <p class="lede" style="margin:0 auto">ระบบคำนวณวันที่ดี ตามหลักเลขศาสตร์และคติไทย — สำหรับงานบุญ งานแต่ง เปิดร้าน เริ่มเรียน</p>
    </div>

    @if (isset($cost) && $cost > 0)
      <div style="text-align:center;margin-bottom:24px;font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase">
        ค่าบริการ ฿{{ number_format($cost, $cost == intval($cost) ? 0 : 2) }} / ครั้ง
      </div>
    @endif
    <form action="{{ route('auspicious.find') }}" method="POST" class="panel"
          x-data="{submitting:false}" @submit="submitting=true">
      @csrf
      <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::uuid() }}">
      <div class="field">
        <label for="occasion">โอกาส</label>
        <input type="text" id="occasion" name="occasion" value="{{ old('occasion') }}" placeholder="เช่น แต่งงาน, เปิดร้านอาหาร, ขึ้นบ้านใหม่, ออกรถ" required>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="field">
          <label for="from_date">เริ่มจากวันที่</label>
          <input type="date" id="from_date" name="from_date" value="{{ old('from_date', now()->toDateString()) }}">
        </div>
        <div class="field">
          <label for="to_date">ถึงวันที่</label>
          <input type="date" id="to_date" name="to_date" value="{{ old('to_date', now()->addDays(60)->toDateString()) }}">
        </div>
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center" :disabled="submitting">
        <span x-show="!submitting">ค้นหาวันมงคล</span>
        <span x-show="submitting">กำลังค้นหา ⋯</span>
        <svg x-show="!submitting" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </button>
    </form>

    @if (isset($upcoming) && count($upcoming))
      <div style="margin-top:48px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:24px">วันมงคลในตาราง (Admin)</div>
        @foreach ($upcoming as $a)
          <div class="panel" style="margin-bottom:14px;padding:24px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
              <div>
                <div style="font-family:var(--display);font-size:13px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase;margin-bottom:6px">{{ $a->type }}</div>
                <h3 style="font-family:var(--serif);font-size:22px;color:var(--moon);font-weight:500">{{ $a->title }}</h3>
                @if($a->description)<p style="margin-top:8px;color:var(--ink-dim);font-size:14px">{{ $a->description }}</p>@endif
              </div>
              <div style="font-family:var(--display);font-size:24px;color:var(--gold)">{{ $a->date->format('d/m/Y') }}</div>
            </div>
          </div>
        @endforeach
      </div>
    @endif
  </div>
</section>
@endsection
