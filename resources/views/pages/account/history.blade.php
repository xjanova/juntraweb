@extends('layouts.app')
@section('title', 'ประวัติการดูดวง')

@section('content')
@php
  $typeLabels = [
    'tarot_three'  => 'ไพ่ 3 ใบ',
    'tarot_celtic' => 'Celtic Cross',
    'numerology'   => 'เลขศาสตร์',
    'palmistry'    => 'ลายมือ',
    'auspicious'   => 'ฤกษ์ยาม',
    'chat'         => 'AI Chat',
  ];
@endphp

<section class="canvas" style="padding-top:160px">
  <div style="max-width:880px;margin:0 auto">
    <div class="eyebrow">ประวัติของฉัน</div>
    <h2 style="font-family:var(--serif);font-size:clamp(40px,5vw,68px);font-weight:400"><em style="color:var(--gold)">บันทึก</em> ดวงชะตา</h2>
    <p class="lede" style="margin:14px 0 0;color:var(--ink-dim)">เก็บไว้ทุกครั้งที่เปิดไพ่หรือดูดวง — ย้อนกลับไปอ่านได้ตลอด</p>

    <div style="display:flex;gap:12px;margin-top:24px;flex-wrap:wrap">
      <a href="{{ route('account.chats') }}" class="btn btn-ghost">ดูประวัติการสนทนา →</a>
      <a href="{{ route('wallet.index') }}" class="btn btn-ghost">วอลเลต</a>
    </div>

    <div style="display:grid;gap:14px;margin-top:32px">
      @forelse ($readings as $r)
        <div class="panel" style="padding:24px">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
            <div style="flex:1;min-width:260px">
              <div style="font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase;margin-bottom:6px">
                {{ $typeLabels[$r->type] ?? $r->type }}
                @if ($r->ai_provider)
                  <span style="color:var(--ink-faint);font-size:10px;margin-left:8px">· via {{ $r->ai_provider }}</span>
                @endif
              </div>
              <div style="color:var(--moon);margin-bottom:8px">{{ $r->question ?? '— ไม่ได้ตั้งคำถาม —' }}</div>
              <div style="color:var(--ink-dim);font-size:13px">{{ $r->created_at->format('d/m/Y H:i') }} · {{ $r->created_at->diffForHumans() }}</div>
            </div>
            <div style="flex-shrink:0;align-self:center">
              @if (str_starts_with($r->type, 'tarot'))
                <a href="{{ route('tarot.show', $r) }}" class="btn btn-ghost" style="padding:10px 22px">ดูผล →</a>
              @endif
            </div>
          </div>
        </div>
      @empty
        <div class="panel" style="text-align:center;padding:48px 24px">
          <p style="color:var(--ink-dim);margin-bottom:18px">ยังไม่มีประวัติการดูดวง</p>
          <a href="{{ route('tarot.index') }}" class="btn btn-primary">เริ่มเปิดไพ่ →</a>
        </div>
      @endforelse
    </div>

    <div style="margin-top:32px">{{ $readings->links() }}</div>
  </div>
</section>
@endsection
