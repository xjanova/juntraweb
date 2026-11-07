{{--
  🌙 (2026-11-07) เช็กอินรับดวงประจำวัน — บนหน้าภาพรวมบัญชี
  ยังไม่กด = ปุ่มรับดวง (ไม่เผยดวงก่อน ให้มีจังหวะ "เปิด") · กดแล้ว = สี/เลข/ทิศ/คำอวยพรของวันนี้ + วันต่อเนื่อง
  ดวงคำนวณจากลูกค้า×วันที่ล้วน ๆ (App\Support\DailyLuck) — ไม่เรียก AI ไม่มีรางวัลเงิน
--}}
@props(['checkin' => null, 'streak' => 0, 'luck' => []])

@php
  use App\Support\DailyLuck;
  $next   = DailyLuck::nextMilestone((int) $streak);
  $earned = DailyLuck::earnedMilestones((int) $streak);
  $fresh  = session('checkin_new');
  $art    = file_exists(public_path('images/juntra/art/daily-luck.webp')) ? asset('images/juntra/art/daily-luck.webp') : null;
@endphp

<div id="daily-luck" class="panel luck" @if ($art) style="--luck-art:url('{{ $art }}')" @endif>
  <div class="luck-top">
    <div>
      <div class="eyebrow" style="display:inline-flex">ดวงประจำวัน{{ $luck['day_name'] ?? '' }}</div>
      <div class="luck-streak">
        @if ($streak > 0)
          🔥 มาต่อเนื่อง <strong>{{ $streak }}</strong> วัน
        @else
          เริ่มนับวันต่อเนื่องได้ตั้งแต่วันนี้
        @endif
      </div>
    </div>
    @if ($earned)
      <div class="luck-badges">
        @foreach ($earned as $days => $label)
          <span class="chip" title="{{ $label }}">{{ $days }} วัน · {{ $label }}</span>
        @endforeach
      </div>
    @endif
  </div>

  @if ($checkin)
    @if ($fresh)
      <p class="luck-fresh" role="status">รับดวงวันนี้แล้ว{{ $fresh > 1 ? ' — วันที่ '.$fresh.' ติดกัน' : '' }} ✨</p>
    @endif
    <div class="luck-grid">
      <div class="luck-item">
        <span class="lbl">สีประจำวัน</span>
        <span class="val"><i style="background:{{ $luck['day_color']['hex'] }}"></i>{{ $luck['day_color']['name'] }}</span>
      </div>
      <div class="luck-item">
        <span class="lbl">สีเสริมดวง</span>
        <span class="val"><i style="background:{{ $luck['lucky_color']['hex'] }}"></i>{{ $luck['lucky_color']['name'] }}</span>
      </div>
      <div class="luck-item">
        <span class="lbl">เลขนำโชค</span>
        <span class="val luck-num">{{ $luck['number'] }}</span>
      </div>
      <div class="luck-item">
        <span class="lbl">ทิศมงคล</span>
        <span class="val">{{ $luck['direction'] }}</span>
      </div>
    </div>
    <p class="luck-bless">“{{ $luck['blessing'] }}”</p>
    <p class="luck-foot">
      @if ($next) อีก {{ $next['left'] }} วันได้ป้าย “{{ $next['label'] }}” · @endif
      ดวงเสี่ยงทายเพื่อความเพลิดเพลิน กลับมารับใหม่พรุ่งนี้นะคะ
    </p>
  @else
    <p class="luck-hint">แตะรับดวงของวันนี้ — สีเสริมดวง เลขนำโชค ทิศมงคล และคำอวยพรจากแม่หมอ</p>
    <form method="POST" action="{{ route('account.checkin') }}" x-data="{ sending:false }" @submit="sending=true">
      @csrf
      <button type="submit" class="btn btn-primary" :disabled="sending">
        <span x-show="!sending">🌙 รับดวงวันนี้</span><span x-show="sending">กำลังเปิดดวง ⋯</span>
      </button>
    </form>
    @if ($next && $streak > 0)
      <p class="luck-foot">มาต่ออีก {{ $next['left'] }} วันได้ป้าย “{{ $next['label'] }}”</p>
    @endif
  @endif
</div>

@once
@push('head')
<style>
  .luck { position: relative; overflow: hidden; padding: 24px 26px; margin-bottom: 28px; border-color: rgba(244,207,106,.35);
          background: linear-gradient(135deg, rgba(244,207,106,.08), rgba(106,59,214,.10)); }
  .luck::before { content: ""; position: absolute; inset: 0; background: var(--luck-art, none) right center / cover no-repeat; opacity: .28; pointer-events: none;
                  -webkit-mask-image: linear-gradient(90deg, transparent 25%, #000); mask-image: linear-gradient(90deg, transparent 25%, #000); }
  .luck > * { position: relative; }
  .luck-top { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; align-items: flex-start; }
  .luck-streak { font-size: 14px; color: var(--ink-dim); margin-top: 6px; }
  .luck-streak strong { color: var(--gold); font-size: 18px; }
  .luck-badges { display: flex; gap: 6px; flex-wrap: wrap; }
  .luck-badges .chip { font-size: 11.5px; }
  .luck-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 18px 0 12px; }
  .luck-item { background: rgba(7,4,26,.45); border: 1px solid var(--line-soft); border-radius: 14px; padding: 12px 14px; }
  .luck-item .lbl { display: block; font-size: 11.5px; color: var(--ink-faint); margin-bottom: 4px; }
  .luck-item .val { display: flex; gap: 8px; align-items: center; font-size: 15px; color: var(--ink); }
  .luck-item .val i { width: 14px; height: 14px; border-radius: 50%; box-shadow: 0 0 0 2px rgba(255,255,255,.15); flex-shrink: 0; }
  .luck-num { font-family: var(--serif); font-size: 24px !important; color: var(--gold) !important; }
  .luck-bless { font-family: var(--serif); font-size: 17px; color: var(--moon, var(--ink)); margin: 6px 0 8px; line-height: 1.6; }
  .luck-hint { color: var(--ink-dim); font-size: 14px; margin: 12px 0 16px; max-width: 52ch; }
  .luck-fresh { color: var(--gold); font-size: 14px; margin: 12px 0 0; }
  .luck-foot { font-size: 12px; color: var(--ink-faint); margin: 10px 0 0; }
  @media (max-width: 700px) { .luck-grid { grid-template-columns: repeat(2, 1fr); } .luck { padding: 20px 18px; } }
</style>
@endpush
@endonce
