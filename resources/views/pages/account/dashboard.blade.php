@extends('layouts.app')
@section('title', 'หน้าหลักของฉัน')

@section('content')
@php
  $typeLabels = collect(\App\Support\TarotSpreads::all())
    ->mapWithKeys(fn ($m, $k) => ['tarot_' . $k => $m['name_th']])
    ->merge([
      'numerology' => 'เลขศาสตร์',
      'palmistry'  => 'ลายมือ',
      'auspicious' => 'ฤกษ์ยาม',
      'chat'       => 'AI Chat',
    ])->all();
  $hasAstro = $profile && ($profile->birth_date || $profile->zodiac_slug);
@endphp

<section class="canvas" style="padding-top:120px">
  <div class="account-shell">
    @include('partials.account-sidebar', ['active' => 'dashboard'])

    <div class="account-content">
      <x-page-hero art="images/juntra/art/account.webp" />

      <div class="eyebrow">บัญชีของฉัน</div>
      <h2 style="font-family:var(--serif);font-size:clamp(32px,4vw,52px);font-weight:400;margin:8px 0 24px">
        สวัสดี <em style="color:var(--gold)">{{ $user->name }}</em>
      </h2>

      {{-- Astrology setup nudge — encourages new users to fill DOB once --}}
      @if (!$hasAstro)
        <div class="panel" style="padding:18px 22px;margin-bottom:24px;border-color:var(--gold);background:linear-gradient(135deg,rgba(244,207,106,.08),rgba(176,122,255,.04));display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
          <div>
            <div style="font-weight:600;margin-bottom:4px">ยังไม่ได้ตั้งข้อมูลโหราศาสตร์</div>
            <div style="font-size:13px;color:var(--ink-dim)">กรอกวันเกิดครั้งเดียว ใช้กับเลขศาสตร์ ฤกษ์ยาม ดวงรายวันได้เลยทันที</div>
          </div>
          <a href="{{ route('account.astrology') }}" class="btn btn-primary">ตั้งค่าตอนนี้ →</a>
        </div>
      @endif

      {{-- Quick action tiles --}}
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:36px" class="dashboard-tiles">
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

      <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:24px" class="dashboard-history-grid">
        <div>
          <div class="eyebrow" style="display:inline-flex">การดูดวงล่าสุด</div>
          @if ($recent->count())
            <div style="display:grid;gap:12px;margin-top:16px">
              @foreach ($recent as $r)
                <div class="panel" style="padding:18px;display:flex;justify-content:space-between;align-items:center;gap:16px">
                  <div>
                    <div style="font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase;margin-bottom:6px">{{ $typeLabels[$r->type] ?? $r->type }}</div>
                    <div style="color:var(--moon);font-size:14px">{{ $r->question ?? '— ไม่ได้ตั้งคำถาม —' }}</div>
                  </div>
                  <div style="text-align:right;flex-shrink:0">
                    <div style="font-size:12px;color:var(--ink-faint)">{{ $r->created_at->diffForHumans() }}</div>
                    {{-- ทุกบริการเปิดดูย้อนหลังได้ ไม่ใช่เฉพาะไพ่ (ดู ReadingController) --}}
                    <a href="{{ route('reading.show', $r) }}" style="color:var(--gold);font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;margin-top:6px;display:inline-block">ดู →</a>
                  </div>
                </div>
              @endforeach
            </div>
            <div style="margin-top:16px">
              <a href="{{ route('account.history') }}" class="btn btn-ghost">ประวัติทั้งหมด →</a>
            </div>
          @else
            <div class="panel" style="text-align:center;padding:32px 24px;margin-top:16px">
              <p style="color:var(--ink-dim);margin:0">ยังไม่มีประวัติการดูดวง</p>
            </div>
          @endif
        </div>

        <div>
          <div class="eyebrow" style="display:inline-flex">การสนทนาล่าสุด</div>
          @if ($chats->count())
            <div style="display:grid;gap:12px;margin-top:16px">
              @foreach ($chats as $c)
                <a href="{{ route('chat.show', $c) }}" class="panel" style="padding:18px;text-decoration:none;color:inherit;display:block">
                  <div style="font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase;margin-bottom:6px">{{ $c->title }} · {{ $c->messages_count }} ข้อความ</div>
                  <div style="color:var(--ink-dim);font-size:12px">{{ $c->updated_at->diffForHumans() }}</div>
                </a>
              @endforeach
            </div>
            <div style="margin-top:16px">
              <a href="{{ route('account.chats') }}" class="btn btn-ghost">บทสนทนาทั้งหมด →</a>
            </div>
          @else
            <div class="panel" style="text-align:center;padding:32px 24px;margin-top:16px">
              <p style="color:var(--ink-dim);margin:0;margin-bottom:14px">ยังไม่มีบทสนทนา</p>
              <a href="{{ route('chat.index') }}" class="btn btn-ghost">ทักแม่หมอ →</a>
            </div>
          @endif
        </div>
      </div>
    </div>
  </div>
</section>

@push('head')
<style>
  @media (max-width: 880px) {
    .dashboard-tiles { grid-template-columns: 1fr !important }
    .dashboard-history-grid { grid-template-columns: 1fr !important }
  }
</style>
@endpush
@endsection
