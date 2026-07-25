@extends('layouts.app')
@section('title', 'ผลการคำนวณเลขศาสตร์')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:880px;margin:0 auto">
    <div class="eyebrow">เลขศาสตร์ · NUMEROLOGY RESULT</div>
    <h2 style="font-family:var(--serif);font-size:clamp(40px,5vw,68px);font-weight:400">ดวงเลขของ <em style="color:var(--gold)">{{ $reading->question }}</em></h2>

    <div class="auto-grid" style="--col-min:200px;gap:20px;margin-top:48px">
      <div class="panel" style="text-align:center">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:8px">LIFE PATH</div>
        <div style="font-family:var(--display);font-size:96px;color:var(--gold);font-weight:600;line-height:1">{{ $result['life_path'] }}</div>
        <div style="font-family:var(--serif);font-size:20px;margin-top:12px;color:var(--moon)">{{ $result['life_path_meaning']['name'] }}</div>
      </div>
      <div class="panel" style="text-align:center">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:8px">EXPRESSION</div>
        <div style="font-family:var(--display);font-size:96px;color:var(--orchid);font-weight:600;line-height:1">{{ $result['expression'] }}</div>
        <div style="font-family:var(--serif);font-size:20px;margin-top:12px;color:var(--moon)">{{ $result['expression_meaning']['name'] }}</div>
      </div>
      <div class="panel" style="text-align:center">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:8px">BIRTH DAY</div>
        <div style="font-family:var(--display);font-size:96px;color:var(--rose);font-weight:600;line-height:1">{{ $result['birth_day_reduced'] }}</div>
        <div style="font-family:var(--serif);font-size:20px;margin-top:12px;color:var(--moon)">{{ $result['birth_day_meaning']['name'] }}</div>
      </div>
    </div>

    <div class="panel" style="margin-top:32px">
      <div class="eyebrow" style="display:inline-flex">บทวิเคราะห์</div>
      <x-reading-prose :text="$result['narrative']" />
    </div>

    <div style="text-align:center;margin-top:48px;display:flex;gap:16px;justify-content:center;flex-wrap:wrap">
      <a href="{{ route('numerology.index') }}" class="btn btn-ghost">คำนวณอีกชื่อ</a>
      <a href="{{ route('chat.index') }}" class="btn btn-primary">ถามเพิ่มเติมกับ AI</a>
    </div>
  </div>
</section>
@endsection
