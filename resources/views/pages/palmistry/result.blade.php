@extends('layouts.app')
@section('title', 'ผลการดูลายมือ')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:880px;margin:0 auto">
    <div class="eyebrow">PALMISTRY ANALYSIS</div>
    <h2 style="font-family:var(--serif);font-size:clamp(40px,5vw,68px);font-weight:400">ลายมือ <em style="color:var(--gold)">บอกเล่าชะตา</em></h2>

    <div style="display:grid;grid-template-columns:1fr 1.5fr;gap:32px;margin-top:48px">
      <div>
        <img src="{{ $image_url }}" alt="ลายมือ" style="border-radius:18px;border:1px solid var(--line);box-shadow:0 30px 80px -10px rgba(0,0,0,.6),0 0 60px -10px rgba(176,124,255,.4);max-width:100%">
      </div>
      <div class="panel">
        <div class="eyebrow" style="display:inline-flex">บทวิเคราะห์</div>
        <x-reading-prose :text="$reading->result" />
      </div>
    </div>

    <div style="text-align:center;margin-top:48px">
      <a href="{{ route('palmistry.index') }}" class="btn btn-ghost">วิเคราะห์รูปอื่น</a>
    </div>
  </div>
</section>
@endsection
