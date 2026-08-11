@extends('layouts.app')
@section('title', 'ประวัติการดูดวง')

@php
  /** ทะเบียนชนิดรายการ — ต้องครบทุกบริการที่เขียนลง readings
      ถ้าลืมเพิ่มชนิดใหม่ตรงนี้ รายการจะยังโชว์ได้ (ใช้ค่า default) แต่จะไม่มีไอคอน */
  $meta = collect(\App\Support\TarotSpreads::all())
    ->mapWithKeys(fn ($m, $k) => ['tarot_' . $k => ['label' => $m['name_th'], 'icon' => '🃏', 'color' => '#b98cc4']])
    ->merge([
      'numerology' => ['label' => 'เลขศาสตร์',        'icon' => '🔢', 'color' => '#4a8bb0'],
      'palmistry'  => ['label' => 'ลายมือ',           'icon' => '🖐', 'color' => '#c77fb0'],
      'auspicious' => ['label' => 'ฤกษ์ยาม',          'icon' => '📅', 'color' => '#e0b642'],
      'deep'       => ['label' => 'ดูดวงเชิงลึก',      'icon' => '🔮', 'color' => '#d4a017'],
      'chat'       => ['label' => 'AI Chat',          'icon' => '💬', 'color' => '#5f9e6e'],
    ])->all();

  $filters = ['' => 'ทั้งหมด', 'tarot' => 'ไพ่ยิปซี', 'auspicious' => 'ฤกษ์ยาม',
              'numerology' => 'เลขศาสตร์', 'palmistry' => 'ลายมือ', 'deep' => 'เชิงลึก'];
  $active  = request('type', '');
@endphp

@section('content')
<section class="canvas" style="padding-top:120px">
  <div class="account-shell">
    @include('partials.account-sidebar', ['active' => 'history'])

    <div class="account-content">
    <x-page-hero art="images/juntra/art/account.webp" />

    <div class="eyebrow">ประวัติของฉัน</div>
    <h2 style="font-family:var(--serif);font-size:clamp(32px,4vw,52px);font-weight:400;margin:8px 0 12px"><em style="color:var(--gold)">บันทึก</em> ดวงชะตา</h2>
    <p class="lede" style="margin:0 0 22px;color:var(--ink-dim);max-width:640px">
      ทุกครั้งที่เปิดไพ่ ดูฤกษ์ อ่านลายมือ หรือคำนวณเลขศาสตร์ — ระบบเก็บไว้ให้ กดเข้าไปอ่านซ้ำได้ตลอด ไม่เสียเครดิตเพิ่ม
    </p>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:22px">
      @foreach ($filters as $key => $label)
        <a href="{{ $key === '' ? route('account.history') : route('account.history', ['type' => $key]) }}"
           class="btn {{ $active === $key ? 'btn-primary' : 'btn-ghost' }}"
           style="padding:8px 18px;font-size:12px">{{ $label }}</a>
      @endforeach
    </div>

    <div style="display:grid;gap:14px">
      @forelse ($readings as $r)
        @php
          $m = $meta[$r->type] ?? ['label' => $r->type, 'icon' => '✦', 'color' => 'var(--gold)'];
          $cost = $r->payload['cost'] ?? null;
        @endphp
        <a href="{{ route('reading.show', $r) }}" class="panel"
           style="padding:22px;display:block;text-decoration:none;border-color:{{ $m['color'] }}2e;position:relative;overflow:hidden">
          <div style="position:absolute;left:0;top:0;bottom:0;width:3px;background:{{ $m['color'] }}"></div>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
            <div style="flex:1;min-width:240px">
              <div style="display:flex;align-items:center;gap:9px;margin-bottom:7px;flex-wrap:wrap">
                <span style="font-size:16px">{{ $m['icon'] }}</span>
                <span style="font-family:var(--display);font-size:11px;letter-spacing:.18em;color:{{ $m['color'] }};text-transform:uppercase">{{ $m['label'] }}</span>
                @if ($cost)
                  <span style="font-size:10px;color:var(--ink-faint);border:1px solid rgba(255,255,255,.12);border-radius:999px;padding:2px 8px">฿{{ (int) $cost }}</span>
                @endif
              </div>
              <div style="color:var(--moon);margin-bottom:7px;font-size:15px">{{ $r->question ?: '— ไม่ได้ตั้งคำถาม —' }}</div>
              <div style="color:var(--ink-dim);font-size:12.5px">
                {{ $r->created_at->format('d/m/Y H:i') }} น. · {{ $r->created_at->diffForHumans() }}
              </div>
            </div>
            <span class="btn btn-ghost" style="padding:10px 22px;flex-shrink:0;pointer-events:none">ดูผล →</span>
          </div>
        </a>
      @empty
        <div class="panel" style="text-align:center;padding:48px 24px">
          <p style="color:var(--ink-dim);margin-bottom:18px">
            {{ $active ? 'ยังไม่มีประวัติในหมวดนี้' : 'ยังไม่มีประวัติการดูดวง' }}
          </p>
          <a href="{{ route('tarot.index') }}" class="btn btn-primary">เริ่มเปิดไพ่ →</a>
        </div>
      @endforelse
    </div>

    <div style="margin-top:32px">{{ $readings->links() }}</div>
    </div>
  </div>
</section>
@endsection
