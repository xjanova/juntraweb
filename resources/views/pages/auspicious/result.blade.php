@extends('layouts.app')
@section('title', 'ฤกษ์มงคลที่แม่หมอคัดให้')

@php
  /** หน้านี้อ่านจาก $reading->payload เสมอ — ทั้งตอนซื้อเสร็จและตอนย้อนดูจากประวัติ
      ผลที่เห็นจึงเหมือนกันทุกครั้งโดยไม่ต้องคำนวณดวงจันทร์ใหม่ */
  $p          = (array) $reading->payload;
  $candidates = (array) ($p['candidates'] ?? []);
  $top        = $candidates[0] ?? null;
  $rest       = array_slice($candidates, 1);
  $occasion   = $p['occasion'] ?? $reading->question;
@endphp

@section('content')
<section class="canvas" style="padding-top:130px">
  <div style="max-width:920px;margin:0 auto">

    <div style="text-align:center;margin-bottom:38px">
      <div class="eyebrow" style="display:inline-flex">ฤกษ์ยาม · AUSPICIOUS DATES</div>
      <h1 class="display" style="font-size:clamp(34px,4.6vw,60px);margin:14px 0 10px;line-height:1.2">
        {!! $p['occasion_icon'] ?? '✨' !!} ฤกษ์สำหรับ <em style="color:var(--gold)">{{ $occasion }}</em>
      </h1>
      <p class="lede" style="margin:0 auto;max-width:620px">
        คัดจากตำแหน่งจริงของดวงจันทร์ในช่วง
        {{ \Illuminate\Support\Carbon::parse($p['from_date'] ?? $reading->created_at)->format('d/m/Y') }}
        – {{ \Illuminate\Support\Carbon::parse($p['to_date'] ?? $reading->created_at)->format('d/m/Y') }}
        @if (! empty($p['occasion_label']))
          · หมวด{{ $p['occasion_label'] }}
        @endif
      </p>
      @if (! empty($replay))
        <div style="margin-top:14px;display:inline-flex;align-items:center;gap:8px;font-size:12px;color:var(--ink-faint);border:1px solid rgba(255,255,255,.12);border-radius:999px;padding:6px 14px">
          🕘 เปิดดูย้อนหลัง · ทำนายเมื่อ {{ $reading->created_at->format('d/m/Y H:i') }} น.
        </div>
      @endif
    </div>

    @if ($top)
      <div style="margin-bottom:16px">
        <x-ruek-day-card :day="$top" :rank="1" :featured="true" />
      </div>
    @endif

    @if ($rest)
      <div class="eyebrow" style="display:inline-flex;margin:26px 0 14px">วันสำรองที่ใช้ได้</div>
      <div class="auto-grid" style="--col-min:330px;gap:14px">
        @foreach ($rest as $i => $day)
          <x-ruek-day-card :day="$day" :rank="$i + 2" />
        @endforeach
      </div>
    @endif

    <div class="panel" style="margin-top:32px">
      <div class="eyebrow" style="display:inline-flex">คำแนะนำของแม่หมอ</div>
      <x-reading-prose :text="$reading->result" />
    </div>

    {{-- อธิบายวิธีคำนวณ — บริการนี้ขายความน่าเชื่อถือ ถ้าโชว์ตัวเลขโดยบอกที่มาไม่ได้
         ก็ไม่ต่างจากสุ่ม ส่วนนี้จึงเป็นเนื้อหาของสินค้า ไม่ใช่ของประดับ --}}
    <details class="panel" style="margin-top:16px" @if(empty($replay)) open @endif>
      <summary style="cursor:pointer;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--gold);list-style:none">
        ระบบคำนวณอย่างไร ▾
      </summary>
      <div style="margin-top:16px;color:var(--ink-dim);font-size:14px;line-height:1.95">
        <p style="margin-bottom:12px">
          คำว่า <strong style="color:var(--moon)">“ฤกษ์”</strong> ในตำราไทยแปลว่า <em>นักษัตร</em>
          คือกลุ่มดาวที่ดวงจันทร์โคจรผ่านบนจักรราศี แบ่ง 27 ช่อง ช่องละ 13°20′
          ระบบคำนวณลองจิจูดจริงของดวงจันทร์ในแต่ละวัน แล้วอ่านว่าตกนักษัตรใด
          จากนั้นแปลงเป็น <strong style="color:var(--moon)">ฤกษ์บน 9</strong> ตามลำดับในตำรา
          (นักษัตรที่ 1, 10, 19 เป็นทลิทโทฤกษ์ · ที่ 2, 11, 20 เป็นมหัทธโนฤกษ์ ไล่ไปจนครบ)
        </p>
        <p style="margin-bottom:12px">
          คะแนนของแต่ละวันมาจาก 3 ชั้นถ่วงน้ำหนักตามประเภทงาน —
          <strong style="color:var(--moon)">ฤกษ์บน</strong> (น้ำหนักมากที่สุด) ·
          <strong style="color:var(--moon)">วาร</strong> คือวันในสัปดาห์กับดาวเจ้าเรือน ·
          <strong style="color:var(--moon)">ดิถี</strong> คือข้างขึ้น-ข้างแรม
          เวลาที่แสดงคือช่วงที่ดวงจันทร์ยังอยู่ในนักษัตรนั้นจริง เมื่อพ้นช่วงแล้วฤกษ์จะเปลี่ยนทันที
        </p>
        @if (! empty($p['occasion_note']))
          <p style="padding:12px 14px;border-left:2px solid var(--gold);background:rgba(224,182,66,.06);border-radius:0 8px 8px 0">
            <strong style="color:var(--gold)">หลักที่ใช้กับงานหมวดนี้:</strong> {{ $p['occasion_note'] }}
          </p>
        @endif
        <p style="margin-top:12px;color:var(--ink-faint);font-size:12.5px">
          ระบบนี้คำนวณเฉพาะ “ฤกษ์บน” ซึ่งตรวจสอบย้อนกลับได้จากตำแหน่งดาราศาสตร์จริง
          ไม่ได้รวมวันธงชัย/อธิบดี/อุบาทว์/โลกาวินาศ ซึ่งผูกกับเดือนจันทรคติตามปฏิทินหลวงที่ประกาศเป็นปี ๆ ไป
        </p>
      </div>
    </details>

    <div style="text-align:center;margin-top:40px;display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
      <a href="{{ route('auspicious.index') }}" class="btn btn-ghost">หาฤกษ์ใหม่</a>
      <a href="{{ route('account.history') }}" class="btn btn-ghost">ประวัติของฉัน</a>
      <a href="{{ route('chat.index') }}" class="btn btn-primary">ถามแม่หมอต่อ
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>
</section>
@endsection
