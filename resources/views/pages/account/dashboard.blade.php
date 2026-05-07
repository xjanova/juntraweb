@extends('layouts.app')
@section('title', 'หน้าหลักของฉัน')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:1080px;margin:0 auto">
    <div class="eyebrow">บัญชีของฉัน</div>
    <h2 style="font-family:var(--serif);font-size:clamp(40px,5vw,68px);font-weight:400">สวัสดี <em style="color:var(--gold)">{{ $user->name }}</em></h2>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:48px">
      <a href="{{ route('tarot.index') }}" class="service" style="text-decoration:none">
        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M2 12 L7 7 L12 12 L17 7 L22 12 L17 17 L12 12 L7 17 Z"/></svg></div>
        <h3>เปิดไพ่</h3>
        <p>ลองเปิดไพ่ยิปซีอีกครั้ง วันนี้พลังของไพ่อาจเปลี่ยนไป</p>
      </a>
      <a href="{{ route('horoscope.index') }}" class="service" style="text-decoration:none">
        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="12" cy="12" r="10"/><path d="M12 7v5l3 3"/></svg></div>
        <h3>ดวงรายวัน</h3>
        <p>คำพยากรณ์ประจำวันของราศีคุณ — อัปเดตทุก 24 ชั่วโมง</p>
      </a>
      <a href="{{ route('chat.index') }}" class="service" style="text-decoration:none">
        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M21 12c0 4.97-4.03 9-9 9-1.5 0-2.91-.37-4.15-1.02L3 21l1.02-4.85A8.96 8.96 0 0 1 3 12a9 9 0 1 1 18 0z"/></svg></div>
        <h3>คุยกับ AI</h3>
        <p>ถามแม่หมอ AI เรื่องที่อยากรู้ ตอบทันทีตลอด 24 ชม.</p>
      </a>
    </div>

    <div style="margin-top:64px">
      <div class="eyebrow" style="display:inline-flex">การดูดวงล่าสุด</div>
      @if ($recent->count())
        <div style="display:grid;gap:12px;margin-top:24px">
          @foreach ($recent as $r)
            <div class="panel" style="padding:20px;display:flex;justify-content:space-between;align-items:center;gap:16px">
              <div>
                <div style="font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase;margin-bottom:6px">{{ __readingType($r->type) }}</div>
                <div style="color:var(--moon)">{{ $r->question ?? '— ไม่ได้ตั้งคำถาม —' }}</div>
              </div>
              <div style="text-align:right;flex-shrink:0">
                <div style="font-size:13px;color:var(--ink-faint)">{{ $r->created_at->diffForHumans() }}</div>
                @if (str_starts_with($r->type, 'tarot'))
                  <a href="{{ route('tarot.show', $r) }}" style="color:var(--gold);font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;margin-top:6px;display:inline-block">ดู →</a>
                @endif
              </div>
            </div>
          @endforeach
        </div>
        <div style="text-align:center;margin-top:24px">
          <a href="{{ route('account.history') }}" class="btn btn-ghost">ดูประวัติทั้งหมด</a>
        </div>
      @else
        <div class="panel" style="text-align:center;padding:48px 24px;margin-top:24px">
          <p style="color:var(--ink-dim)">ยังไม่มีประวัติการดูดวง — เลือกบริการด้านบนเพื่อเริ่มต้นได้เลย</p>
        </div>
      @endif
    </div>
  </div>
</section>

@php
function __readingType($t) {
  return [
    'tarot_three'  => 'ไพ่ 3 ใบ',
    'tarot_celtic' => 'Celtic Cross',
    'numerology'   => 'เลขศาสตร์',
    'palmistry'    => 'ลายมือ',
    'auspicious'   => 'ฤกษ์ยาม',
    'chat'         => 'AI Chat',
  ][$t] ?? $t;
}
@endphp
@endsection
