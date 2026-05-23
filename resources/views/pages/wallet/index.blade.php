@extends('layouts.app')
@section('title', 'วอลเลตของฉัน')

@section('content')
@php
  $f = $filters ?? ['type'=>null,'status'=>null,'from'=>null,'to'=>null];
  $hasFilter = !empty($f['type']) || !empty($f['status']) || !empty($f['from']) || !empty($f['to']);
@endphp

<section class="canvas" style="padding-top:120px">
  <div class="account-shell">
    @include('partials.account-sidebar', ['active' => 'wallet'])

    <div class="account-content">

    <div class="eyebrow">วอลเลตของฉัน</div>
    <h1 class="display" style="font-size:clamp(32px,4vw,56px);margin:8px 0 12px">
      <em style="color:var(--gold)">เครดิต</em> สำหรับดูดวง
    </h1>
    <p class="lede" style="margin:0 0 28px">เติมเงินเข้าวอลเลตหนึ่งครั้ง ใช้ดูดวงและคุยกับแม่หมอได้ตามใจ</p>

    @if (session('status'))
      <div class="flash" style="padding:14px 18px;margin-bottom:24px">{{ session('status') }}</div>
    @endif

    <div style="display:grid;grid-template-columns:1.1fr .9fr;gap:24px;margin-bottom:36px" class="wallet-summary-grid">
      <div class="panel" style="padding:28px;display:flex;flex-direction:column;gap:14px;background:linear-gradient(135deg,rgba(244,207,106,.08),rgba(176,122,255,.05))">
        <div class="eyebrow" style="display:inline-flex">ยอดคงเหลือ</div>
        <div style="font-family:var(--display);font-weight:300;font-size:52px;line-height:1;color:var(--gold);letter-spacing:.02em">
          ฿{{ number_format($wallet->balance, 2) }}
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <a href="{{ route('wallet.topup') }}" class="btn btn-primary">
            เติมเงิน
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
          </a>
          <a href="{{ route('wallet.topups') }}" class="btn btn-ghost">สถานะการเติมเงิน</a>
        </div>
      </div>

      <div class="panel" style="padding:22px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:12px">ค่าบริการ</div>
        <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px;font-size:13px">
          @foreach ($pricing as $key => $val)
            <li style="display:flex;justify-content:space-between;padding:8px 12px;border:1px solid var(--line-soft);border-radius:8px">
              <span style="color:var(--moon)">{{ $pricingMap[$key] ?? $key }}</span>
              <strong style="color:var(--gold);font-family:var(--display);letter-spacing:.04em">฿{{ number_format($val, $val == intval($val) ? 0 : 2) }}</strong>
            </li>
          @endforeach
        </ul>
      </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('wallet.index') }}" class="panel" style="padding:16px 20px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
      <div class="field" style="margin:0;flex:1;min-width:140px">
        <label style="font-size:11px">ประเภท</label>
        <select name="type">
          <option value="">ทั้งหมด</option>
          <option value="topup"      @selected($f['type']==='topup')>เติมเงิน</option>
          <option value="debit"      @selected($f['type']==='debit')>หักค่าบริการ</option>
          <option value="refund"     @selected($f['type']==='refund')>คืนเครดิต</option>
          <option value="adjustment" @selected($f['type']==='adjustment')>ปรับยอด</option>
        </select>
      </div>
      <div class="field" style="margin:0;flex:1;min-width:140px">
        <label style="font-size:11px">สถานะ</label>
        <select name="status">
          <option value="">ทั้งหมด</option>
          <option value="success"  @selected($f['status']==='success')>สำเร็จ</option>
          <option value="pending"  @selected($f['status']==='pending')>รอตรวจสอบ</option>
          <option value="failed"   @selected($f['status']==='failed')>ปฏิเสธ</option>
          <option value="refunded" @selected($f['status']==='refunded')>คืนเงินแล้ว</option>
        </select>
      </div>
      <div class="field" style="margin:0;flex:1;min-width:140px">
        <label style="font-size:11px">จากวันที่</label>
        <input type="date" name="from" value="{{ $f['from'] }}">
      </div>
      <div class="field" style="margin:0;flex:1;min-width:140px">
        <label style="font-size:11px">ถึงวันที่</label>
        <input type="date" name="to" value="{{ $f['to'] }}">
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="btn btn-primary" style="padding:10px 18px">กรอง</button>
        @if ($hasFilter)
          <a href="{{ route('wallet.index') }}" class="btn btn-ghost" style="padding:10px 18px">ล้าง</a>
        @endif
      </div>
    </form>

    <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">
      รายการ {{ $hasFilter ? '(กรองแล้ว)' : 'ทั้งหมด' }} · {{ $transactions->total() }} รายการ
    </div>
    <div class="panel" style="padding:0;overflow:hidden">
      @if ($transactions->count() === 0)
        <div style="padding:48px 24px;text-align:center;color:var(--ink-dim)">
          @if ($hasFilter)
            ไม่พบรายการตามเงื่อนไข — <a href="{{ route('wallet.index') }}" style="color:var(--gold)">ล้างตัวกรอง</a>
          @else
            ยังไม่มีรายการ — กดปุ่ม "เติมเงิน" ด้านบนเพื่อเริ่มต้น
          @endif
        </div>
      @else
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;min-width:640px">
          <thead>
            <tr style="background:rgba(0,0,0,.02);border-bottom:1px solid var(--line-soft)">
              <th style="padding:14px;text-align:left;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500">วันที่</th>
              <th style="padding:14px;text-align:left;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500">รายการ</th>
              <th style="padding:14px;text-align:right;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500">จำนวน</th>
              <th style="padding:14px;text-align:right;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500">คงเหลือ</th>
              <th style="padding:14px;text-align:center;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500">สถานะ</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($transactions as $t)
              <tr style="border-bottom:1px solid var(--line-soft)">
                <td style="padding:14px;font-size:13px;color:var(--ink-dim);white-space:nowrap">
                  {{ $t->created_at->format('d/m/Y H:i') }}
                </td>
                <td style="padding:14px;font-size:14px">
                  <div>{{ $t->description ?: $t->typeLabel() }}</div>
                  @if ($t->reference_code)
                    <div style="font-size:11px;color:var(--ink-faint);font-family:var(--display);letter-spacing:.06em;margin-top:2px">REF: {{ $t->reference_code }}</div>
                  @endif
                </td>
                <td style="padding:14px;text-align:right;font-family:var(--display);white-space:nowrap;color:{{ $t->isPositive() ? 'var(--gold)' : 'var(--ink)' }}">
                  {{ $t->isPositive() ? '+' : '' }}฿{{ number_format(abs($t->amount), 2) }}
                </td>
                <td style="padding:14px;text-align:right;font-family:var(--display);color:var(--ink-dim);white-space:nowrap">
                  @if ($t->balance_after !== null)
                    ฿{{ number_format($t->balance_after, 2) }}
                  @else
                    —
                  @endif
                </td>
                <td style="padding:14px;text-align:center;white-space:nowrap">
                  @php
                    $color = match($t->status) {
                        'success'  => 'var(--gold)',
                        'pending'  => '#d4a017',
                        'failed'   => '#c2382e',
                        'refunded' => '#888',
                        default    => 'var(--ink-dim)',
                    };
                  @endphp
                  <span style="display:inline-block;padding:3px 10px;border-radius:999px;font-family:var(--display);font-size:10px;letter-spacing:.18em;text-transform:uppercase;border:1px solid {{ $color }};color:{{ $color }}">
                    {{ $t->statusLabel() }}
                  </span>
                  @if ($t->status === 'pending' && $t->type === 'topup')
                    <div style="margin-top:4px"><a href="{{ route('wallet.topup.show', $t) }}" style="font-size:11px;color:var(--gold)">ดูสลิป →</a></div>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
      @endif
    </div>

    <div style="margin-top:24px">{{ $transactions->links() }}</div>
    </div>
  </div>
</section>

@push('head')
<style>
  @media (max-width: 720px) {
    .wallet-summary-grid { grid-template-columns: 1fr !important }
  }
</style>
@endpush
@endsection
