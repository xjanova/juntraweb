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
        แม่หมออ่านให้แล้วค่ะ — อ่านย้อนกลับมาได้ตลอดจากเมนู "ประวัติการดูดวง"
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

    <div class="panel" style="padding:28px">
      <div class="eyebrow" style="margin-bottom:16px">คำทำนายของแม่หมอ</div>
      <x-reading-prose :text="$reading->result" />
    </div>

    <div style="text-align:center;margin-top:40px;display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
      <a href="{{ route('deep.index') }}" class="btn btn-ghost">ถามเรื่องอื่นอีก</a>
      <a href="{{ route('chat.index') }}" class="btn btn-primary">ถามแม่หมอต่อ
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>
</section>
@endsection
