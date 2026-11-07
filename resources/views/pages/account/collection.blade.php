@extends('layouts.app')
@section('title', 'สมุดสะสมไพ่')

{{--
  🃏 (2026-11-07) สมุดสะสมไพ่ 78 ใบ — ใบที่เคยเปิดได้จากคำทำนายของตัวเองจะหงายหน้า
  ใบที่ยังไม่เคยเจอ = หลังไพ่ · ไม่มีรางวัลเงิน เป็นของสะสมให้อยากกลับมาเปิดไพ่
--}}
@php
  $groupNames = ['major' => 'ไพ่ชุดใหญ่ (Major Arcana)', 'wands' => 'ชุดไม้เท้า', 'cups' => 'ชุดถ้วย', 'swords' => 'ชุดดาบ', 'pentacles' => 'ชุดเหรียญ', 'other' => 'ไพ่อื่น ๆ'];
  $total = $cards->count();
  $pct = $total ? (int) floor($seenCount * 100 / $total) : 0;
  // หลังไพ่ที่แอดมินอัปโหลด (ไม่มี = วาดหลังไพ่ด้วย CSS)
  $back = (new \App\Models\TarotCard)->cardBackUrl();
  $favCard = $favorite ? $cards->firstWhere('id', $favorite->tarot_card_id) : null;
@endphp

@section('content')
<section class="canvas" style="padding-top:120px">
  <div class="account-shell">
    @include('partials.account-sidebar', ['active' => 'collection'])

    <div class="account-content">
      <x-page-hero art="{{ file_exists(public_path('images/juntra/art/collection.webp')) ? 'images/juntra/art/collection.webp' : 'images/juntra/art/tarot.webp' }}" />

      <div class="eyebrow">สมุดสะสมไพ่</div>
      <h2 style="font-family:var(--serif);font-size:clamp(28px,3.6vw,46px);font-weight:400;margin:8px 0 10px">
        เจอแล้ว <em style="color:var(--gold)">{{ $seenCount }}</em> จาก {{ $total }} ใบ
      </h2>
      <div class="coll-bar" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><span style="width:{{ $pct }}%"></span></div>
      <p style="color:var(--ink-dim);font-size:14px;margin:10px 0 28px">
        @if ($seenCount === 0)
          ยังไม่มีไพ่ในสมุดเลย — เปิดไพ่ครั้งแรกแล้วไพ่ที่ได้จะหงายหน้าอยู่ตรงนี้
        @elseif ($seenCount >= $total)
          ครบทั้งสำรับแล้ว ไพ่ทุกใบเคยมาหาคุณอย่างน้อยหนึ่งครั้ง ✨
        @else
          อีก {{ $total - $seenCount }} ใบที่ยังไม่เคยมาหาคุณ
          @if ($favCard) · ใบที่มาหาบ่อยที่สุดคือ <strong style="color:var(--gold)">{{ $favCard->name_th }}</strong> ({{ $favorite->times }} ครั้ง) @endif
        @endif
      </p>

      @foreach ($groups as $key => $list)
        @php $have = $list->filter(fn ($c) => $seen->has($c->id))->count(); @endphp
        <div class="coll-group">
          <div class="coll-head">
            <h3>{{ $groupNames[$key] ?? $key }}</h3>
            <span>{{ $have }}/{{ $list->count() }}</span>
          </div>
          <div class="coll-grid">
            @foreach ($list as $c)
              @php $s = $seen->get($c->id); @endphp
              <figure class="coll-card {{ $s ? 'is-seen' : '' }}" title="{{ $s ? $c->name_th : 'ยังไม่เคยเปิดได้' }}">
                @if ($s || $back)
                  <img src="{{ $s ? $c->imageUrl() : $back }}" alt="{{ $s ? $c->name_th : 'หลังไพ่' }}" loading="lazy" decoding="async" width="300" height="520">
                @else
                  <div class="coll-back" aria-hidden="true">☾</div>
                @endif
                <figcaption>
                  @if ($s)
                    <span class="nm">{{ $c->name_th }}</span>
                    <span class="ct">{{ $s->times }} ครั้ง</span>
                  @else
                    <span class="nm">?</span>
                  @endif
                </figcaption>
              </figure>
            @endforeach
          </div>
        </div>
      @endforeach

      <div style="text-align:center;margin-top:36px">
        <a href="{{ route('tarot.index') }}" class="btn btn-primary">เปิดไพ่เพิ่มในสมุด</a>
      </div>
    </div>
  </div>
</section>

@push('head')
<style>
  .coll-bar { height: 8px; border-radius: 99px; background: rgba(244,207,106,.12); overflow: hidden; max-width: 420px; }
  .coll-bar span { display: block; height: 100%; background: linear-gradient(90deg, var(--gold), #fff1c2); border-radius: inherit; }
  .coll-group { margin-top: 30px; }
  .coll-head { display: flex; justify-content: space-between; align-items: baseline; border-bottom: 1px solid var(--line-soft); padding-bottom: 8px; margin-bottom: 14px; }
  .coll-head h3 { font-family: var(--serif); font-weight: 400; font-size: 20px; margin: 0; }
  .coll-head span { color: var(--gold); font-size: 13px; }
  .coll-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 12px; }
  .coll-card { margin: 0; text-align: center; }
  .coll-card img { width: 100%; height: auto; aspect-ratio: 300/520; object-fit: cover; border-radius: 8px; border: 1px solid var(--line-soft); filter: grayscale(.7) brightness(.55); transition: transform .25s, filter .25s; }
  .coll-back { aspect-ratio: 300/520; border-radius: 8px; border: 1px solid var(--line-soft); display: grid; place-items: center; color: rgba(244,207,106,.35); font-size: 26px;
               background: radial-gradient(circle at 50% 40%, rgba(106,59,214,.35), rgba(7,4,26,.9) 70%); }
  .coll-card.is-seen img { filter: none; border-color: rgba(244,207,106,.45); box-shadow: 0 6px 18px rgba(0,0,0,.35); }
  .coll-card.is-seen:hover img { transform: translateY(-4px); }
  .coll-card figcaption { margin-top: 6px; font-size: 11.5px; line-height: 1.35; color: var(--ink-faint); }
  .coll-card.is-seen figcaption .nm { color: var(--ink); display: block; }
  .coll-card figcaption .ct { color: var(--gold); font-size: 11px; }
  @media (max-width: 520px) { .coll-grid { grid-template-columns: repeat(4, 1fr); gap: 8px; } .coll-card figcaption { font-size: 10.5px; } }
</style>
@endpush
@endsection
