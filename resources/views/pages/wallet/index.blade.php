@extends('layouts.app')
@section('title', 'วอลเลตของฉัน')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:1080px;margin:0 auto">

    <div class="eyebrow">วอลเลตของฉัน</div>
    <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">
      <em style="color:var(--gold)">เครดิต</em> สำหรับดูดวง
    </h1>
    <p class="lede" style="margin:0 0 36px">เติมเงินเข้าวอลเลตหนึ่งครั้ง ใช้ดูดวงและคุยกับแม่หมอได้ตามใจ</p>

    @if (session('status'))
      <div class="flash" style="padding:14px 18px;margin-bottom:24px">{{ session('status') }}</div>
    @endif

    <div style="display:grid;grid-template-columns:1.1fr .9fr;gap:24px;margin-bottom:48px" class="wallet-summary-grid">
      <div class="panel" style="padding:32px;display:flex;flex-direction:column;gap:18px;background:linear-gradient(135deg,rgba(244,207,106,.08),rgba(176,122,255,.05))">
        <div class="eyebrow" style="display:inline-flex">ยอดคงเหลือ</div>
        <div style="font-family:var(--display);font-weight:300;font-size:64px;line-height:1;color:var(--gold);letter-spacing:.02em">
          ฿{{ number_format($wallet->balance, 2) }}
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <a href="{{ route('wallet.topup') }}" class="btn btn-primary">
            เติมเงิน
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
          </a>
          <a href="{{ route('account.history') }}" class="btn btn-ghost">ประวัติดูดวง</a>
        </div>
      </div>

      <div class="panel" style="padding:24px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">ค่าบริการ</div>
        <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px;font-size:14px">
          @foreach ($pricing as $key => $val)
            <li style="display:flex;justify-content:space-between;padding:10px 14px;border:1px solid var(--line-soft);border-radius:8px">
              <span style="color:var(--moon)">{{ $pricingMap[$key] ?? $key }}</span>
              <strong style="color:var(--gold);font-family:var(--display);letter-spacing:.04em">฿{{ number_format($val, $val == intval($val) ? 0 : 2) }}</strong>
            </li>
          @endforeach
        </ul>
      </div>
    </div>

    <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">รายการล่าสุด</div>
    <div class="panel" style="padding:0;overflow:hidden">
      @if ($transactions->count() === 0)
        <div style="padding:48px 24px;text-align:center;color:var(--ink-dim)">
          ยังไม่มีรายการ — กดปุ่ม "เติมเงิน" ด้านบนเพื่อเริ่มต้น
        </div>
      @else
        <table style="width:100%;border-collapse:collapse">
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
      @endif
    </div>

    <div style="margin-top:24px">{{ $transactions->links() }}</div>
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
