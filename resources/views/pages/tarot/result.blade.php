@extends('layouts.app')
@section('title', 'ผลการเปิดไพ่')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div class="reading-result">
    <div class="eyebrow">{{ $reading->type === 'tarot_celtic' ? 'CELTIC CROSS · 10 ใบ' : 'THREE-CARD SPREAD · 3 ใบ' }}</div>
    <h2>คำพยากรณ์ <em>จากไพ่ของคุณ</em></h2>
    @if ($reading->question)
      <p class="lede" style="margin-top:16px">"{{ $reading->question }}"</p>
    @endif

    <div class="reading-card-row" style="grid-template-columns: repeat({{ $reading->type === 'tarot_celtic' ? 5 : 3 }}, 1fr)">
      @foreach ($reading->tarotCards as $rc)
        <div class="position">
          <div class="pos-label">{{ $rc->position_label }}</div>
          <div class="card-img" style="{{ $rc->reversed ? 'transform:rotate(180deg)' : '' }}">
            <img src="{{ $rc->card->imageUrl() }}" alt="{{ $rc->card->name_th }}">
          </div>
          <div class="card-name">{{ $rc->card->name_th }} {{ $rc->reversed ? '(กลับหัว)' : '' }}</div>
          <div class="card-meaning">{{ $rc->reversed ? $rc->card->reversed_meaning_th : $rc->card->upright_meaning_th }}</div>
        </div>
      @endforeach
    </div>

    <div class="panel" style="margin-top:48px">
      <div class="eyebrow" style="display:inline-flex">บทวิเคราะห์รวม</div>
      <div class="summary">{!! nl2br(e($reading->result)) !!}</div>
    </div>

    <div style="text-align:center;margin-top:48px;display:flex;gap:16px;justify-content:center;flex-wrap:wrap">
      <a href="{{ route('tarot.index') }}" class="btn btn-ghost">เปิดอีกครั้ง</a>
      <a href="{{ route('chat.index') }}" class="btn btn-primary">ถามเพิ่มเติมกับ AI
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>
</section>
@endsection
