@extends('layouts.app')
@section('title', 'คำทำนายเชิงลึกจากแม่หมอจันทรา')

@php
  $questions = (array) ($reading->payload['questions'] ?? []);
  $birth     = $reading->payload['birth_date'] ?? null;
@endphp

@section('content')
<section class="canvas">
  <div class="shell-read" style="max-width:760px;margin:0 auto">

    <div style="text-align:center;margin-bottom:30px">
      <div class="eyebrow">DEEP READING</div>
      <h1 class="display" style="font-size:clamp(30px,4vw,48px);margin-bottom:10px">
        คำทำนาย<em>เชิงลึก</em>
      </h1>
      <p class="lede" style="margin:0 auto">
        @if ($reading->isInProgress())
          แม่หมอรับคำถามของลูกแล้ว กำลังดูดวงให้อยู่ค่ะ
        @elseif ($reading->isFailed())
          รายการนี้ไม่ได้คิดเงินค่ะ
        @else
          แม่หมออ่านให้แล้วค่ะ — อ่านย้อนกลับมาได้ตลอดจากเมนู "ประวัติการดูดวง"
        @endif
      </p>
    </div>

    @if ($questions)
      <div class="panel" style="padding:22px 24px;margin-bottom:20px">
        <div class="eyebrow" style="margin-bottom:14px">สิ่งที่คุณถาม</div>
        <ol style="margin:0;padding-left:20px;line-height:1.9;color:var(--ink)">
          @foreach ($questions as $q)
            <li>{{ $q }}</li>
          @endforeach
        </ol>
        @if ($birth)
          <div style="margin-top:14px;font-size:12.5px;color:var(--ink-dim)">
            ทำนายตามวันเกิด {{ \Illuminate\Support\Carbon::parse($birth)->translatedFormat('j F Y') }}
          </div>
        @endif
      </div>
    @endif

    @if ($reading->isInProgress())
      {{-- 🔮 (2026-11-07) แม่หมอดูดวงเบื้องหลังทีละข้อ — ถามสถานะทุก 3 วิ เสร็จ/ไม่สำเร็จ = โหลดหน้าใหม่ --}}
      <div class="panel deep-wait" style="padding:28px;text-align:center" role="status" aria-live="polite"
           data-status-url="{{ route('deep.status', $reading) }}">
        <div class="reading-wait-moon" aria-hidden="true"></div>
        <div class="eyebrow" style="display:inline-flex;margin-top:18px">แม่หมอกำลังดูดวงให้ลูก</div>
        <p style="font-family:var(--thai);color:var(--ink-dim);line-height:1.8;max-width:46ch;margin:10px auto 0">
          แม่หมอกำลังเปิดผังดวงและตอบทีละคำถาม ({{ count($questions) }} ข้อ) ใช้เวลาประมาณ 1-3 นาที
          <span class="deep-wait-elapsed" style="color:var(--gold)"></span><br>
          ปิดหน้านี้ได้ คำทำนายจะรออยู่ในประวัติการดูดวงของลูก
        </p>
      </div>
      <script>
        (function () {
          var box = document.querySelector('.deep-wait');
          if (!box) return;
          var url = box.getAttribute('data-status-url'), started = Date.now(), el = box.querySelector('.deep-wait-elapsed');
          var timer = setInterval(function () {
            var s = Math.round((Date.now() - started) / 1000);
            if (el) el.textContent = '· ผ่านไป ' + s + ' วินาที';
            if (s > 600) {                       // เกิน 10 นาที — ตัวกวาดรายการค้างคืนเงินให้เอง
              clearInterval(timer);
              if (el) el.textContent = '· นานกว่าปกติ กดรีเฟรชหน้าอีกครั้งได้ค่ะ';
              return;
            }
            if (s % 3 !== 0) return;
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
              .then(function (r) { return r.ok ? r.json() : null; })
              .then(function (j) { if (j && j.status !== 'reading') { clearInterval(timer); location.reload(); } })
              .catch(function () { /* เน็ตสะดุด — รอบหน้าลองใหม่ */ });
          }, 1000);
        })();
      </script>
    @elseif ($reading->isFailed())
      @php
        $failure = (array) data_get($reading->payload, 'failure', []);
        $cost = (float) data_get($reading->payload, 'cost', 0);
      @endphp
      <div class="panel" style="padding:28px;text-align:center">
        @if (! empty($failure['crisis']) && ! empty($failure['message']))
          {{-- คำถามส่อสัญญาณวิกฤต — แสดงคำปลอบจาก Thaiprompt แทนข้อความ "ไม่สำเร็จ" --}}
          <div class="eyebrow" style="display:inline-flex">แม่หมออยู่ตรงนี้นะคะ</div>
          <div style="font-family:var(--thai);color:var(--ink);line-height:1.9;max-width:52ch;margin:12px auto 0;text-align:left">
            <x-reading-prose :text="$failure['message']" />
          </div>
          @if ($cost > 0)
            <p style="font-size:12.5px;color:var(--ink-dim);margin-top:14px">เครดิต ฿{{ number_format($cost, 0) }} คืนเข้ากระเป๋าของลูกแล้วค่ะ</p>
          @endif
          <a href="{{ route('chat.index') }}" class="btn btn-primary" style="margin-top:18px">คุยกับแม่หมอ</a>
        @else
          <div class="eyebrow" style="display:inline-flex">ดูดวงไม่สำเร็จ</div>
          <p style="font-family:var(--thai);color:var(--ink-dim);line-height:1.8;max-width:48ch;margin:12px auto 20px">
            ขออภัยค่ะ แม่หมอดูดวงชุดนี้ไม่สำเร็จ
            @if ($cost > 0)
              — เครดิต ฿{{ number_format($cost, 0) }} คืนเข้ากระเป๋าของลูกเรียบร้อยแล้ว
            @endif
            ลองถามใหม่อีกครั้งได้เลยนะคะ
          </p>
          <a href="{{ route('deep.index') }}" class="btn btn-primary">ถามใหม่อีกครั้ง</a>
        @endif
      </div>
    @else
      <div class="panel" style="padding:28px">
        <div class="eyebrow" style="margin-bottom:16px">คำทำนายของแม่หมอ</div>
        <x-reading-prose :text="$reading->result" />
      </div>
    @endif

    <div style="text-align:center;margin-top:40px;display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
      <a href="{{ route('deep.index') }}" class="btn btn-ghost">ถามเรื่องอื่นอีก</a>
      <a href="{{ route('chat.index') }}" class="btn btn-primary">ถามแม่หมอต่อ
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>
</section>
@endsection
